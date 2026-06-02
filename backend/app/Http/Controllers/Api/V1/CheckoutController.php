<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    /**
     * List checkouts for authenticated account
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $query = Checkout::where('account_id', $accountId)->with('product');

            if ($request->query('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->query('type')) {
                $query->where('type', $request->query('type'));
            }

            $perPage = min((int) $request->query('per_page', 20), 100);
            $checkouts = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Transform data to include product info and url
            $data = collect($checkouts->items())->map(function ($checkout) {
                $checkoutData = $checkout->toArray();
                try {
                    $checkoutData['url'] = $checkout->url;
                } catch (\Throwable $e) {
                    $checkoutData['url'] = null;
                }
                if ($checkout->product) {
                    $images = is_array($checkout->product->images) ? $checkout->product->images : [];
                    $checkoutData['product'] = [
                        'id' => $checkout->product->id,
                        'name' => $checkout->product->name,
                        'price' => $checkout->product->price,
                        'image_url' => $checkout->product->image_url ?? ($images[0] ?? null),
                    ];
                }
                return $checkoutData;
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'current_page' => $checkouts->currentPage(),
                    'per_page' => $checkouts->perPage(),
                    'total' => $checkouts->total(),
                    'last_page' => $checkouts->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CheckoutController::index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
        }
    }

    /**
     * Create a new checkout
     */
    public function store(Request $request): JsonResponse
    {
        try {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'in:redirect,embedded,popup'],
            'product_id' => ['nullable', 'uuid'],
            'settings' => ['nullable', 'array'],
            'theme' => ['nullable', 'array'],
        ]);

        $accountId = $request->user()->account_id;

        // Validate product belongs to account if provided
        if ($request->input('product_id')) {
            $product = Product::where('id', $request->input('product_id'))
                ->where('account_id', $accountId)
                ->first();
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'product_not_found',
                        'message' => 'Produto não encontrado',
                    ],
                ], 404);
            }
        }

        // Generate random slug
        $slug = $request->input('slug');
        if (!$slug) {
            do {
                $slug = Str::random(8);
            } while (Checkout::where('slug', $slug)->exists());
        } else {
            $baseSlug = $slug;
            $counter = 1;
            while (Checkout::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
        }

        $defaultSettings = [
            'theme_color' => '#6366f1',
            'collect_cpf' => true,
            'collect_address' => false,
            'collect_phone' => true,
            'payment_methods' => ['credit_card', 'pix', 'boleto'],
            'max_installments' => 12,
            'exit_popup_enabled' => false,
            'timer_enabled' => false,
            'timer_minutes' => 15,
            'testimonials_enabled' => false,
            'theme' => [
                'template' => 'modern-split',
                'primaryColor' => '#10b981',
                'secondaryColor' => '#6366f1',
                'backgroundColor' => '#f8fafc',
                'cardBackground' => '#ffffff',
                'textColor' => '#1e293b',
                'mutedColor' => '#64748b',
                'borderRadius' => 'xl',
                'fontFamily' => 'Inter',
                'buttonStyle' => 'gradient',
                'shadow' => 'lg',
            ],
        ];

        $checkout = Checkout::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $accountId,
            'product_id' => $request->input('product_id'),
            'name' => $request->input('name'),
            'slug' => $slug,
            'type' => $request->input('type', 'redirect'),
            'status' => 'active',
            'theme' => $request->input('theme', [
                'template' => 'modern-split',
                'primaryColor' => '#10b981',
                'secondaryColor' => '#6366f1',
                'backgroundColor' => '#f8fafc',
                'cardBackground' => '#ffffff',
                'textColor' => '#1e293b',
                'mutedColor' => '#64748b',
                'borderRadius' => 'xl',
                'fontFamily' => 'Inter',
                'buttonStyle' => 'gradient',
                'shadow' => 'lg',
            ]),
            'fields' => $request->input('fields', [
                'name' => true,
                'email' => true,
                'phone' => true,
                'document' => true,
            ]),
            'settings' => array_merge($defaultSettings, $request->input('settings', [])),
            'views_count' => 0,
            'conversions_count' => 0,
        ]);

        // Load product relationship
        $checkout->load('product');

        $responseData = $checkout->toArray();
        $responseData['url'] = $checkout->url;
        if ($checkout->product) {
            $responseData['product'] = [
                'id' => $checkout->product->id,
                'name' => $checkout->product->name,
                'price' => $checkout->product->price,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $responseData,
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CheckoutController::store error', [
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
     * Get a specific checkout
     */
    public function show(Request $request, string $checkoutId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $checkout = Checkout::where('id', $checkoutId)
            ->where('account_id', $accountId)
            ->with('product')
            ->first();

        if (!$checkout) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'checkout_not_found',
                    'message' => 'Checkout não encontrado',
                ],
            ], 404);
        }

        $responseData = $checkout->toArray();
        $responseData['url'] = $checkout->url;
        if ($checkout->product) {
            $responseData['product'] = [
                'id' => $checkout->product->id,
                'name' => $checkout->product->name,
                'description' => $checkout->product->description ?? '',
                'price' => $checkout->product->price,
                'original_price' => $checkout->product->original_price,
                'image_url' => $checkout->product->image_url ?? ($checkout->product->images[0] ?? null),
                'type' => $checkout->product->type,
                'shipping_required' => $checkout->product->shipping_required ?? false,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $responseData,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CheckoutController::show error', [
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
     * Update a checkout
     */
    public function update(Request $request, string $checkoutId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $checkout = Checkout::where('id', $checkoutId)
            ->where('account_id', $accountId)
            ->first();

        if (!$checkout) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'checkout_not_found',
                    'message' => 'Checkout não encontrado',
                ],
            ], 404);
        }

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'in:redirect,embedded,popup'],
            'status' => ['sometimes', 'in:active,inactive,draft'],
            'product_id' => ['nullable', 'uuid'],
            'settings' => ['nullable', 'array'],
            'theme' => ['nullable', 'array'],
            'fields' => ['nullable', 'array'],
        ]);

        // Validate product belongs to account if provided
        if ($request->has('product_id') && $request->input('product_id')) {
            $product = Product::where('id', $request->input('product_id'))
                ->where('account_id', $accountId)
                ->first();
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'product_not_found',
                        'message' => 'Produto não encontrado',
                    ],
                ], 404);
            }
        }

        $updateData = $request->only(['name', 'slug', 'type', 'status', 'product_id', 'settings', 'theme', 'fields']);

        if (isset($updateData['slug']) && $updateData['slug'] !== $checkout->slug) {
            $slug = $updateData['slug'];
            $baseSlug = $slug;
            $counter = 1;
            while (Checkout::where('slug', $slug)->where('id', '!=', $checkoutId)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $updateData['slug'] = $slug;
        }

        // Merge settings instead of replacing
        if (isset($updateData['settings'])) {
            $updateData['settings'] = array_merge($checkout->settings ?? [], $updateData['settings']);
        }

        $checkout->update(array_filter($updateData, fn($v) => $v !== null));
        $checkout->load('product');

        $responseData = $checkout->fresh()->toArray();
        $responseData['url'] = $checkout->url;
        if ($checkout->product) {
            $responseData['product'] = [
                'id' => $checkout->product->id,
                'name' => $checkout->product->name,
                'price' => $checkout->product->price,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $responseData,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CheckoutController::update error', [
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
     * Delete a checkout
     */
    public function destroy(Request $request, string $checkoutId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $checkout = Checkout::where('id', $checkoutId)
            ->where('account_id', $accountId)
            ->first();

        if (!$checkout) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'checkout_not_found',
                    'message' => 'Checkout não encontrado',
                ],
            ], 404);
        }

        $checkout->delete();

        return response()->json([
            'success' => true,
            'data' => ['message' => 'Checkout removido com sucesso'],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CheckoutController::destroy error', [
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
     * Clone a checkout
     */
    public function clone(Request $request, string $checkoutId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $checkout = Checkout::where('id', $checkoutId)
            ->where('account_id', $accountId)
            ->first();

        if (!$checkout) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'checkout_not_found',
                    'message' => 'Checkout não encontrado',
                ],
            ], 404);
        }

        // Generate random slug for clone
        do {
            $slug = Str::random(8);
        } while (Checkout::where('slug', $slug)->exists());

        $newCheckout = Checkout::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $accountId,
            'product_id' => $checkout->product_id,
            'name' => $checkout->name . ' (Cópia)',
            'slug' => $slug,
            'type' => $checkout->type,
            'status' => 'draft',
            'theme' => $checkout->theme,
            'fields' => $checkout->fields,
            'settings' => $checkout->settings,
            'views_count' => 0,
            'conversions_count' => 0,
        ]);

        $newCheckout->load('product');

        return response()->json([
            'success' => true,
            'data' => $newCheckout,
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CheckoutController::clone error', [
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
     * Get checkout analytics
     */
    public function analytics(Request $request, string $checkoutId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $checkout = Checkout::where('id', $checkoutId)
            ->where('account_id', $accountId)
            ->first();

        if (!$checkout) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'checkout_not_found',
                    'message' => 'Checkout não encontrado',
                ],
            ], 404);
        }

        $period = $request->query('period', '30d');
        $daysMap = ['7d' => 7, '15d' => 15, '30d' => 30, '90d' => 90, '365d' => 365];
        $days = $daysMap[$period] ?? 30;
        $startDate = now()->subDays($days)->startOfDay();

        $charges = \App\Models\Charge::where('checkout_id', $checkoutId)
            ->where('account_id', $accountId)
            ->where('created_at', '>=', $startDate)
            ->get();

        $allCharges = \App\Models\Charge::where('checkout_id', $checkoutId)
            ->where('account_id', $accountId)
            ->get();

        // Payment method breakdown
        $byMethod = $charges->groupBy('payment_method')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total' => $group->whereIn('status', ['approved', 'authorized'])->sum('amount'),
                'approved' => $group->where('status', 'approved')->count() + $group->where('status', 'authorized')->count(),
            ];
        });

        // Status breakdown
        $byStatus = $charges->groupBy('status')->map->count();

        // Daily revenue for chart (period)
        $dailyRevenue = $charges->whereIn('status', ['approved', 'authorized'])
            ->groupBy(fn ($c) => $c->created_at->format('Y-m-d'))
            ->map(function ($group, $date) {
                return [
                    'date' => $date,
                    'revenue' => $group->sum('amount'),
                    'count' => $group->count(),
                ];
            })->sortKeys()->values();

        // Daily views/conversions for chart
        // Fill all days in period
        $dailyChart = collect();
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayRevenue = $dailyRevenue->firstWhere('date', $date);
            $dailyChart->push([
                'date' => $date,
                'revenue' => $dayRevenue['revenue'] ?? 0,
                'sales' => $dayRevenue['count'] ?? 0,
            ]);
        }

        // Top charges (recent approved)
        $recentCharges = $charges->sortByDesc('created_at')->take(10)->map(function ($c) {
            return [
                'id' => $c->id,
                'amount' => $c->amount,
                'status' => $c->status,
                'payment_method' => $c->payment_method,
                'customer_name' => $c->customer['name'] ?? $c->customer['email'] ?? 'Anônimo',
                'customer_email' => $c->customer['email'] ?? null,
                'created_at' => $c->created_at->format('c'),
                'installments' => $c->installments,
            ];
        })->values();

        // Refund stats
        $refundedCharges = $allCharges->where('status', 'refunded');
        $chargebackCharges = $allCharges->where('status', 'chargeback');

        // Ticket médio
        $approvedCharges = $charges->whereIn('status', ['approved', 'authorized']);
        $avgTicket = $approvedCharges->count() > 0 ? (int) round($approvedCharges->avg('amount')) : 0;

        // Revenue in period vs all time
        $periodRevenue = $approvedCharges->sum('amount');
        $allTimeRevenue = $allCharges->whereIn('status', ['approved', 'authorized'])->sum('amount');

        // Installment breakdown
        $byInstallments = $approvedCharges->where('payment_method', 'credit_card')
            ->groupBy('installments')
            ->map->count()
            ->sortKeys();

        return response()->json([
            'success' => true,
            'data' => [
                'overview' => [
                    'views' => (int) $checkout->views_count,
                    'conversions' => (int) $checkout->conversions_count,
                    'conversion_rate' => (float) $checkout->conversion_rate,
                    'total_revenue' => (int) $checkout->total_revenue,
                    'period_revenue' => (int) $periodRevenue,
                    'all_time_revenue' => (int) $allTimeRevenue,
                    'avg_ticket' => $avgTicket,
                    'total_charges' => $charges->count(),
                    'approved_charges' => $approvedCharges->count(),
                    'pending_charges' => $charges->where('status', 'pending')->count() + $charges->where('status', 'processing')->count(),
                    'failed_charges' => $charges->where('status', 'failed')->count() + $charges->where('status', 'declined')->count(),
                    'refunded_count' => $refundedCharges->count(),
                    'refunded_amount' => (int) $refundedCharges->sum('refunded_amount'),
                    'chargeback_count' => $chargebackCharges->count(),
                    'total_fees' => (int) $approvedCharges->sum('fee_amount'),
                    'net_revenue' => (int) ($periodRevenue - $approvedCharges->sum('fee_amount')),
                ],
                'by_payment_method' => $byMethod,
                'by_status' => $byStatus,
                'by_installments' => $byInstallments,
                'daily_chart' => $dailyChart,
                'recent_charges' => $recentCharges,
                'period' => $period,
                'period_days' => $days,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CheckoutController::analytics error', [
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
}
