<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\PaymentStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Models\Charge;
use App\Models\ChargeOffer;
use App\Models\OrderBump;
use App\Models\Product;
use App\Models\Upsell;
use App\Models\Affiliate;
use App\Models\Transaction;
use App\Models\CustomDomain;
use App\Models\SavedCard;
use App\Services\MemberEnrollmentService;
use App\Services\PaymentService;
use App\Services\LiveMapService;
use App\Services\UtmifyService;
use App\Services\FacebookConversionsApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PublicCheckoutController extends Controller
{
    private PaymentService $paymentService;
    private MemberEnrollmentService $memberEnrollmentService;
    private LiveMapService $liveMapService;

    public function __construct(
        PaymentService $paymentService,
        MemberEnrollmentService $memberEnrollmentService,
        LiveMapService $liveMapService
    )
    {
        $this->paymentService = $paymentService;
        $this->memberEnrollmentService = $memberEnrollmentService;
        $this->liveMapService = $liveMapService;
    }

    private function resolveFacebookPixel(Checkout $checkout): ?array
    {
        $metadata = $checkout->product?->metadata;
        $productPixel = is_array($metadata) && is_array($metadata['facebook_pixel'] ?? null)
            ? $metadata['facebook_pixel']
            : null;

        if ($productPixel
            && filter_var($productPixel['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && !empty($productPixel['pixel_id'])
        ) {
            return [
                'pixel_id' => (string) $productPixel['pixel_id'],
                'track_page_view' => (bool) ($productPixel['track_page_view'] ?? true),
                'track_initiate_checkout' => (bool) ($productPixel['track_initiate_checkout'] ?? true),
                'track_add_payment_info' => (bool) ($productPixel['track_add_payment_info'] ?? true),
                'track_purchase' => (bool) ($productPixel['track_purchase'] ?? true),
            ];
        }

        try {
            return app(FacebookConversionsApiService::class)->getPixelDataForAccount($checkout->account_id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get checkout by custom domain (resolves account from domain)
     * 
     * URL: https://pay.seudominio.com/{slug?}
     * Se slug não informado, retorna o checkout padrão da conta
     */
    public function showByDomain(Request $request, ?string $slug = null): JsonResponse
    {
        try {
        // Obtém o domínio personalizado do middleware
        $customDomain = $request->attributes->get('custom_domain');
        
        if (!$customDomain) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'domain_not_configured',
                    'message' => 'Domínio não configurado',
                ],
            ], 404);
        }

        // Busca checkout pelo slug ou pega o padrão da conta
        if ($slug) {
            $checkout = Checkout::where('slug', $slug)
                ->where('account_id', $customDomain->account_id)
                ->where('status', 'active')
                ->first();
        } else {
            // Busca o checkout padrão (primeiro ativo da conta)
            $checkout = Checkout::where('account_id', $customDomain->account_id)
                ->where('status', 'active')
                ->orderBy('created_at', 'asc')
                ->first();
        }

        if (!$checkout) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'checkout_not_found',
                    'message' => 'Checkout não encontrado',
                ],
            ], 404);
        }

        // Increment views
        try {
            $checkout->incrementViews();
            $this->liveMapService->recordCheckoutAccess($checkout, $request, 'checkout', 'Checkout aberto');
        } catch (\Throwable $e) {
            Log::warning('Failed to increment checkout views (domain)', ['error' => $e->getMessage()]);
        }

        // Load product
        $checkout->load('product');

        $facebookPixel = $this->resolveFacebookPixel($checkout);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $checkout->id,
                'name' => $checkout->name,
                'slug' => $checkout->slug,
                'type' => $checkout->type,
                'theme' => $checkout->theme,
                'settings' => $checkout->settings,
                'fields' => $checkout->fields,
                'product' => $checkout->product ? [
                    'id' => $checkout->product->id,
                    'name' => $checkout->product->name,
                    'description' => $checkout->product->description,
                    'price' => $checkout->product->price,
                    'originalPrice' => $checkout->product->original_price,
                    'original_price' => $checkout->product->original_price,
                    'currency' => $checkout->product->currency ?? 'BRL',
                    'image_url' => $this->sanitizeImageUrl($checkout->product->image_url ?? ($checkout->product->images[0] ?? null), $checkout->product->name),
                    'type' => $checkout->product->type,
                ] : null,
                'custom_domain' => $customDomain->domain,
                'facebook_pixel' => $facebookPixel,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicCheckoutController::showByDomain error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get checkout by slug (public)
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        try {
        $checkout = Checkout::where('slug', $slug)
            ->where('status', 'active')
            ->first();

        if (!$checkout) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'checkout_not_found',
                    'message' => 'Checkout não encontrado ou inativo',
                ],
            ], 404);
        }

        // Increment views
        try {
            $checkout->incrementViews();
            $this->liveMapService->recordCheckoutAccess($checkout, $request, 'checkout', 'Checkout aberto');
        } catch (\Throwable $e) {
            Log::warning('Failed to increment checkout views', ['slug' => $slug, 'error' => $e->getMessage()]);
        }

        // Load product
        $checkout->load('product');

        // Load active order bumps (table may not exist if migrations pending)
        try {
            $orderBumps = OrderBump::where('checkout_id', $checkout->id)
                ->whereRaw('"is_active" = true')
                ->with('product:id,name,price,image_url,images')
                ->orderBy('sort_order')
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Failed to load order bumps', ['slug' => $slug, 'error' => $e->getMessage()]);
            $orderBumps = collect();
        }

        // Check if checkout has upsells configured
        try {
            $hasUpsells = Upsell::where('checkout_id', $checkout->id)
                ->whereRaw('"is_active" = true')
                ->exists();
        } catch (\Throwable $e) {
            $hasUpsells = false;
        }

        $facebookPixel = $this->resolveFacebookPixel($checkout);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $checkout->id,
                'name' => $checkout->name,
                'slug' => $checkout->slug,
                'type' => $checkout->type,
                'theme' => $checkout->theme,
                'settings' => $checkout->settings,
                'fields' => $checkout->fields,
                'product' => $checkout->product ? [
                    'id' => $checkout->product->id,
                    'name' => $checkout->product->name,
                    'description' => $checkout->product->description,
                    'price' => $checkout->product->price,
                    'originalPrice' => $checkout->product->original_price,
                    'original_price' => $checkout->product->original_price,
                    'currency' => $checkout->product->currency ?? 'BRL',
                    'image_url' => $this->sanitizeImageUrl($checkout->product->image_url ?? ($checkout->product->images[0] ?? null), $checkout->product->name),
                    'type' => $checkout->product->type,
                ] : null,
                'order_bumps' => $orderBumps->map(fn ($b) => [
                    'id' => $b->id,
                    'title' => $b->title,
                    'description' => $b->description,
                    'call_to_action' => $b->call_to_action,
                    'image_url' => $b->image_url ?? ($b->product?->image_url ?? ($b->product?->images[0] ?? null)),
                    'price' => $b->price,
                    'original_price' => $b->original_price,
                    'discount_percent' => $b->discount_percent,
                    'display_position' => $b->display_position,
                    'is_pre_selected' => $b->is_pre_selected,
                    'product' => $b->product ? [
                        'id' => $b->product->id,
                        'name' => $b->product->name,
                    ] : null,
                ]),
                'has_upsells' => $hasUpsells,
                'facebook_pixel' => $facebookPixel,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicCheckoutController::show error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Process checkout payment
     */
    public function process(Request $request, string $slug): JsonResponse
    {
        try {
        $checkout = Checkout::where('slug', $slug)
            ->where('status', 'active')
            ->with('product')
            ->first();

        if (!$checkout) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'checkout_not_found',
                    'message' => 'Checkout não encontrado ou inativo',
                ],
            ], 404);
        }

        try {
            $this->liveMapService->recordCheckoutAccess($checkout, $request, 'api', 'API do checkout acionada');
        } catch (\Throwable $e) {
            Log::debug('Failed to record checkout API access', ['slug' => $slug, 'error' => $e->getMessage()]);
        }

        // Accept customer data either nested or flat
        $customerData = $request->input('customer');
        if (!$customerData) {
            $customerData = [
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'document' => $request->input('document'),
                'phone' => $request->input('phone'),
            ];
        }

        // Validate customer data
        $validator = \Illuminate\Support\Facades\Validator::make([
            'payment_method' => $request->input('payment_method'),
            'customer' => $customerData,
            'installments' => $request->input('installments', $request->input('card.installments', 1)),
        ], [
            'payment_method' => ['required', 'in:credit_card,pix,boleto'],
            'customer.name' => ['required', 'string'],
            'customer.email' => ['required', 'email'],
            'customer.document' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!$this->isValidCpfOrCnpj((string) $value)) {
                        $fail('CPF/CNPJ inválido.');
                    }
                },
            ],
            'customer.phone' => ['nullable', 'string'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Dados inválidos',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        // Use checkout's product if no products array provided
        $totalAmount = 0;
        $productsData = [];
        
        if ($request->input('products')) {
            $productIds = collect($request->input('products'))->pluck('id')->toArray();
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            foreach ($request->input('products') as $item) {
                $product = $products->get($item['id']);
                if (!$product) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'product_not_found',
                            'message' => 'Produto não encontrado: ' . $item['id'],
                        ],
                    ], 400);
                }
                $quantity = $item['quantity'] ?? 1;
                $totalAmount += $product->price * $quantity;
                $productsData[] = ['id' => $product->id, 'quantity' => $quantity];
            }
        } elseif ($checkout->product) {
            // Use the checkout's linked product
            $totalAmount = $checkout->product->price;
            $productsData[] = ['id' => $checkout->product->id, 'quantity' => 1];
        } else {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'no_product',
                    'message' => 'Checkout não possui produto associado',
                ],
            ], 400);
        }

        // Check affiliate
        $affiliateId = null;
        if ($request->input('affiliate_code')) {
            $affiliate = Affiliate::where('code', $request->input('affiliate_code'))
                ->where('status', 'active')
                ->first();
            if ($affiliate) {
                $affiliateId = $affiliate->id;
            }
        }

        // Validate and calculate order bumps
        $acceptedBumps = [];
        $orderBumpTotal = 0;
        if ($request->input('order_bumps') && is_array($request->input('order_bumps'))) {
            try {
                $bumpIds = $request->input('order_bumps');
                $bumps = OrderBump::whereIn('id', $bumpIds)
                    ->where('checkout_id', $checkout->id)
                    ->whereRaw('"is_active" = true')
                    ->get();

                foreach ($bumps as $bump) {
                    $orderBumpTotal += $bump->price;
                    $acceptedBumps[] = $bump;
                    $bump->incrementViews();
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to load order bumps during process', ['slug' => $slug, 'error' => $e->getMessage()]);
            }
        }

        $totalAmount += $orderBumpTotal;

        // Inject customer IP for UTMify tracking (required field)
        if (is_array($customerData) && empty($customerData['ip'])) {
            $customerData['ip'] = $this->getClientIp($request);
        }
        if (is_array($customerData)) {
            $customerData['document'] = $this->normalizeBrazilianDocument((string) ($customerData['document'] ?? ''));
        }

        // Capture UTM tracking parameters for UTMify attribution
        $trackingParams = [
            'src'          => $request->input('src') ?? $request->query('src'),
            'sck'          => $request->input('sck') ?? $request->query('sck'),
            'utm_source'   => $request->input('utm_source') ?? $request->query('utm_source'),
            'utm_campaign' => $request->input('utm_campaign') ?? $request->query('utm_campaign'),
            'utm_medium'   => $request->input('utm_medium') ?? $request->query('utm_medium'),
            'utm_content'  => $request->input('utm_content') ?? $request->query('utm_content'),
            'utm_term'     => $request->input('utm_term') ?? $request->query('utm_term'),
            // Facebook click/browser IDs for Conversions API matching
            'fbclid'       => $request->input('fbclid') ?? $request->query('fbclid'),
            'fbp'          => $request->input('fbp') ?? $request->query('fbp'),
            'fbc'          => $request->input('fbc') ?? $request->query('fbc'),
        ];

        // Create charge
        $chargeId = Str::uuid()->toString();
        try {
            $charge = Charge::create([
                'id' => $chargeId,
                'account_id' => $checkout->account_id,
                'checkout_id' => $checkout->id,
                'product_id' => $checkout->product_id,
                'affiliate_id' => $affiliateId,
                'amount' => $totalAmount,
                'currency' => 'BRL',
                'status' => 'pending',
                'payment_method' => $request->input('payment_method'),
                'installments' => $request->input('installments', $request->input('card.installments', 1)),
                'customer' => $customerData,
                'billing' => $request->input('billing'),
                'metadata' => [
                    'checkout_slug' => $slug,
                    'products' => $productsData,
                    'order_bumps' => collect($acceptedBumps)->map(fn ($b) => ['id' => $b->id, 'price' => $b->price, 'product_id' => $b->product_id])->values()->all(),
                    'order_bump_total' => $orderBumpTotal,
                    'ip' => $this->getClientIp($request),
                    'customer_ip' => $this->getClientIp($request),
                    'user_agent' => $request->userAgent(),
                    'tracking' => $trackingParams,
                ],
                'description' => $checkout->product ? $checkout->product->name : 'Compra via checkout',
                'expires_at' => now()->addHours(24),
                'idempotency_key' => 'chk_' . $slug . '_' . Str::random(16),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create charge', ['slug' => $slug, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'charge_creation_failed',
                    'message' => 'Erro ao criar cobrança. Tente novamente.',
                ],
            ], 500);
        }

        // Record order bump offers
        try {
            foreach ($acceptedBumps as $bump) {
                ChargeOffer::create([
                    'id' => Str::uuid()->toString(),
                    'charge_id' => $charge->id,
                    'account_id' => $checkout->account_id,
                    'offer_type' => 'order_bump',
                    'offer_id' => $bump->id,
                    'product_id' => $bump->product_id,
                    'amount' => $bump->price,
                    'status' => 'accepted',
                ]);
                $bump->incrementConversions($bump->price);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to record charge offers', ['charge_id' => $charge->id, 'error' => $e->getMessage()]);
        }

        // Process payment via acquirer (critical path — must be fast)
        try {
            $paymentResult = $this->paymentService->processPayment($charge, $request->input('card', []));
        } catch (\Throwable $e) {
            $failureMessage = 'Erro interno ao processar pagamento';
            Log::error('Payment processing exception', ['charge_id' => $charge->id, 'error' => $e->getMessage()]);
            $this->persistChargeFailure($charge, $failureMessage);
            $paymentResult = ['success' => false, 'error' => $failureMessage];
        }
        
        if (isset($paymentResult['success']) && !$paymentResult['success']) {
            $this->logChargeFailureDetails($charge, $paymentResult, 'Public checkout payment failed with provider details');
            $this->persistChargeFailure($charge, (string) ($paymentResult['error'] ?? 'Falha ao processar pagamento'), $paymentResult);
            $this->dispatchPaymentStatusChangedEvent($charge->fresh() ?? $charge);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'payment_failed',
                    'message' => $paymentResult['error'] ?? 'Falha ao processar pagamento',
                ],
            ], 400);
        }

        $this->dispatchPaymentStatusChangedEvent($charge->fresh() ?? $charge);

        // Check if there are upsells to show after payment
        try {
            $hasUpsells = Upsell::where('checkout_id', $checkout->id)
                ->whereRaw('"is_active" = true')
                ->exists();
        } catch (\Throwable $e) {
            $hasUpsells = false;
        }

        // Defer non-critical work to after the response is sent
        $chargeForDeferred = $charge;
        $checkoutForDeferred = $checkout;
        $totalAmountForDeferred = $totalAmount;
        $paymentMethodInput = $request->input('payment_method');
        $memberEnrollmentService = $this->memberEnrollmentService;

        dispatch(function () use ($chargeForDeferred, $checkoutForDeferred, $totalAmountForDeferred, $paymentMethodInput, $memberEnrollmentService) {
            try {
                app(UtmifyService::class)->dispatchSync($chargeForDeferred);
            } catch (\Exception $e) {
                Log::warning('Failed to dispatch UTMify on checkout charge', ['charge_id' => $chargeForDeferred->id, 'error' => $e->getMessage()]);
            }

            try {
                app(FacebookConversionsApiService::class)->dispatchSync($chargeForDeferred, 'InitiateCheckout');
            } catch (\Exception $e) {
                Log::warning('Failed to dispatch Facebook CAPI on checkout charge', ['charge_id' => $chargeForDeferred->id, 'error' => $e->getMessage()]);
            }

            try {
                if ($paymentMethodInput === 'credit_card' && in_array($chargeForDeferred->fresh()->status, ['approved', 'paid'])) {
                    $checkoutForDeferred->incrementConversions($totalAmountForDeferred);
                    $memberEnrollmentService->createAutomaticEnrollment($chargeForDeferred->fresh());
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to update checkout analytics', ['charge_id' => $chargeForDeferred->id, 'error' => $e->getMessage()]);
            }
        })->afterResponse();

        return response()->json([
            'success' => true,
            'data' => [
                'charge_id' => $charge->id,
                'status' => $charge->status,
                'amount' => $charge->amount,
                'payment_method' => $charge->payment_method,
                'acquirer' => $paymentResult['acquirer'] ?? 'babylon',
                'transaction_id' => $paymentResult['transaction_id'] ?? null,
                'pix_qr_code' => $paymentResult['pix_qr_code'] ?? $charge->pix_qr_code,
                'pix_copy_paste' => $paymentResult['pix_copy_paste'] ?? $charge->pix_copy_paste,
                'boleto_url' => $paymentResult['boleto_url'] ?? $charge->boleto_url,
                'boleto_barcode' => $paymentResult['boleto_barcode'] ?? $charge->boleto_barcode,
                'boleto_digitable_line' => $paymentResult['boleto_digitable_line'] ?? null,
                'payment_url' => $charge->payment_url,
                'expires_at' => $paymentResult['expires_at'] ?? $charge->expires_at,
                'has_upsells' => $hasUpsells,
                'order_bumps_accepted' => count($acceptedBumps),
                'order_bump_total' => $orderBumpTotal,
            ],
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicCheckoutController::process error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Check payment status (public endpoint for PIX/Boleto verification)
     */
    public function checkStatus(string $chargeId): JsonResponse
    {
        try {
        $charge = Charge::find($chargeId);

        if (!$charge) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'charge_not_found',
                    'message' => 'Cobrança não encontrada',
                ],
            ], 404);
        }

        // Try to sync with acquirer for real-time status
        $synced = false;
        if ($charge->status === 'pending' && $charge->acquirer_transaction_id) {
            try {
                $syncResult = $this->paymentService->syncChargeStatus($charge);
                $synced = $syncResult['synced'] ?? false;
                $charge->refresh();
            } catch (\Throwable $e) {
                Log::warning('Failed to sync charge status', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
            }
        }

        // Map internal status to frontend-friendly status
        $statusMap = [
            'paid' => 'approved',
            'pending' => 'pending',
            'expired' => 'expired',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'rejected',
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'charge_id'      => $charge->id,
                'status'         => $statusMap[$charge->status] ?? $charge->status,
                'payment_method' => $charge->payment_method,
                'synced'         => $synced,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicCheckoutController::checkStatus error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get the next upsell to display after a purchase
     */
    public function getUpsell(string $chargeId): JsonResponse
    {
        try {
        $charge = Charge::find($chargeId);
        if (!$charge || !$charge->checkout_id) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'charge_not_found', 'message' => 'Cobrança não encontrada'],
            ], 404);
        }

        // Get already shown/processed upsell IDs for this charge
        try {
            $processedUpsellIds = ChargeOffer::where('charge_id', $chargeId)
                ->whereIn('offer_type', ['upsell', 'downsell'])
                ->pluck('offer_id')
                ->toArray();
        } catch (\Throwable $e) {
            $processedUpsellIds = [];
        }

        // Find the next upsell in sequence that hasn't been shown yet
        try {
            $upsell = Upsell::where('checkout_id', $charge->checkout_id)
                ->whereRaw('"is_active" = true')
                ->whereNotIn('id', $processedUpsellIds)
                ->with('product:id,name,price,description,image_url,images')
                ->orderBy('sort_order')
                ->first();
        } catch (\Throwable $e) {
            $upsell = null;
        }

        if (!$upsell) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Nenhum upsell disponível',
            ]);
        }

        $upsell->incrementViews();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $upsell->id,
                'title' => $upsell->title,
                'description' => $upsell->description,
                'headline' => $upsell->headline,
                'call_to_action' => $upsell->call_to_action,
                'decline_text' => $upsell->decline_text,
                'image_url' => $upsell->image_url ?? ($upsell->product?->image_url ?? ($upsell->product?->images[0] ?? null)),
                'video_url' => $upsell->video_url,
                'price' => $upsell->price,
                'original_price' => $upsell->original_price,
                'discount_percent' => $upsell->discount_percent,
                'type' => $upsell->type,
                'one_click_purchase' => $upsell->one_click_purchase,
                'timer_seconds' => $upsell->timer_seconds,
                'benefits' => $upsell->benefits,
                'product' => $upsell->product ? [
                    'id' => $upsell->product->id,
                    'name' => $upsell->product->name,
                    'description' => $upsell->product->description,
                    'image_url' => $upsell->product->image_url ?? ($upsell->product->images[0] ?? null),
                ] : null,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicCheckoutController::getUpsell error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Accept an upsell offer — creates a new charge for the upsell product
     */
    public function acceptUpsell(Request $request, string $chargeId, string $upsellId): JsonResponse
    {
        try {
        $charge = Charge::find($chargeId);
        if (!$charge) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'charge_not_found', 'message' => 'Cobrança não encontrada'],
            ], 404);
        }

        try {
            $upsell = Upsell::where('id', $upsellId)
                ->where('checkout_id', $charge->checkout_id)
                ->whereRaw('"is_active" = true')
                ->first();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'upsell_error', 'message' => 'Erro ao buscar upsell'],
            ], 500);
        }

        if (!$upsell) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'upsell_not_found', 'message' => 'Upsell não encontrado'],
            ], 404);
        }

        // Prevent duplicate acceptance
        try {
            $existing = ChargeOffer::where('charge_id', $chargeId)
                ->where('offer_id', $upsellId)
                ->where('offer_type', $upsell->type)
                ->first();
        } catch (\Throwable $e) {
            $existing = null;
        }

        if ($existing && $existing->status === 'accepted') {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'already_accepted', 'message' => 'Upsell já foi aceito'],
            ], 409);
        }

        // Create a new charge for the upsell
        $upsellChargeId = Str::uuid()->toString();
        try {
            $upsellCharge = Charge::create([
                'id' => $upsellChargeId,
                'account_id' => $charge->account_id,
                'checkout_id' => $charge->checkout_id,
                'product_id' => $upsell->product_id,
                'affiliate_id' => $charge->affiliate_id,
                'amount' => $upsell->price,
                'currency' => 'BRL',
                'status' => 'pending',
                'payment_method' => $charge->payment_method,
                'installments' => 1,
                'customer' => $charge->customer,
                'billing' => $charge->billing,
                'metadata' => [
                    'parent_charge_id' => $chargeId,
                    'upsell_id' => $upsellId,
                    'type' => 'upsell_purchase',
                ],
                'description' => 'Upsell: ' . ($upsell->product->name ?? $upsell->title),
                'expires_at' => now()->addHours(24),
                'idempotency_key' => 'ups_' . $upsellId . '_' . Str::random(16),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create upsell charge', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'charge_creation_failed', 'message' => 'Erro ao criar cobrança do upsell'],
            ], 500);
        }

        // Process upsell payment (one-click if credit card)
        $cardData = [];
        if ($upsell->one_click_purchase && $charge->payment_method === 'credit_card' && $charge->acquirer_transaction_id) {
            // For one-click, reuse parent payment info
            $cardData = ['reuse_from_charge' => $chargeId];
        }

        try {
            $paymentResult = $this->paymentService->processPayment($upsellCharge, $cardData);
        } catch (\Throwable $e) {
            $failureMessage = 'Erro ao processar pagamento do upsell';
            Log::error('Upsell payment processing failed', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
            $this->persistChargeFailure($upsellCharge, $failureMessage);
            $paymentResult = ['success' => false, 'error' => $failureMessage];
        }

        if (isset($paymentResult['success']) && !$paymentResult['success']) {
            $this->logChargeFailureDetails($upsellCharge, $paymentResult, 'Upsell payment failed with provider details');
            $this->persistChargeFailure($upsellCharge, (string) ($paymentResult['error'] ?? 'Falha ao processar pagamento do upsell'), $paymentResult);
        }

        // Record the upsell offer
        try {
            ChargeOffer::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $chargeId,
                'account_id' => $charge->account_id,
                'offer_type' => $upsell->type,
                'offer_id' => $upsellId,
                'product_id' => $upsell->product_id,
                'amount' => $upsell->price,
                'status' => 'accepted',
                'upsell_charge_id' => $upsellChargeId,
            ]);

            $upsell->incrementConversions($upsell->price);
        } catch (\Throwable $e) {
            Log::warning('Failed to record accepted upsell offer', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
        }

        // Check for next upsell or downsell
        $hasNext = false;
        try {
            $nextUpsellId = $upsell->next_upsell_id;
            $hasNext = $nextUpsellId ? Upsell::where('id', $nextUpsellId)->whereRaw('"is_active" = true')->exists() : false;

            if (!$hasNext) {
                // Check if there are more upsells in sequence
                $processedIds = ChargeOffer::where('charge_id', $chargeId)
                    ->whereIn('offer_type', ['upsell', 'downsell'])
                    ->pluck('offer_id')
                    ->toArray();
                $hasNext = Upsell::where('checkout_id', $charge->checkout_id)
                    ->whereRaw('"is_active" = true')
                    ->whereNotIn('id', $processedIds)
                    ->exists();
            }
        } catch (\Throwable $e) {
            $hasNext = false;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'upsell_charge_id' => $upsellChargeId,
                'status' => $upsellCharge->fresh()->status,
                'amount' => $upsell->price,
                'has_next_offer' => $hasNext,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicCheckoutController::acceptUpsell error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Decline an upsell offer — record and check for downsell
     */
    public function declineUpsell(string $chargeId, string $upsellId): JsonResponse
    {
        try {
        $charge = Charge::find($chargeId);
        if (!$charge) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'charge_not_found', 'message' => 'Cobrança não encontrada'],
            ], 404);
        }

        $upsell = Upsell::where('id', $upsellId)
            ->where('checkout_id', $charge->checkout_id)
            ->first();

        if (!$upsell) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'upsell_not_found', 'message' => 'Upsell não encontrado'],
            ], 404);
        }

        // Record the decline
        try {
            ChargeOffer::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $chargeId,
                'account_id' => $charge->account_id,
                'offer_type' => $upsell->type,
                'offer_id' => $upsellId,
                'product_id' => $upsell->product_id,
                'amount' => $upsell->price,
                'status' => 'declined',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record declined offer', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
        }

        // Check for downsell
        $downsell = null;
        $hasNext = false;
        try {
            if ($upsell->downsell_id) {
                $downsell = Upsell::where('id', $upsell->downsell_id)
                    ->whereRaw('"is_active" = true')
                    ->with('product:id,name,price,description,image_url,images')
                    ->first();
            }

            // If no downsell, check for next upsell in sequence
            if (!$downsell) {
                $processedIds = ChargeOffer::where('charge_id', $chargeId)
                    ->whereIn('offer_type', ['upsell', 'downsell'])
                    ->pluck('offer_id')
                    ->toArray();
                $hasNext = Upsell::where('checkout_id', $charge->checkout_id)
                    ->whereRaw('"is_active" = true')
                    ->whereNotIn('id', $processedIds)
                    ->exists();
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to check downsell/next upsell', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'declined' => true,
                'downsell' => $downsell ? [
                    'id' => $downsell->id,
                    'title' => $downsell->title,
                    'description' => $downsell->description,
                    'headline' => $downsell->headline,
                    'call_to_action' => $downsell->call_to_action,
                    'decline_text' => $downsell->decline_text,
                    'image_url' => $downsell->image_url ?? ($downsell->product?->image_url ?? ($downsell->product?->images[0] ?? null)),
                    'video_url' => $downsell->video_url,
                    'price' => $downsell->price,
                    'original_price' => $downsell->original_price,
                    'discount_percent' => $downsell->discount_percent,
                    'type' => $downsell->type,
                    'one_click_purchase' => $downsell->one_click_purchase,
                    'timer_seconds' => $downsell->timer_seconds,
                    'benefits' => $downsell->benefits,
                    'product' => $downsell->product ? [
                        'id' => $downsell->product->id,
                        'name' => $downsell->product->name,
                        'description' => $downsell->product->description,
                        'image_url' => $downsell->product->image_url ?? ($downsell->product->images[0] ?? null),
                    ] : null,
                ] : null,
                'has_next_offer' => $hasNext || ($downsell !== null),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicCheckoutController::declineUpsell error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    private function persistChargeFailure(Charge $charge, string $failureReason, array $paymentResult = []): void
    {
        try {
            $reason = trim($failureReason);
            $metadata = is_array($charge->metadata) ? $charge->metadata : [];
            $metadata['payment_failure_context'] = array_filter([
                'logged_at' => now()->toIso8601String(),
                'acquirer' => $paymentResult['acquirer'] ?? $charge->acquirer,
                'error' => $paymentResult['error'] ?? ($reason !== '' ? Str::limit($reason, 500, '') : 'Falha ao processar pagamento'),
                'raw_response' => $paymentResult['raw_response'] ?? $paymentResult['data'] ?? null,
                'provider_transaction_id' => $paymentResult['transaction_id'] ?? $paymentResult['external_id'] ?? $charge->acquirer_transaction_id,
            ], static fn ($value) => $value !== null && $value !== '');

            $charge->update([
                'status' => Charge::STATUS_FAILED,
                'failure_reason' => $reason !== '' ? Str::limit($reason, 500, '') : 'Falha ao processar pagamento',
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist checkout charge failure state', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchPaymentStatusChangedEvent(Charge $charge): void
    {
        event(new PaymentStatusChanged(
            $charge->id,
            (string) $charge->account_id,
            (string) $charge->status,
            $charge->toArray(),
            ((float) ($charge->amount ?? 0)) / 100,
            (string) ($charge->currency ?? 'BRL'),
            $charge->customer['email'] ?? null,
        ));
    }

    private function logChargeFailureDetails(Charge $charge, array $paymentResult, string $message): void
    {
        try {
            Log::warning($message, [
                'charge_id' => $charge->id,
                'account_id' => $charge->account_id,
                'payment_method' => $charge->payment_method,
                'amount' => $charge->amount,
                'acquirer' => $paymentResult['acquirer'] ?? $charge->acquirer,
                'error' => $paymentResult['error'] ?? null,
                'raw_response' => $paymentResult['raw_response'] ?? $paymentResult['data'] ?? null,
                'provider_transaction_id' => $paymentResult['transaction_id'] ?? $paymentResult['external_id'] ?? $charge->acquirer_transaction_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log public checkout failure details', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sanitize product image URL — replace broken/unreliable URLs with fallback
     */
    private function sanitizeImageUrl(?string $url, ?string $productName = null): ?string
    {
        if (!$url) {
            return null;
        }

        // Check for unreliable image hosts
        $unreliableHosts = ['image.pollinations.ai', 'pollinations.ai'];
        foreach ($unreliableHosts as $host) {
            if (str_contains($url, $host)) {
                return $this->buildFallbackImageUrl($productName ?? 'produto');
            }
        }

        return $url;
    }

    /**
     * Build a fallback image URL from product name keywords
     */
    private function buildFallbackImageUrl(string $productName): string
    {
        $words = preg_split('/[\s,.:;!?]+/', strtolower(trim($productName)));
        $stopWords = ['de', 'da', 'do', 'das', 'dos', 'em', 'no', 'na', 'um', 'uma', 'para', 'com', 'por', 'o', 'a', 'os', 'as', 'e'];
        $keywords = [];
        foreach ($words as $w) {
            if (strlen($w) > 2 && !in_array($w, $stopWords)) {
                $keywords[] = $w;
            }
        }
        $seed = abs(crc32($productName)) % 100000;
        if (!empty($keywords)) {
            $kw = implode(',', array_slice($keywords, 0, 3));
            return "https://loremflickr.com/800/450/{$kw}?lock={$seed}";
        }
        return "https://picsum.photos/seed/{$seed}/800/450";
    }

    /**
     * Lookup saved cards for a customer by email (public, rate-limited).
     * POST to prevent email leakage in URL/logs. Requires checkout slug for account scoping.
     */
    public function lookupSavedCards(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'document' => ['required', 'string', 'min:11'],
        ]);

        $checkout = Checkout::where('slug', $slug)->where('is_active', true)->first();
        if (!$checkout) {
            return response()->json(['success' => false, 'error' => 'Checkout não encontrado'], 404);
        }

        $cards = SavedCard::where('account_id', $checkout->account_id)
            ->where('customer_email', strtolower($request->input('email')))
            ->where('customer_document', $request->input('document'))
            ->select(['id', 'card_brand', 'card_last_four', 'card_holder_name', 'exp_month', 'exp_year', 'is_default'])
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $cards,
        ]);
    }
}
