<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Charge;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AffiliateController extends Controller
{
    /**
     * List affiliates for authenticated account
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $query = Affiliate::where('account_id', $accountId);

            if ($request->query('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->query('product_id')) {
                $query->where('product_id', $request->query('product_id'));
            }

            if ($request->query('search')) {
                $search = addcslashes($request->query('search'), '%_\\');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }

            $perPage = min((int) $request->query('per_page', 20), 100);
            $affiliates = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $affiliates->items(),
                'meta' => [
                    'current_page' => $affiliates->currentPage(),
                    'per_page' => $affiliates->perPage(),
                    'total' => $affiliates->total(),
                    'last_page' => $affiliates->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('AffiliateController::index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        }
    }

    /**
     * Create a new affiliate
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email'],
                'product_id' => ['nullable', 'uuid', 'exists:products,id'],
                'commission_type' => ['required', 'in:percentage,fixed'],
                'commission_value' => ['required', 'numeric', 'min:0'],
            ]);

            $accountId = $request->user()->account_id;

            // Generate unique affiliate code
            $code = strtoupper(Str::random(8));
            while (Affiliate::where('code', $code)->exists()) {
                $code = strtoupper(Str::random(8));
            }

            $affiliate = Affiliate::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $accountId,
                'user_id' => $request->input('user_id', $request->user()->id),
                'product_id' => $request->input('product_id'),
                'code' => $code,
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'document' => $request->input('document'),
                'commission_type' => $request->input('commission_type'),
                'commission_value' => $request->input('commission_value'),
                'status' => 'active',
                'metadata' => $request->input('metadata', []),
            ]);

            return response()->json([
                'success' => true,
                'data' => array_merge($affiliate->toArray(), [
                    'affiliate_url' => url('/ref/' . $code),
                ]),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('AffiliateController::store failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao criar afiliado']], 500);
        }
    }

    /**
     * Invite affiliate by email (user must have an account)
     */
    public function invite(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'product_id' => ['required', 'uuid', 'exists:products,id'],
            ]);

            $accountId = $request->user()->account_id;
            $email = strtolower(trim($request->input('email')));
            $productId = $request->input('product_id');

            // Find user by email
            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'user_not_found',
                        'message' => 'Nenhum usuário encontrado com este email. O afiliado precisa ter uma conta na plataforma.',
                    ],
                ], 404);
            }

            // Cannot invite yourself
            if ($user->id === $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'self_invite',
                        'message' => 'Você não pode se convidar como afiliado.',
                    ],
                ], 400);
            }

            // Check if product exists and belongs to the account
            $product = Product::where('id', $productId)
                ->where('account_id', $accountId)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'product_not_found',
                        'message' => 'Produto não encontrado.',
                    ],
                ], 404);
            }

            // Check if affiliate already exists for this user and product
            $existingAffiliate = Affiliate::where('user_id', $user->id)
                ->where('product_id', $productId)
                ->first();

            if ($existingAffiliate) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'already_affiliated',
                        'message' => 'Este usuário já é afiliado deste produto.',
                        'affiliate' => [
                            'id' => $existingAffiliate->id,
                            'code' => $existingAffiliate->code,
                            'status' => $existingAffiliate->status,
                        ],
                    ],
                ], 400);
            }

            // Generate unique affiliate code
            $code = strtoupper(Str::random(8));
            while (Affiliate::where('code', $code)->exists()) {
                $code = strtoupper(Str::random(8));
            }

            // Get commission from product
            $commissionPercent = $product->commission_percent ?? 10;

            // Create affiliate
            $affiliate = Affiliate::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $accountId,
                'user_id' => $user->id,
                'product_id' => $productId,
                'code' => $code,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'commission_type' => 'percentage',
                'commission_value' => $commissionPercent,
                'status' => 'active',
                'metadata' => [
                    'invited_by' => $request->user()->id,
                    'affiliate_account_id' => $user->account_id,
                ],
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $affiliate->id,
                    'user_id' => $user->id,
                    'name' => $affiliate->name,
                    'email' => $affiliate->email,
                    'code' => $affiliate->code,
                    'commission_percent' => $commissionPercent,
                    'status' => $affiliate->status,
                    'affiliate_url' => url('/ref/' . $code),
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                    ],
                    'message' => "Afiliado {$user->name} convidado com sucesso!",
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('AffiliateController::invite failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao convidar afiliado']], 500);
        }
    }

    /**
     * Get a specific affiliate
     */
    public function show(Request $request, string $affiliateId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $affiliate = Affiliate::where('id', $affiliateId)
            ->where('account_id', $accountId)
            ->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'affiliate_not_found',
                    'message' => 'Afiliado não encontrado',
                ],
            ], 404);
        }

        // Get affiliate stats
        $stats = $this->getAffiliateStats($affiliate);

        return response()->json([
            'success' => true,
            'data' => array_merge($affiliate->toArray(), [
                'affiliate_url' => url('/ref/' . $affiliate->code),
                'stats' => $stats,
            ]),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AffiliateController::show error', [
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
     * Update an affiliate
     */
    public function update(Request $request, string $affiliateId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $affiliate = Affiliate::where('id', $affiliateId)
            ->where('account_id', $accountId)
            ->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'affiliate_not_found',
                    'message' => 'Afiliado não encontrado',
                ],
            ], 404);
        }

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'commission_type' => ['sometimes', 'in:percentage,fixed'],
            'commission_value' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive,blocked'],
        ]);

        $updateData = $request->only([
            'name', 'email', 'phone', 'document', 'product_id',
            'commission_type', 'commission_value', 'status', 'metadata',
        ]);

        $affiliate->update(array_filter($updateData, fn($v) => $v !== null));

        return response()->json([
            'success' => true,
            'data' => array_merge($affiliate->fresh()->toArray(), [
                'affiliate_url' => url('/ref/' . $affiliate->code),
            ]),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AffiliateController::update error', [
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
     * Delete an affiliate
     */
    public function destroy(Request $request, string $affiliateId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $affiliate = Affiliate::where('id', $affiliateId)
            ->where('account_id', $accountId)
            ->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'affiliate_not_found',
                    'message' => 'Afiliado não encontrado',
                ],
            ], 404);
        }

        $affiliate->delete();

        return response()->json([
            'success' => true,
            'data' => ['message' => 'Afiliado removido com sucesso'],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AffiliateController::destroy error', [
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
     * Get affiliate statistics
     */
    public function stats(Request $request, string $affiliateId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $affiliate = Affiliate::where('id', $affiliateId)
            ->where('account_id', $accountId)
            ->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'affiliate_not_found',
                    'message' => 'Afiliado não encontrado',
                ],
            ], 404);
        }

        $stats = $this->getAffiliateStats($affiliate);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AffiliateController::stats error', [
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
     * Get affiliate sales
     */
    public function sales(Request $request, string $affiliateId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $affiliate = Affiliate::where('id', $affiliateId)
                ->where('account_id', $accountId)
                ->first();

            if (!$affiliate) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'affiliate_not_found',
                        'message' => 'Afiliado não encontrado',
                    ],
                ], 404);
            }

            $charges = Charge::where('affiliate_id', $affiliateId)
                ->where('status', 'approved')
                ->orderBy('paid_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $charges->items(),
                'meta' => [
                    'current_page' => $charges->currentPage(),
                    'per_page' => $charges->perPage(),
                    'total' => $charges->total(),
                    'last_page' => $charges->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('AffiliateController::sales failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        }
    }

    /**
     * Validate affiliate code and return affiliate data (public route)
     */
    public function findByCode(Request $request, string $code): JsonResponse
    {
        try {
        $affiliate = Affiliate::where('code', $code)
            ->where('status', 'active')
            ->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'affiliate_not_found',
                    'message' => 'Código de afiliado inválido ou inativo',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $affiliate->id,
                'name' => $affiliate->name,
                'code' => $affiliate->code,
                'product_id' => $affiliate->product_id,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AffiliateController::findByCode error', [
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
     * Get affiliate commissions history
     */
    public function commissions(Request $request, string $affiliateId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $affiliate = Affiliate::where('id', $affiliateId)
            ->where('account_id', $accountId)
            ->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'affiliate_not_found',
                    'message' => 'Afiliado não encontrado',
                ],
            ], 404);
        }

        $charges = Charge::where('affiliate_id', $affiliateId)
            ->where('status', 'approved')
            ->orderBy('paid_at', 'desc')
            ->paginate(20);

        $commissions = $charges->getCollection()->map(function ($charge) use ($affiliate) {
            $commissionAmount = 0;
            if ($affiliate->commission_type === 'percentage') {
                $commissionAmount = (int) (($charge->amount * $affiliate->commission_value) / 100);
            } else {
                $commissionAmount = (int) $affiliate->commission_value;
            }

            return [
                'charge_id' => $charge->id,
                'amount' => $charge->amount,
                'commission_amount' => $commissionAmount,
                'commission_type' => $affiliate->commission_type,
                'commission_value' => $affiliate->commission_value,
                'status' => 'approved',
                'paid_at' => $charge->paid_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $commissions,
            'meta' => [
                'current_page' => $charges->currentPage(),
                'per_page' => $charges->perPage(),
                'total' => $charges->total(),
                'last_page' => $charges->lastPage(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AffiliateController::commissions error', [
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
     * Calculate affiliate statistics
     */
    private function getAffiliateStats(Affiliate $affiliate): array
    {
        $charges = Charge::where('affiliate_id', $affiliate->id);
        
        $totalSales = $charges->clone()->where('status', 'approved')->count();
        $totalRevenue = $charges->clone()->where('status', 'approved')->sum('amount');
        
        $commissionEarned = 0;
        if ($affiliate->commission_type === 'percentage') {
            $commissionEarned = ($totalRevenue * $affiliate->commission_value) / 100;
        } else {
            $commissionEarned = $totalSales * $affiliate->commission_value;
        }

        return [
            'total_sales' => $totalSales,
            'total_revenue' => $totalRevenue,
            'commission_earned' => (int) $commissionEarned,
            'pending_commission' => 0,
            'clicks' => 0,
            'conversion_rate' => 0,
        ];
    }
}
