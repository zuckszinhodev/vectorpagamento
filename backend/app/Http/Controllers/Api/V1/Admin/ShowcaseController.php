<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShowcaseController extends Controller
{
    /**
     * List ALL products from ALL accounts (admin only) - Only showcase products
     */
    public function allProducts(Request $request): JsonResponse
    {
        try {
            $query = Product::with(['account.users', 'category'])
                ->select([
                    'id',
                    'account_id',
                    'name',
                    'description',
                    'price',
                    'currency',
                    'type',
                    'commission_percent',
                    'category_id',
                    'image_url',
                    'is_active',
                    'is_showcase',
                    'showcase_status',
                    'showcase_visibility',
                    'affiliate_enabled',
                    'sales_count',
                    'created_at',
                    'updated_at',
                ])
                ->where('is_showcase', true);

            // Filter by status
            if ($request->has('status')) {
                $status = $request->status;
                if ($status === 'pending') {
                    $query->where('is_showcase', true)->where('showcase_status', 'pending');
                } elseif ($status === 'approved') {
                    $query->where(function ($q) {
                        $q->where(function ($q2) {
                            $q2->where('is_showcase', true)->where('showcase_status', 'approved');
                        })->orWhere(function ($q2) {
                            $q2->where('is_active', true)->where('affiliate_enabled', true);
                        });
                    });
                } elseif ($status === 'rejected') {
                    $query->where('is_showcase', true)->where('showcase_status', 'rejected');
                }
            }

            // Search
            if ($request->has('search') && $request->search) {
                $search = addcslashes($request->search, '%_\\');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('account.users', function ($q2) use ($search) {
                            $q2->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            // Filter by account
            if ($request->has('account_id')) {
                $query->where('account_id', $request->account_id);
            }

            $perPage = min((int) ($request->per_page ?? 50), 100);
            $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

            $formattedProducts = $products->getCollection()->map(function ($product) {
                $accountOwner = $product->account?->users?->first();

                // Determine effective status
                $status = 'inactive';
                if ($product->is_showcase && $product->showcase_status) {
                    $status = $product->showcase_status;
                } elseif ($product->is_active && $product->affiliate_enabled) {
                    $status = 'approved';
                } elseif ($product->is_active) {
                    $status = 'active';
                }

                return [
                    'id' => $product->id,
                    'user_id' => $product->account_id,
                    'user_name' => $accountOwner?->name ?? 'N/A',
                    'user_email' => $accountOwner?->email ?? 'N/A',
                    'product_name' => $product->name,
                    'description' => $product->description,
                    'price' => $product->price,
                    'currency' => $product->currency ?? 'BRL',
                    'type' => $product->type ?? 'digital',
                    'commission_percentage' => $product->commission_percent ?? 0,
                    'category' => $product->category->name ?? 'Sem categoria',
                    'image_url' => $product->image_url,
                    'status' => $status,
                    'is_active' => $product->is_active,
                    'is_showcase' => $product->is_showcase,
                    'affiliate_enabled' => $product->affiliate_enabled,
                    'visibility' => $product->showcase_visibility ?? ($product->is_active ? 'public' : 'private'),
                    'sales_count' => $product->sales_count ?? 0,
                    'created_at' => $product->created_at->toIso8601String(),
                    'updated_at' => $product->updated_at?->toIso8601String(),
                ];
            });

            // Stats
            $allProducts = Product::query();
            $totalProducts = (clone $allProducts)->count();
            $totalActive = (clone $allProducts)->where('is_active', true)->count();
            $totalShowcase = Product::where('is_showcase', true)->count();
            $totalPending = Product::where('is_showcase', true)->where('showcase_status', 'pending')->count();
            $totalApproved = Product::where('is_showcase', true)->where('showcase_status', 'approved')->count();
            $totalRejected = Product::where('is_showcase', true)->where('showcase_status', 'rejected')->count();
            $totalAffiliate = Product::where('affiliate_enabled', true)->where('is_active', true)->count();

            return response()->json([
                'success' => true,
                'data' => $formattedProducts,
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
                'stats' => [
                    'total' => $totalProducts,
                    'active' => $totalActive,
                    'showcase' => $totalShowcase,
                    'pending' => $totalPending,
                    'approved' => $totalApproved,
                    'rejected' => $totalRejected,
                    'affiliate' => $totalAffiliate,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch all products', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'fetch_failed',
                    'message' => 'Erro ao buscar produtos',
                ],
            ], 500);
        }
    }

    /**
     * List all showcase products for admin review
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Product::with(['account.users', 'category'])
                ->select([
                    'id',
                    'account_id',
                    'name',
                    'description',
                    'price',
                    'commission_percent',
                    'category_id',
                    'image_url',
                    'showcase_status',
                    'showcase_visibility',
                    'sales_count',
                    'created_at',
                    'updated_at',
                ])
                ->where('is_showcase', true);

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('showcase_status', $request->status);
            }

            $products = $query->orderBy('created_at', 'desc')->get();

            $formattedProducts = $products->map(function ($product) {
                $accountOwner = $product->account?->users?->first();
                
                return [
                    'id' => $product->id,
                    'user_id' => $product->account_id,
                    'user_name' => $accountOwner?->name ?? 'N/A',
                    'user_email' => $accountOwner?->email ?? 'N/A',
                    'product_name' => $product->name,
                    'description' => $product->description,
                    'price' => $product->price,
                    'commission_percentage' => $product->commission_percent ?? 0,
                    'category' => $product->category->name ?? 'Sem categoria',
                    'image_url' => $product->image_url,
                    'status' => $product->showcase_status ?? 'pending',
                    'visibility' => $product->showcase_visibility ?? 'private',
                    'sales_count' => $product->sales_count ?? 0,
                    'created_at' => $product->created_at->toIso8601String(),
                    'updated_at' => $product->updated_at?->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedProducts,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch showcase products', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'fetch_failed',
                    'message' => 'Erro ao buscar produtos da vitrine',
                ],
            ], 500);
        }
    }

    /**
     * Approve a showcase product
     */
    public function approve(Request $request, string $productId): JsonResponse
    {
        try {
            $product = Product::find($productId);

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'product_not_found',
                        'message' => 'Produto não encontrado',
                    ],
                ], 404);
            }

            DB::beginTransaction();

            // Campos da vitrine não são preenchíveis pelo seller: forceFill é o que realmente grava
            $product->forceFill([
                'showcase_status' => 'approved',
                'showcase_visibility' => 'public',
                'showcase_approved_at' => now(),
                'showcase_approved_by' => $request->user()->id,
            ])->save();

            DB::commit();

            Log::info('Showcase product approved', [
                'product_id' => $productId,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Produto aprovado com sucesso',
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve showcase product', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'approval_failed',
                    'message' => 'Erro ao aprovar produto',
                ],
            ], 500);
        }
    }

    /**
     * Reject a showcase product
     */
    public function reject(Request $request, string $productId): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $product = Product::find($productId);

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'product_not_found',
                        'message' => 'Produto não encontrado',
                    ],
                ], 404);
            }

            DB::beginTransaction();

            $product->forceFill([
                'showcase_status' => 'rejected',
                'showcase_visibility' => 'private',
                'showcase_rejection_reason' => $request->input('reason'),
                'showcase_reviewed_at' => now(),
                'showcase_reviewed_by' => $request->user()->id,
            ])->save();

            DB::commit();

            Log::info('Showcase product rejected', [
                'product_id' => $productId,
                'admin_id' => $request->user()->id,
                'reason' => $request->input('reason'),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Produto rejeitado',
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject showcase product', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'rejection_failed',
                    'message' => 'Erro ao rejeitar produto',
                ],
            ], 500);
        }
    }

    /**
     * Delete a showcase product
     */
    public function destroy(Request $request, string $productId): JsonResponse
    {
        try {
            $product = Product::find($productId);

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'product_not_found',
                        'message' => 'Produto não encontrado',
                    ],
                ], 404);
            }

            DB::beginTransaction();

            $product->delete();

            DB::commit();

            Log::info('Showcase product deleted', [
                'product_id' => $productId,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Produto removido com sucesso',
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete showcase product', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'deletion_failed',
                    'message' => 'Erro ao remover produto',
                ],
            ], 500);
        }
    }

    /**
     * Get showcase settings
     */
    public function getSettings(Request $request): JsonResponse
    {
        try {
            // Use cache/database setting, fallback to config
            $autoApprove = cache()->get('showcase_auto_approve', false);

            return response()->json([
                'success' => true,
                'data' => [
                    'auto_approve' => $autoApprove,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'data' => [
                    'auto_approve' => false,
                ],
            ]);
        }
    }

    /**
     * Update showcase settings (e.g., auto-approve)
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'auto_approve' => 'required|boolean',
            ]);

            $autoApprove = $request->boolean('auto_approve');
            
            // Store in cache permanently (can be replaced with DB setting)
            cache()->forever('showcase_auto_approve', $autoApprove);

            // If turning on auto-approve, approve all pending products
            if ($autoApprove) {
                $pendingCount = Product::where('is_showcase', true)
                    ->where('showcase_status', 'pending')
                    ->count();

                if ($pendingCount > 0) {
                    Product::where('is_showcase', true)
                        ->where('showcase_status', 'pending')
                        ->update([
                            'showcase_status' => 'approved',
                            'showcase_visibility' => 'public',
                            'showcase_approved_at' => now(),
                            'showcase_approved_by' => $request->user()->id,
                        ]);

                    Log::info('Auto-approve enabled: approved all pending products', [
                        'count' => $pendingCount,
                        'admin_id' => $request->user()->id,
                    ]);
                }
            }

            Log::info('Showcase settings updated', [
                'auto_approve' => $autoApprove,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => $autoApprove 
                        ? 'Aprovação automática ativada' 
                        : 'Aprovação automática desativada',
                    'auto_approve' => $autoApprove,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update showcase settings', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'settings_update_failed',
                    'message' => 'Erro ao atualizar configurações',
                ],
            ], 500);
        }
    }
}
