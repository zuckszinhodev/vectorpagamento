<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\SavedCard;
use App\Models\Transaction;
use App\Domain\Acquirer\Entities\Acquirer;
use App\Services\PaymentService;
use App\Services\AdminApprovalService;
use App\Services\UtmifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChargeController extends Controller
{
    private PaymentService $paymentService;
    private AdminApprovalService $approvalService;

    public function __construct(PaymentService $paymentService, AdminApprovalService $approvalService)
    {
        $this->paymentService = $paymentService;
        $this->approvalService = $approvalService;
    }
    /**
     * List charges for authenticated account
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

        $hiddenFromSellerList = [
            Charge::STATUS_FAILED,
            Charge::STATUS_DECLINED,
            'blocked',
        ];

        $query = Charge::where('account_id', $accountId)
            ->whereNotIn('status', $hiddenFromSellerList);

        if ($request->query('status')) {
            $status = $request->query('status');
            if (str_contains($status, ',')) {
                $query->whereIn('status', explode(',', $status));
            } elseif ($status === 'pending') {
                $query->whereIn('status', ['pending', 'waiting_payment']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->query('payment_method')) {
            $query->where('payment_method', $request->query('payment_method'));
        }

        if ($request->query('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->query('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        if ($request->query('customer_email')) {
            $query->where('customer->email', $request->query('customer_email'));
        }

        if ($request->query('search')) {
            $search = addcslashes($request->query('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhere('customer->email', 'like', "%{$search}%")
                  ->orWhere('customer->name', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $charges = $query->with(['product:id,name,image_url', 'transactions.splits'])->orderBy('created_at', 'desc')->paginate($perPage);

        // Enrich charges with split info
        $chargesData = collect($charges->items())->map(function ($charge) use ($accountId) {
            $chargeArray = $charge->toArray();
            unset($chargeArray['transactions']); // don't leak raw transactions to response

            // Check if this charge has an active chargeback
            $hasChargeback = \App\Models\Chargeback::where('charge_id', $charge->id)
                ->whereIn('status', ['open', 'evidence_sent', 'under_review'])
                ->exists();
            $chargeArray['has_chargeback'] = $hasChargeback;

            // Check if this charge has splits (via metadata or actual Split records)
            $splitRules = $charge->metadata['split_rules'] ?? null;
            if (!empty($splitRules) && is_array($splitRules)) {
                $transaction = $charge->transactions->first();
                $splitTotal = 0;
                if ($transaction) {
                    $splitTotal = (int) $transaction->splits
                        ->where('recipient_id', '!=', $accountId)
                        ->whereIn('status', ['transferred', 'processing', 'pending'])
                        ->sum('net_amount');
                }

                $chargeArray['has_split'] = true;
                $chargeArray['split_amount'] = $splitTotal;
                $chargeArray['split_recipients'] = count($splitRules);
            } else {
                $chargeArray['has_split'] = false;
                $chargeArray['split_amount'] = 0;
                $chargeArray['split_recipients'] = 0;
            }

            return $chargeArray;
        });

        return response()->json([
            'success' => true,
            'data' => $chargesData->values(),
            'meta' => [
                'current_page' => $charges->currentPage(),
                'per_page' => $charges->perPage(),
                'total' => $charges->total(),
                'last_page' => $charges->lastPage(),
            ],
        ]);
        } catch (\Throwable $e) {
            Log::error('ChargeController::index failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao listar cobranças'],
            ], 500);
        }
    }

    /**
     * Create a new charge
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->merge([
                'amount' => (int) $request->input('amount'),
            ]);

            $request->validate([
                'amount' => ['required', 'integer', 'min:100'],
                'payment_method' => ['required', 'in:credit_card,pix,boleto'],
                'customer' => ['required', 'array'],
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
                'description' => ['nullable', 'string'],
                'product_id' => ['nullable', 'uuid'],
                'checkout_id' => ['nullable', 'uuid'],
                'installments' => ['nullable', 'integer', 'min:1', 'max:12'],
                // Split de pagamento (opcional)
                'split_rules' => ['nullable', 'array', 'min:1'],
                'split_rules.*.recipient_id' => ['required_with:split_rules', 'uuid'],
                'split_rules.*.recipient_type' => ['nullable', 'string', 'in:account,affiliate'],
                'split_rules.*.amount' => ['required_without:split_rules.*.percentage', 'integer', 'min:1'],
                'split_rules.*.percentage' => ['required_without:split_rules.*.amount', 'numeric', 'min:0.01', 'max:100'],
                'split_rules.*.type' => ['required_with:split_rules', 'string', 'in:percentage,fixed,flat'],
                'split_rules.*.charge_processing_fee' => ['nullable', 'boolean'],
            ]);

            $accountId = $request->user()->account_id;
            
            // Validate that user has an account_id
            if (!$accountId) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'account_required',
                        'message' => 'Usuário não possui uma conta configurada. Entre em contato com o suporte.',
                    ],
                ], 400);
            }

            $idempotencyKey = trim((string) (
                $request->input('idempotency_key')
                ?? $request->header('X-Idempotency-Key')
                ?? $request->header('Idempotency-Key')
                ?? ''
            ));

            if ($idempotencyKey !== '' && (strlen($idempotencyKey) > 64 || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey))) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'invalid_idempotency_key',
                        'message' => 'Idempotency-Key inválida.',
                    ],
                ], 400);
            }

            if ($idempotencyKey !== '') {
                $existingCharge = Charge::where('account_id', $accountId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingCharge) {
                    return response()->json([
                        'success' => true,
                        'data' => $existingCharge->toArray(),
                    ], 200)
                        ->header('X-Idempotency-Key', $idempotencyKey)
                        ->header('X-Idempotency-Replay', 'true');
                }
            }
            
        $chargeId = Str::uuid()->toString();

        // Inject customer IP for UTMify tracking (required field)
        $customerData = $request->input('customer');
        if (is_array($customerData) && empty($customerData['ip'])) {
            $customerData['ip'] = $this->getClientIp($request);
        }
        if (is_array($customerData)) {
            $customerData['document'] = $this->normalizeBrazilianDocument((string) ($customerData['document'] ?? ''));
        }

        // Inject UTM tracking parameters into metadata if not present
        $metadata = $request->input('metadata', []);
        if (!isset($metadata['tracking'])) {
            $metadata['tracking'] = [
                'src'          => $request->input('src') ?? $request->query('src'),
                'sck'          => $request->input('sck') ?? $request->query('sck'),
                'utm_source'   => $request->input('utm_source') ?? $request->query('utm_source'),
                'utm_campaign' => $request->input('utm_campaign') ?? $request->query('utm_campaign'),
                'utm_medium'   => $request->input('utm_medium') ?? $request->query('utm_medium'),
                'utm_content'  => $request->input('utm_content') ?? $request->query('utm_content'),
                'utm_term'     => $request->input('utm_term') ?? $request->query('utm_term'),
            ];
        }
        if (!isset($metadata['ip'])) {
            $metadata['ip'] = $this->getClientIp($request);
        }

        // Store split_rules in metadata if provided
        $splitRules = $request->input('split_rules');
        if (!empty($splitRules)) {
            $metadata['split_rules'] = $splitRules;
        }

        $effectiveIdempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : ('chr_' . Str::random(20));

        try {
            $charge = Charge::create([
                'id' => $chargeId,
                'account_id' => $accountId,
                'product_id' => $request->input('product_id'),
                'checkout_id' => $request->input('checkout_id'),
                'affiliate_id' => $request->input('affiliate_id'),
                'amount' => $request->input('amount'),
                'currency' => 'BRL',
                'status' => 'pending',
                'payment_method' => $request->input('payment_method'),
                'installments' => $request->input('installments', 1),
                'customer' => $customerData,
                'billing' => $request->input('billing'),
                'metadata' => $metadata,
                'description' => $request->input('description'),
                'expires_at' => now()->addHours(24),
                'idempotency_key' => $effectiveIdempotencyKey,
            ]);
        } catch (QueryException $e) {
            // Handle race conditions where same idempotency key is inserted concurrently.
            $existingCharge = Charge::where('account_id', $accountId)
                ->where('idempotency_key', $effectiveIdempotencyKey)
                ->first();

            if ($existingCharge) {
                return response()->json([
                    'success' => true,
                    'data' => $existingCharge->toArray(),
                ], 200)
                    ->header('X-Idempotency-Key', $effectiveIdempotencyKey)
                    ->header('X-Idempotency-Replay', 'true');
            }

            throw $e;
        }

        // Process payment via acquirer FIRST (critical path — must be fast)
        try {
            $paymentResult = $this->paymentService->processPayment($charge, $request->input('card', []));
        } catch (\Throwable $e) {
            $failureMessage = 'Erro interno ao processar pagamento';
            Log::error('ChargeController::store processPayment failed', ['charge_id' => $charge->id, 'error' => $e->getMessage()]);
            $this->persistChargeFailure($charge, $failureMessage);
            $paymentResult = ['success' => false, 'error' => $failureMessage];
        }
        
        if (isset($paymentResult['success']) && !$paymentResult['success']) {
            $this->persistChargeFailure($charge, (string) ($paymentResult['error'] ?? 'Falha ao processar pagamento'));
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'payment_failed',
                    'message' => $paymentResult['error'] ?? 'Falha ao processar pagamento',
                ],
            ], 400);
        }

        // Auto-save card for faster future purchases
        if ($charge->payment_method === 'credit_card' && $paymentResult['success'] ?? false) {
            try {
                $cardInput = $request->input('card', []);
                $acquirerUsed = $paymentResult['acquirer'] ?? $charge->acquirer ?? 'unknown';
                SavedCard::saveFromCharge($charge, $cardInput, $acquirerUsed);
            } catch (\Throwable $e) {
                Log::warning('Failed to save card for fast checkout', [
                    'charge_id' => $charge->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($charge->payment_method === 'pix') {
            app(\App\Services\PixNominalNameService::class)->syncFromCharge($charge->refresh(), $paymentResult);
        }

        // Dispatch webhook & UTMify AFTER response is sent (non-blocking)
        $chargeForDeferred = $charge;
        dispatch(function () use ($chargeForDeferred) {
            try {
                app(\App\Services\WebhookDispatchService::class)
                    ->dispatchPaymentEvent('charge.created', $chargeForDeferred);
            } catch (\Exception $e) {
                Log::warning('Failed to dispatch charge.created webhook', ['error' => $e->getMessage()]);
            }

            try {
                app(UtmifyService::class)->dispatchSync($chargeForDeferred);
            } catch (\Exception $e) {
                Log::warning('Failed to dispatch UTMify', ['charge_id' => $chargeForDeferred->id, 'error' => $e->getMessage()]);
            }
        })->afterResponse();

        return response()->json([
            'success' => true,
            'data' => array_merge($charge->refresh()->toArray(), [
                'pix_qr_code' => $paymentResult['pix_qr_code'] ?? $charge->pix_qr_code,
                'pix_copy_paste' => $paymentResult['pix_copy_paste'] ?? $charge->pix_copy_paste,
                'boleto_url' => $paymentResult['boleto_url'] ?? $charge->boleto_url,
                'boleto_barcode' => $paymentResult['boleto_barcode'] ?? $charge->boleto_barcode,
                'transaction_id' => $paymentResult['transaction_id'] ?? null,
            ]),
        ], 201)->header('X-Idempotency-Key', $effectiveIdempotencyKey);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // Let validation exceptions be handled by the exception handler
        } catch (\Throwable $e) {
            Log::error('ChargeController::store failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['card.number', 'card.cvv']),
            ]);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Erro ao criar cobrança. Verifique os dados e tente novamente.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Get a specific charge
     */
    public function show(Request $request, string $chargeId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $charge = Charge::where('id', $chargeId)
            ->where('account_id', $accountId)
            ->with(['transactions', 'product'])
            ->first();

        if (!$charge) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'charge_not_found',
                    'message' => 'Cobrança não encontrada',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $charge,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ChargeController::show error', [
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
     * Cancel a charge
     */
    public function cancel(Request $request, string $chargeId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $charge = Charge::where('id', $chargeId)
                ->where('account_id', $accountId)
                ->first();

            if (!$charge) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'charge_not_found',
                        'message' => 'Cobrança não encontrada',
                    ],
                ], 404);
            }

            if (!in_array($charge->status, ['pending', 'waiting_payment', 'processing'])) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'invalid_status',
                        'message' => 'Esta cobrança não pode ser cancelada',
                    ],
                ], 400);
            }

            $charge->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            try {
                Transaction::create([
                    'id' => Str::uuid()->toString(),
                    'charge_id' => $chargeId,
                    'acquirer_id' => $this->resolveAcquirerId($charge),
                    'type' => 'cancellation',
                    'status' => 'cancelled',
                    'amount' => $charge->amount,
                    'net_amount' => $charge->amount,
                    'acquirer_tid' => 'cancel_' . Str::random(16),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to create cancel transaction', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'data' => $charge->fresh(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ChargeController::cancel failed', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao cancelar cobrança'],
            ], 500);
        }
    }

    /**
     * Refund a charge
     */
    public function refund(Request $request, string $chargeId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $charge = Charge::where('id', $chargeId)
                ->where('account_id', $accountId)
                ->first();

            if (!$charge) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'charge_not_found',
                        'message' => 'Cobrança não encontrada',
                    ],
                ], 404);
            }

            if (!in_array($charge->status, ['paid', 'approved'])) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'invalid_status',
                        'message' => 'Apenas cobranças pagas podem ser reembolsadas',
                    ],
                ], 400);
            }

            // Maximum refundable = original amount minus what was already refunded
            $alreadyRefunded = (int) ($charge->refunded_amount ?? 0);
            $maxRefundable    = $charge->amount - $alreadyRefunded;

            if ($maxRefundable <= 0) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'already_refunded', 'message' => 'Esta cobrança já foi totalmente reembolsada'],
                ], 400);
            }

            $request->validate([
                'amount' => ['nullable', 'integer', 'min:100', 'max:' . $maxRefundable],
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            $refundAmount = $request->input('amount', $maxRefundable);

            $approvalCheck = $this->approvalService->requireApproval($request, [
                'action_key' => 'admin.charge.refund',
                'resource_type' => 'charge',
                'resource_id' => $chargeId,
                'request_reason' => (string) ($request->input('reason') ?? 'Refund manual solicitado por operador admin'),
                'payload' => [
                    'refund_amount' => (int) $refundAmount,
                    'charge_amount' => (int) $charge->amount,
                    'charge_status' => (string) $charge->status,
                ],
                'expires_minutes' => 30,
            ]);

            if (!($approvalCheck['approved'] ?? false)) {
                return response()->json($approvalCheck['response'], $approvalCheck['http_status'] ?? 202);
            }

            // Use pessimistic lock inside a transaction to prevent concurrent double-refunds
            $refundResult = DB::transaction(function () use ($chargeId, $accountId, $refundAmount, $maxRefundable) {
                // Re-fetch with exclusive lock
                $lockedCharge = Charge::where('id', $chargeId)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedCharge || !in_array($lockedCharge->status, ['paid', 'approved'])) {
                    return ['success' => false, 'error' => 'Cobrança não está em status reembolsável (estado concorrente detectado)'];
                }

                // Re-check refundable amount inside the lock
                $alreadyRefundedLocked = (int) ($lockedCharge->refunded_amount ?? 0);
                $maxRefundableLocked   = $lockedCharge->amount - $alreadyRefundedLocked;

                if ($refundAmount > $maxRefundableLocked) {
                    return ['success' => false, 'error' => 'Valor de reembolso excede o saldo disponível'];
                }

                try {
                    $result = $this->paymentService->refund($lockedCharge, $refundAmount);
                } catch (\Throwable $e) {
                    Log::error('Refund processing failed', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
                    $result = ['success' => false, 'error' => 'Erro interno ao processar reembolso'];
                }

                if ($result['success'] ?? false) {
                    try {
                        Transaction::create([
                            'id'          => Str::uuid()->toString(),
                            'charge_id'   => $chargeId,
                            'acquirer_id' => $this->resolveAcquirerId($lockedCharge),
                            'type'        => 'refund',
                            'status'      => 'refunded',
                            'amount'      => $refundAmount,
                            'net_amount'  => $refundAmount,
                            'acquirer_tid' => 'refund_' . Str::random(16),
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to create refund transaction', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
                    }
                }

                return array_merge($result, ['charge' => $lockedCharge->fresh()]);
            });

            if (!($refundResult['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code'    => 'refund_failed',
                        'message' => $refundResult['error'] ?? 'Falha no reembolso',
                    ],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data'    => $refundResult['charge'],
            ]);
        } catch (\Throwable $e) {
            Log::error('ChargeController::refund failed', ['charge_id' => $chargeId, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao processar reembolso'],
            ], 500);
        }
    }

    /**
     * Sync charge status with Babylon (for PIX/Boleto that were paid before webhook)
     */
    public function syncStatus(Request $request, string $chargeId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $charge = Charge::where('id', $chargeId)
            ->where('account_id', $accountId)
            ->first();

        if (!$charge) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'charge_not_found',
                    'message' => 'Cobrança não encontrada',
                ],
            ], 404);
        }

        if ($charge->status === 'approved') {
            return response()->json([
                'success' => true,
                'data' => $charge,
                'message' => 'Cobrança já está aprovada',
                'synced' => false,
            ]);
        }

        $previousStatus = $charge->status;

        // If charge has acquirer_transaction_id, sync with Babylon API
        if ($charge->acquirer_transaction_id) {
            $syncResult = $this->paymentService->syncChargeStatus($charge);
            $charge->refresh();

            // If synced and now paid, also update wallet/checkout and dispatch notifications
            if ($syncResult['synced'] && in_array($charge->status, ['paid', 'approved'])) {
                if ($previousStatus !== 'approved' && !($syncResult['side_effects_handled'] ?? false)) {
                    // Normalize to 'approved'
                    if ($charge->status === 'paid') {
                        $charge->update(['status' => 'approved', 'paid_at' => $charge->paid_at ?? now()]);
                    }
                    // Update wallet
                    $this->paymentService->updateWalletOnPayment($charge);

                    // Update checkout revenue
                    if ($charge->checkout_id && in_array($charge->payment_method, ['pix', 'boleto'])) {
                        $checkout = \App\Models\Checkout::find($charge->checkout_id);
                        if ($checkout) {
                            $checkout->increment('total_revenue', $charge->amount);
                        }
                    }

                    // Dispatch notifications (UTMify, N8N, per-account webhooks, emails)
                    $this->paymentService->dispatchApprovedNotifications($charge->fresh());

                    Log::info('Charge synced and marked as paid', [
                        'charge_id' => $charge->id,
                        'previous_status' => $previousStatus,
                        'new_status' => $charge->status,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $charge->fresh(),
                'synced' => $syncResult['synced'],
                'previous_status' => $previousStatus,
                'message' => $syncResult['synced'] 
                    ? 'Status sincronizado com sucesso'
                    : 'Não foi possível sincronizar com o adquirente',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $charge,
            'synced' => false,
            'message' => 'Cobrança sem ID de transação do adquirente para sincronizar',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ChargeController::syncStatus error', [
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
     * Sync all pending charges with Babylon
     */
    public function syncAllPending(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $pendingCharges = Charge::where('account_id', $accountId)
            ->whereIn('status', ['pending', 'waiting_payment', 'processing'])
            ->whereNotNull('acquirer_transaction_id')
            ->get();

        $synced = 0;
        $updated = 0;
        $errors = 0;

        foreach ($pendingCharges as $charge) {
            try {
                $previousStatus = $charge->status;
                $syncResult = $this->paymentService->syncChargeStatus($charge);
                
                if ($syncResult['synced']) {
                    $synced++;
                    $charge->refresh();

                    if (in_array($charge->status, ['paid', 'approved']) && $previousStatus !== 'approved' && !($syncResult['side_effects_handled'] ?? false)) {
                        if ($charge->status === 'paid') {
                            $charge->update(['status' => 'approved', 'paid_at' => $charge->paid_at ?? now()]);
                        }
                        $this->paymentService->updateWalletOnPayment($charge);

                        if ($charge->checkout_id && in_array($charge->payment_method, ['pix', 'boleto'])) {
                            $checkout = \App\Models\Checkout::find($charge->checkout_id);
                            if ($checkout) {
                                $checkout->increment('total_revenue', $charge->amount);
                            }
                        }

                        // Dispatch UTMify + notifications for newly approved charges
                        $this->paymentService->dispatchApprovedNotifications($charge->fresh());
                        $updated++;
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                Log::warning('Failed to sync charge', [
                    'charge_id' => $charge->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Sincronização concluída: {$synced} sincronizadas, {$updated} atualizadas, {$errors} erros",
            'data' => [
                'total_pending' => $pendingCharges->count(),
                'synced' => $synced,
                'updated' => $updated,
                'errors' => $errors,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ChargeController::syncAllPending error', [
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
     * Soft-delete a charge.
     *
     * Financial records (approved, refunded, chargeback) cannot be deleted
     * to maintain audit trail compliance.
     */
    public function destroy(Request $request, string $chargeId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $charge = Charge::where('id', $chargeId)
            ->where('account_id', $accountId)
            ->first();

        if (!$charge) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'charge_not_found',
                    'message' => 'Cobrança não encontrada',
                ],
            ], 404);
        }

        // Prevent deletion of financially relevant charges
        $protectedStatuses = ['approved', 'refunded', 'partially_refunded', 'chargeback'];
        if (in_array($charge->status, $protectedStatuses)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'charge_protected',
                    'message' => 'Cobranças pagas, estornadas ou com chargeback não podem ser excluídas.',
                ],
            ], 422);
        }

        // Soft-delete (charge + transactions keep audit trail)
        $charge->delete();

        Log::info('Charge soft-deleted', [
            'charge_id' => $chargeId,
            'account_id' => $accountId,
            'status' => $charge->status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cobrança excluída com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ChargeController::destroy error', [
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

    private function persistChargeFailure(Charge $charge, string $failureReason): void
    {
        try {
            $reason = trim($failureReason);

            $charge->update([
                'status' => Charge::STATUS_FAILED,
                'failure_reason' => $reason !== '' ? Str::limit($reason, 500, '') : 'Falha ao processar pagamento',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist charge failure state', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the acquirer ID from the charge's acquirer slug.
     */
    private function resolveAcquirerId(Charge $charge): string
    {
        $slug = $charge->acquirer ?? 'payfort';

        $acquirer = Acquirer::firstOrCreate(
            ['slug' => $slug],
            [
                'id' => Str::uuid()->toString(),
                'name' => ucfirst($slug),
                'slug' => $slug,
                'is_active' => true,
                'supported_methods' => json_encode(['pix']),
                'settings' => json_encode([]),
            ]
        );

        return $acquirer->id;
    }
}
