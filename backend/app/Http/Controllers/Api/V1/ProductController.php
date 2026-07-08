<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Models\Course;
use App\Models\CustomDomain;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
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
     * Get the checkout base URL for the account (using custom domain if available)
     */
    private function getCheckoutBaseUrl(string $accountId, Request $request): string
    {
        try {
            $customDomain = CustomDomain::where('account_id', $accountId)
                ->where('status', 'verified')
                ->where('is_primary', true)
                ->first();

            if ($customDomain) {
                $protocol = $customDomain->ssl_enabled ? 'https' : 'http';
                return "{$protocol}://{$customDomain->domain}";
            }
        } catch (\Throwable $e) {
            // custom_domains table may not exist yet
        }

        $frontendUrl = config('app.frontend_url');
        if ($frontendUrl) {
            return rtrim($frontendUrl, '/');
        }

        return $request->getSchemeAndHttpHost();
    }

    private function normalizePaymentMethods(mixed $methods): array
    {
        $allowed = ['credit_card', 'pix', 'boleto'];

        if (!is_array($methods)) {
            return ['pix'];
        }

        $selected = array_is_list($methods)
            ? $methods
            : array_keys(array_filter($methods, fn ($enabled) => filter_var($enabled, FILTER_VALIDATE_BOOLEAN)));

        $selected = array_values(array_intersect($allowed, $selected));

        return empty($selected) ? ['pix'] : $selected;
    }

    /**
     * List products for authenticated account
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $query = Product::where('account_id', $accountId);

            if ($request->query('status') === 'active') {
                $query->whereRaw('"is_active" = true');
            } elseif ($request->query('status') === 'inactive') {
                $query->whereRaw('"is_active" = false');
            }

            if ($request->query('category_id')) {
                $query->where('category_id', $request->query('category_id'));
            }

            if ($request->query('type')) {
                $query->where('type', $request->query('type'));
            }

            if ($request->query('search')) {
                $search = addcslashes($request->query('search'), '%_\\');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $perPage = min((int) $request->query('per_page', 20), 100);
            $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Get checkout base URL for this account
            $checkoutBaseUrl = $this->getCheckoutBaseUrl($accountId, $request);

            // Add image_url and checkout_url to each product with proper host
            $productsData = collect($products->items())->map(function ($product) use ($request, $checkoutBaseUrl) {
                $data = $product->toArray();
                $images = is_array($product->images) ? $product->images : [];
                $data['image_url'] = $this->fixImageUrl($product->image_url ?? ($images[0] ?? null), $request);
                
                // Get checkout for this product
                try {
                    $checkout = Checkout::where('product_id', $product->id)->first();
                } catch (\Throwable $e) {
                    $checkout = null;
                }
                if ($checkout) {
                    $data['checkout_slug'] = $checkout->slug;
                    $data['checkout_url'] = "{$checkoutBaseUrl}/checkout/{$checkout->slug}";
                } else {
                    $data['checkout_slug'] = null;
                    $data['checkout_url'] = null;
                }
                
                return $data;
            })->all();

            return response()->json([
                'success' => true,
                'data' => $productsData,
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProductController::index error', [
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
     * Create a new product
     */
    public function store(Request $request): JsonResponse
    {
        try {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:digital,physical,service'],
            'price' => ['required', 'integer', 'min:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'checkout_id' => ['nullable', 'uuid', 'exists:checkouts,id'],
            'affiliate_enabled' => ['boolean'],
            'affiliate_commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_enabled' => ['boolean'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'images' => ['nullable', 'array'],
            'image_url' => ['nullable', 'string'],
        ];

        // Category is required when affiliate is enabled
        if ($request->input('affiliate_enabled')) {
            $rules['category'] = ['required', 'string', 'max:100'];
        }

        $request->validate($rules, [
            'category.required' => 'Categoria é obrigatória quando o programa de afiliados está ativo',
        ]);

        $accountId = $request->user()->account_id;

        $baseSlug = Str::slug($request->input('name'));
        if (empty($baseSlug)) {
            $baseSlug = 'produto';
        }
        // Append short random code so same-name products get different links
        $slug = $baseSlug . '-' . Str::lower(Str::random(6));
        // Check including soft-deleted rows (DB unique constraint covers them)
        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . Str::lower(Str::random(6));
        }

        // Handle image_url - keep both image_url column and images array in sync
        $imageUrl = $request->input('image_url');
        $images = $request->input('images', []);
        if ($imageUrl) {
            $images = [$imageUrl];
        } elseif (!empty($images)) {
            $imageUrl = $images[0];
        }

        $metadata = $request->input('metadata', []);
        $paymentMethods = $this->normalizePaymentMethods(
            $request->input('payment_methods', $metadata['payment_methods'] ?? ['pix' => true])
        );

        $product = Product::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $accountId,
            'name' => $request->input('name'),
            'slug' => $slug,
            'description' => $request->input('description'),
            'type' => $request->input('type'),
            'price' => $request->input('price'),
            'currency' => 'BRL',
            'category' => $request->input('category'),
            'category_id' => $request->input('category_id'),
            'checkout_id' => $request->input('checkout_id'),
            'affiliate_enabled' => $request->input('affiliate_enabled', false),
            'commission_percent' => $request->input('affiliate_commission_percent', $request->input('commission_percent', 0)),
            'commission_fixed' => $request->input('commission_fixed', 0),
            'stock_enabled' => $request->input('stock_enabled', false),
            'stock_quantity' => $request->input('stock_quantity'),
            'images' => $images,
            'image_url' => $imageUrl,
            'metadata' => $metadata,
            'is_active' => true,
        ]);

        // Auto-create checkout for the product if not provided
        $checkout = null;
        if (!$request->input('checkout_id')) {
            $checkoutSlug = $slug;
            // Check including soft-deleted rows (DB unique constraint covers them)
            while (Checkout::withTrashed()->where('slug', $checkoutSlug)->exists()) {
                $checkoutSlug = $baseSlug . '-' . Str::lower(Str::random(6));
            }
            
            $checkout = Checkout::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $accountId,
                'product_id' => $product->id,
                'name' => $product->name,
                'slug' => $checkoutSlug,
                'type' => 'redirect',
                'status' => 'active',
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
                'settings' => [
                    'theme_color' => '#10b981',
                    'collect_cpf' => true,
                    'collect_address' => false,
                    'collect_phone' => true,
                    'payment_methods' => $paymentMethods,
                    'max_installments' => 12,
                    'exit_popup_enabled' => false,
                    'timer_enabled' => false,
                    'timer_minutes' => 15,
                    'testimonials_enabled' => true,
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
                ],
            ]);
            
            // Update product with checkout_id
            $product->update(['checkout_id' => $checkout->id]);
        } else {
            $checkout = Checkout::find($request->input('checkout_id'));
        }

        // Link course if member_area_enabled is true
        if ($request->input('member_area_enabled') && $request->input('course_id')) {
            Course::where('id', $request->input('course_id'))
                ->where('account_id', $accountId)
                ->update(['product_id' => $product->id]);
        }

        // Add image_url and checkout_url to response
        $productData = $product->toArray();
        $productData['image_url'] = $this->fixImageUrl($product->image_url ?? $product->images[0] ?? null, $request);
        
        if ($checkout) {
            $checkoutBaseUrl = $this->getCheckoutBaseUrl($accountId, $request);
            $productData['checkout_slug'] = $checkout->slug;
            $productData['checkout_url'] = "{$checkoutBaseUrl}/checkout/{$checkout->slug}";
        }

        return response()->json([
            'success' => true,
            'data' => $productData,
        ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProductController::store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro ao criar produto.'],
            ], 500);
        }
    }

    /**
     * Show a specific product
     */
    public function show(Request $request, string $productId): JsonResponse
    {
        try {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $productId)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'product_not_found', 'message' => 'Produto não encontrado'],
            ], 404);
        }

        $accountId = $request->user()->account_id;

        $product = Product::where('id', $productId)
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

        $productData = $product->toArray();
        $productData['image_url'] = $this->fixImageUrl($product->image_url ?? $product->images[0] ?? null, $request);
        
        // Add checkout_url
        $checkout = Checkout::where('product_id', $product->id)->first();
        if ($checkout) {
            $checkoutBaseUrl = $this->getCheckoutBaseUrl($accountId, $request);
            $productData['checkout_slug'] = $checkout->slug;
            $productData['checkout_url'] = "{$checkoutBaseUrl}/checkout/{$checkout->slug}";
        }

        // Add member area info
        $course = Course::where('product_id', $product->id)->first();
        $productData['member_area_enabled'] = $course !== null;
        $productData['course_id'] = $course?->id;

        return response()->json([
            'success' => true,
            'data' => $productData,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProductController::show error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro ao buscar produto.'],
            ], 500);
        }
    }

    /**
     * Update a product
     */
    public function update(Request $request, string $productId): JsonResponse
    {
        try {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $productId)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'product_not_found', 'message' => 'Produto não encontrado'],
            ], 404);
        }

        $accountId = $request->user()->account_id;

        $product = Product::where('id', $productId)
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

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'in:digital,physical,service'],
            'price' => ['sometimes', 'integer', 'min:100'],
            'original_price' => ['nullable', 'integer', 'min:0'],
            'category' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'checkout_id' => ['nullable', 'uuid', 'exists:checkouts,id'],
            'affiliate_enabled' => ['sometimes', 'boolean'],
            'affiliate_commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_enabled' => ['sometimes', 'boolean'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'images' => ['nullable', 'array'],
            'image_url' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        // Category is required when affiliate is being enabled (only enforce if category is not already set)
        $affiliateEnabled = $request->has('affiliate_enabled') 
            ? $request->input('affiliate_enabled') 
            : $product->affiliate_enabled;
        
        if ($affiliateEnabled && $request->has('category')) {
            $rules['category'] = ['required', 'string', 'max:100'];
        } elseif ($affiliateEnabled && !$product->category) {
            $rules['category'] = ['required', 'string', 'max:100'];
        }

        $request->validate($rules, [
            'category.required' => 'Categoria é obrigatória quando o programa de afiliados está ativo',
        ]);

        $updateData = $request->only([
            'name', 'description', 'type', 'price', 'original_price', 'category', 'category_id', 'checkout_id',
            'affiliate_enabled', 'commission_fixed',
            'stock_enabled', 'stock_quantity', 'images', 'image_url', 'metadata', 'is_active',
        ]);

        // Handle image_url - also update images array for backwards compatibility
        if ($request->has('image_url')) {
            $imageUrl = $request->input('image_url');
            $updateData['image_url'] = $imageUrl;
            if ($imageUrl) {
                $updateData['images'] = [$imageUrl];
            } else {
                $updateData['images'] = [];
            }
        }

        // Map affiliate_commission_percent to commission_percent
        if ($request->has('affiliate_commission_percent')) {
            $updateData['commission_percent'] = $request->input('affiliate_commission_percent');
        } elseif ($request->has('commission_percent')) {
            $updateData['commission_percent'] = $request->input('commission_percent');
        }

        if (isset($updateData['name']) && $updateData['name'] !== $product->name) {
            $updBaseSlug = Str::slug($updateData['name']);
            if (empty($updBaseSlug)) {
                $updBaseSlug = 'produto';
            }
            $updSlug = $updBaseSlug . '-' . Str::lower(Str::random(6));
            while (Product::withTrashed()->where('slug', $updSlug)->where('id', '!=', $productId)->exists()) {
                $updSlug = $updBaseSlug . '-' . Str::lower(Str::random(6));
            }
            $updateData['slug'] = $updSlug;
        }

        $product->update(array_filter($updateData, fn($v) => $v !== null));

        $freshProduct = $product->fresh();
        $productData = $freshProduct->toArray();
        $productData['image_url'] = $this->fixImageUrl($freshProduct->image_url ?? $freshProduct->images[0] ?? null, $request);
        
        // Add checkout_url
        $checkout = Checkout::where('product_id', $freshProduct->id)->first();
        if ($checkout) {
            $metadata = is_array($freshProduct->metadata) ? $freshProduct->metadata : [];
            if ($request->has('payment_methods') || isset($metadata['payment_methods'])) {
                $settings = is_array($checkout->settings) ? $checkout->settings : [];
                $settings['payment_methods'] = $this->normalizePaymentMethods(
                    $request->input('payment_methods', $metadata['payment_methods'] ?? ['pix' => true])
                );
                $checkout->update(['settings' => $settings]);
            }

            $checkoutBaseUrl = $this->getCheckoutBaseUrl($accountId, $request);
            $productData['checkout_slug'] = $checkout->slug;
            $productData['checkout_url'] = "{$checkoutBaseUrl}/checkout/{$checkout->slug}";
        }

        // Link/unlink course for member area
        if ($request->has('member_area_enabled')) {
            if ($request->input('member_area_enabled') && $request->input('course_id')) {
                // Link the course to this product
                Course::where('id', $request->input('course_id'))
                    ->where('account_id', $accountId)
                    ->update(['product_id' => $freshProduct->id]);
            } elseif (!$request->input('member_area_enabled')) {
                // Unlink any course from this product
                Course::where('product_id', $freshProduct->id)
                    ->where('account_id', $accountId)
                    ->update(['product_id' => null]);
            }
        }

        // Add member area info to response
        $course = Course::where('product_id', $freshProduct->id)->first();
        $productData['member_area_enabled'] = $course !== null;
        $productData['course_id'] = $course?->id;

        return response()->json([
            'success' => true,
            'data' => $productData,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProductController::update error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro ao atualizar produto.'],
            ], 500);
        }
    }

    /**
     * Delete a product (soft delete)
     */
    public function destroy(Request $request, string $productId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $product = Product::where('id', $productId)
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

        // Free up slugs before soft-deleting so they can be reused
        $deletedSuffix = '_deleted_' . now()->timestamp;
        Checkout::where('product_id', $productId)
            ->where('account_id', $accountId)
            ->get()
            ->each(function ($checkout) use ($deletedSuffix) {
                $checkout->update(['slug' => $checkout->slug . $deletedSuffix]);
                $checkout->delete();
            });

        $product->update(['slug' => $product->slug . $deletedSuffix]);
        $product->delete();

        return response()->json([
            'success' => true,
            'data' => ['message' => 'Produto e checkouts vinculados removidos com sucesso'],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProductController::destroy error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro ao remover produto.'],
            ], 500);
        }
    }

    /**
     * Get product statistics
     */
    public function stats(Request $request, string $productId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $product = Product::where('id', $productId)
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

        $charges = \App\Models\Charge::where('product_id', $productId);

        $totalSales = $charges->clone()->where('status', 'approved')->count();
        $totalRevenue = $charges->clone()->where('status', 'approved')->sum('amount');
        $pendingCount = $charges->clone()->where('status', 'pending')->count();
        $refundedCount = $charges->clone()->where('status', 'refunded')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_sales' => $totalSales,
                'total_revenue' => $totalRevenue,
                'pending_count' => $pendingCount,
                'refunded_count' => $refundedCount,
                'conversion_rate' => $charges->count() > 0 ? round(($totalSales / $charges->count()) * 100, 2) : 0,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProductController::stats error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro ao buscar estatísticas.'],
            ], 500);
        }
    }
}
