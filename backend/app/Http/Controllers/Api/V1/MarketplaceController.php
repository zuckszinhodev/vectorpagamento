<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketplaceController extends Controller
{
    /**
     * Fix image URL to use the current request's host
     */
    private function fixImageUrl(?string $imageUrl, Request $request): ?string
    {
        if ($imageUrl && str_contains($imageUrl, '/storage/')) {
            $baseUrl = $request->getSchemeAndHttpHost();
            $path = preg_replace('/^https?:\/\/[^\/]+/', '', $imageUrl);
            return $baseUrl . $path;
        }
        return $imageUrl;
    }

    /**
     * List products available for affiliation (marketplace/vitrine)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Product::where('affiliate_enabled', true)
                ->whereRaw('"is_active" = true')
                ->with(['account:id,name']);

            // Try to eager load category if table exists
            try {
                $query->with(['category:id,name']);
            } catch (\Throwable $e) {
                // categories table may not exist
            }

            // Exclude user's own products if authenticated (unless include_own is requested)
            try {
                $user = $request->user();
            } catch (\Throwable $e) {
                $user = null;
            }
            if ($user && !$request->boolean('include_own')) {
                $query->where('account_id', '!=', $user->account_id);
            }

            // Search filter
            if ($request->query('search')) {
                $search = addcslashes($request->query('search'), '%_\\');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Category filter
            if ($request->query('category_id')) {
                $query->where('category_id', $request->query('category_id'));
            }

            // Category name filter
            if ($request->query('category')) {
                $categoryName = $request->query('category');
                $query->where('category', 'like', "%{$categoryName}%");
            }

            // Type filter
            if ($request->query('type')) {
                $query->where('type', $request->query('type'));
            }

            // Price range
            if ($request->query('min_price')) {
                $query->where('price', '>=', (int) $request->query('min_price'));
            }
            if ($request->query('max_price')) {
                $query->where('price', '<=', (int) $request->query('max_price'));
            }

            // Commission filter
            if ($request->query('min_commission')) {
                $query->where('commission_percent', '>=', (float) $request->query('min_commission'));
            }

            // Sorting
            $sort = $request->query('sort', 'newest');
            switch ($sort) {
                case 'price_asc':
                    $query->orderBy('price', 'asc');
                    break;
                case 'price_desc':
                    $query->orderBy('price', 'desc');
                    break;
                case 'commission':
                    $query->orderBy('commission_percent', 'desc');
                    break;
                case 'popular':
                    $query->withCount('charges')->orderBy('charges_count', 'desc');
                    break;
                case 'newest':
                default:
                    $query->orderBy('created_at', 'desc');
                    break;
            }

            $perPage = min((int) $request->query('per_page', 20), 100);
            $products = $query->paginate($perPage);

            // Transform data for frontend
            $items = $products->getCollection()->map(function ($product) use ($request) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'price' => $product->price,
                    'currency' => $product->currency,
                    'type' => $product->type,
                    'image_url' => $this->fixImageUrl($product->images[0] ?? null, $request),
                    'images' => $product->images,
                    'seller' => [
                        'id' => $product->account?->id ?? $product->account_id,
                        'name' => $product->account?->name ?? 'Vendedor',
                        'verified' => true,
                    ],
                    'affiliate_commission_percent' => (float) $product->commission_percent,
                    'category' => $product->getRawOriginal('category') ?? 'Sem categoria',
                    'category_id' => $product->category_id,
                    'is_active' => $product->is_active,
                    'created_at' => $product->created_at->format('Y-m-d'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MarketplaceController::index error', [
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
     * Get single product details for marketplace
     */
    public function show(Request $request, string $productId): JsonResponse
    {
        try {
        $product = Product::where('id', $productId)
            ->where('affiliate_enabled', true)
            ->whereRaw('"is_active" = true')
            ->with(['account:id,name,email', 'category:id,name'])
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'product_not_found',
                    'message' => 'Produto não encontrado ou não disponível para afiliação',
                ],
            ], 404);
        }

        // Fix image URLs
        $images = array_map(fn($img) => $this->fixImageUrl($img, $request), $product->images ?? []);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'price' => $product->price,
                'currency' => $product->currency,
                'type' => $product->type,
                'images' => $images,
                'image_url' => $images[0] ?? null,
                'seller' => [
                    'id' => $product->account->id,
                    'name' => $product->account->name,
                    'verified' => true,
                ],
                'affiliate_commission_percent' => (float) $product->commission_percent,
                'category' => $product->category?->name ?? 'Sem categoria',
                'created_at' => $product->created_at->format('Y-m-d'),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MarketplaceController::show error', [
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
     * Request affiliation to a product
     */
    public function affiliate(Request $request, string $productId): JsonResponse
    {
        try {
        $user = $request->user();

        $product = Product::where('id', $productId)
            ->where('affiliate_enabled', true)
            ->whereRaw('"is_active" = true')
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'product_not_found',
                    'message' => 'Produto não encontrado ou não disponível para afiliação',
                ],
            ], 404);
        }

        // Cannot affiliate to your own product
        if ($product->account_id === $user->account_id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'self_affiliate',
                    'message' => 'Você não pode se afiliar ao seu próprio produto',
                ],
            ], 400);
        }

        // Check if already affiliated
        $existingAffiliate = Affiliate::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if ($existingAffiliate) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'already_affiliated',
                    'message' => 'Você já está afiliado a este produto',
                    'affiliate' => [
                        'code' => $existingAffiliate->code,
                        'status' => $existingAffiliate->status,
                        'affiliate_url' => url('/ref/' . $existingAffiliate->code),
                    ],
                ],
            ], 400);
        }

        // Generate unique affiliate code
        $code = strtoupper(Str::random(8));
        while (Affiliate::where('code', $code)->exists()) {
            $code = strtoupper(Str::random(8));
        }

        // Create affiliation
        $affiliate = Affiliate::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $product->account_id, // Owner of the product
            'user_id' => $user->id, // Affiliate user
            'product_id' => $productId,
            'code' => $code,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'commission_type' => 'percentage',
            'commission_value' => $product->commission_percent,
            'status' => 'active', // Auto-approve for now
            'metadata' => [
                'source' => 'marketplace',
                'affiliate_account_id' => $user->account_id,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'affiliate_id' => $affiliate->id,
                'product_id' => $productId,
                'product_name' => $product->name,
                'code' => $affiliate->code,
                'commission_percent' => (float) $affiliate->commission_value,
                'status' => $affiliate->status,
                'affiliate_url' => url('/ref/' . $affiliate->code),
                'message' => 'Afiliação realizada com sucesso!',
            ],
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MarketplaceController::affiliate error', [
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
     * List products the user is affiliated to
     */
    public function myAffiliations(Request $request): JsonResponse
    {
        try {
        $user = $request->user();

        $affiliations = Affiliate::where('user_id', $user->id)
            ->with(['product:id,name,slug,price,currency,images,commission_percent', 'product.account:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $affiliations->map(function ($affiliate) use ($request) {
            return [
                'id' => $affiliate->id,
                'code' => $affiliate->code,
                'status' => $affiliate->status,
                'commission_percent' => (float) $affiliate->commission_value,
                'affiliate_url' => url('/ref/' . $affiliate->code),
                'total_clicks' => $affiliate->total_clicks ?? 0,
                'total_sales' => $affiliate->total_sales ?? 0,
                'total_earned' => $affiliate->total_earned ?? 0,
                'product' => $affiliate->product ? [
                    'id' => $affiliate->product->id,
                    'name' => $affiliate->product->name,
                    'slug' => $affiliate->product->slug,
                    'price' => $affiliate->product->price,
                    'currency' => $affiliate->product->currency,
                    'image_url' => $this->fixImageUrl($affiliate->product->images[0] ?? null, $request),
                    'seller_name' => $affiliate->product->account->name ?? 'N/A',
                ] : null,
                'created_at' => $affiliate->created_at->format('Y-m-d'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MarketplaceController::myAffiliations error', [
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
     * Get marketplace categories
     */
    public function categories(): JsonResponse
    {
        try {
        $categories = Product::where('affiliate_enabled', true)
            ->whereRaw('"is_active" = true')
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->values()
            ->map(fn($cat) => ['id' => Str::slug($cat), 'name' => $cat]);

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MarketplaceController::categories error', [
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
     * Get marketplace statistics
     */
    public function stats(): JsonResponse
    {
        try {
            $totalProducts = Product::where('affiliate_enabled', true)
                ->whereRaw('"is_active" = true')
                ->count();
            
            // Count unique sellers (accounts with affiliate-enabled products)
            $totalSellers = Product::where('affiliate_enabled', true)
                ->whereRaw('"is_active" = true')
                ->distinct()
                ->count('account_id');
            
            // Average commission
            $avgCommission = Product::where('affiliate_enabled', true)
                ->whereRaw('"is_active" = true')
                ->avg('commission_percent') ?? 0;
            
            // Hot products (could be based on sales, for now just count recent ones)
            $hotProducts = Product::where('affiliate_enabled', true)
                ->whereRaw('"is_active" = true')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_products' => $totalProducts,
                    'total_sellers' => $totalSellers,
                    'avg_commission' => round((float) $avgCommission, 0),
                    'hot_products' => $hotProducts,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MarketplaceController::stats error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_products' => 0,
                    'total_sellers' => 0,
                    'avg_commission' => 0,
                    'hot_products' => 0,
                ],
            ]);
        }
    }
}
