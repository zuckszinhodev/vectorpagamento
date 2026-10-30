<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Checkout;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        $accountId = $request->input('account_id');
        $scoped = fn () => Product::query()->when($accountId, fn ($q) => $q->where('account_id', $accountId));
        $query = $scoped()->with(['account.users']);

        match ($request->input('status')) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false)->whereNull('admin_blocked_at'),
            'blocked' => $query->whereNotNull('admin_blocked_at'),
            'showcase_pending' => $query->where('is_showcase', true)->where('showcase_status', 'pending'),
            default => null,
        };

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $search = '%' . mb_strtolower(addcslashes((string) $request->input('search'), '%_\\')) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', [$search])
                    ->orWhereHas('account.users', function ($users) use ($search) {
                        $users->whereRaw('LOWER(name) LIKE ?', [$search])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$search]);
                    });
            });
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);
        $sales = $this->salesByProduct($products->getCollection()->pluck('id'));

        return response()->json([
            'success' => true,
            'data' => $products->getCollection()->map(fn (Product $product) => $this->format($product, $sales)),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
            'stats' => [
                'total' => $scoped()->count(),
                'active' => $scoped()->where('is_active', true)->count(),
                'inactive' => $scoped()->where('is_active', false)->whereNull('admin_blocked_at')->count(),
                'blocked' => $scoped()->whereNotNull('admin_blocked_at')->count(),
                'showcase_pending' => $scoped()->where('is_showcase', true)->where('showcase_status', 'pending')->count(),
            ],
            'sellers' => $this->sellersWithProducts(),
        ]);
    }

    private function sellersWithProducts(): Collection
    {
        return Product::with(['account.users'])
            ->get(['id', 'account_id'])
            ->groupBy('account_id')
            ->map(function (Collection $products, string $accountId) {
                $account = $products->first()->account;

                return [
                    'account_id' => $accountId,
                    'name' => $account?->users?->first()?->name ?? $account?->name ?? 'Sem nome',
                    'email' => $account?->users?->first()?->email ?? $account?->email,
                    'products' => $products->count(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function show(string $productId): JsonResponse
    {
        $product = Product::with(['account.users'])->find($productId);

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Produto não encontrado'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($this->format($product, $this->salesByProduct(collect([$product->id]))), [
                'description' => $product->description,
                'original_price' => $product->original_price,
                'category' => $product->getAttribute('category'),
                'stock_enabled' => (bool) $product->stock_enabled,
                'stock_quantity' => $product->stock_quantity,
                'affiliate_enabled' => (bool) $product->affiliate_enabled,
                'commission_percent' => (float) ($product->commission_percent ?? 0),
                'checkouts' => Checkout::where('product_id', $product->id)->get(['id', 'name', 'slug'])->values(),
                'recent_charges' => Charge::where('product_id', $product->id)
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'amount', 'status', 'payment_method', 'created_at']),
            ]),
        ]);
    }

    public function update(Request $request, string $productId): JsonResponse
    {
        $product = Product::find($productId);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Produto não encontrado'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:digital,physical,service'],
            'price' => ['required', 'integer', 'min:100'],
            'original_price' => ['nullable', 'integer', 'min:0'],
            'category' => ['nullable', 'required_if:affiliate_enabled,true', 'string', 'max:100'],
            'stock_enabled' => ['required', 'boolean'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'affiliate_enabled' => ['required', 'boolean'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'category.required_if' => 'Categoria é obrigatória quando o programa de afiliados está ativo',
        ]);

        $changes = array_diff_assoc(
            array_map(fn ($value) => is_bool($value) ? (int) $value : $value, $validated),
            array_map(fn ($value) => is_bool($value) ? (int) $value : $value, $product->only(array_keys($validated)))
        );

        // O slug não muda: é o endereço que o seller já divulgou
        $product->update($validated);

        Log::info('Product edited by admin', [
            'product_id' => $product->id,
            'admin_id' => $request->user()->id,
            'changed_fields' => array_keys($changes),
        ]);

        return $this->show($product->id);
    }

    public function block(Request $request, string $productId): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $product = Product::find($productId);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Produto não encontrado'], 404);
        }

        $product->forceFill([
            'admin_blocked_at' => now(),
            'admin_block_reason' => $validated['reason'],
            'is_active' => false,
        ])->save();

        Log::info('Product blocked by admin', [
            'product_id' => $product->id,
            'admin_id' => $request->user()->id,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['success' => true, 'message' => 'Produto bloqueado']);
    }

    public function unblock(Request $request, string $productId): JsonResponse
    {
        $product = Product::find($productId);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Produto não encontrado'], 404);
        }

        $product->forceFill([
            'admin_blocked_at' => null,
            'admin_block_reason' => null,
            'is_active' => true,
        ])->save();

        Log::info('Product unblocked by admin', [
            'product_id' => $product->id,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'message' => 'Produto desbloqueado']);
    }

    public function destroy(Request $request, string $productId): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $product = Product::find($productId);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Produto não encontrado'], 404);
        }

        // Mesma remoção do seller: libera os slugs e leva junto os checkouts do produto
        $deletedSuffix = '_deleted_' . now()->timestamp;
        Checkout::where('product_id', $product->id)->get()->each(function (Checkout $checkout) use ($deletedSuffix) {
            $checkout->update(['slug' => $checkout->slug . $deletedSuffix]);
            $checkout->delete();
        });

        $product->update(['slug' => $product->slug . $deletedSuffix]);
        $product->delete();

        Log::info('Product deleted by admin', [
            'product_id' => $product->id,
            'admin_id' => $request->user()->id,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['success' => true, 'message' => 'Produto excluído']);
    }

    private function salesByProduct(Collection $productIds): Collection
    {
        return Charge::whereIn('product_id', $productIds)
            ->selectRaw('product_id, status, COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('product_id', 'status')
            ->get()
            ->groupBy('product_id');
    }

    private function format(Product $product, Collection $sales): array
    {
        $owner = $product->account?->users?->first();
        $rows = $sales->get($product->id, collect());
        $approved = $rows->firstWhere('status', 'approved');

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'type' => $product->type,
            'price' => $product->price,
            'image_url' => $product->image_url,
            'is_active' => (bool) $product->is_active,
            'blocked_at' => $product->admin_blocked_at?->toIso8601String(),
            'block_reason' => $product->admin_block_reason,
            'is_showcase' => (bool) $product->is_showcase,
            'showcase_status' => $product->showcase_status,
            'seller_id' => $owner?->id,
            'seller_name' => $owner?->name ?? $product->account?->name,
            'seller_email' => $owner?->email ?? $product->account?->email,
            'sales_count' => (int) ($approved->total ?? 0),
            'sales_amount' => (int) ($approved->amount ?? 0),
            'chargebacks' => (int) ($rows->firstWhere('status', 'chargeback')->total ?? 0),
            'created_at' => $product->created_at?->toIso8601String(),
        ];
    }
}
