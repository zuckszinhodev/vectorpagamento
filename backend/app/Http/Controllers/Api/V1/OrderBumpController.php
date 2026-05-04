<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Models\OrderBump;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderBumpController extends Controller
{
    /**
     * List order bumps for a checkout
     */
    public function index(Request $request, string $checkoutId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $checkout = Checkout::where('id', $checkoutId)
                ->where('account_id', $accountId)
                ->first();

            if (!$checkout) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'checkout_not_found', 'message' => 'Checkout não encontrado'],
                ], 404);
            }

            $bumps = OrderBump::where('checkout_id', $checkoutId)
                ->with('product:id,name,price,image_url,images')
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $bumps->map(fn ($b) => $this->formatBump($b)),
            ]);
        } catch (\Throwable $e) {
            Log::error('OrderBumpController::index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => []]);
        }
    }

    /**
     * Create a new order bump
     */
    public function store(Request $request, string $checkoutId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $checkout = Checkout::where('id', $checkoutId)
                ->where('account_id', $accountId)
                ->first();

            if (!$checkout) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'checkout_not_found', 'message' => 'Checkout não encontrado'],
                ], 404);
            }

            $request->validate([
                'product_id' => ['required', 'uuid'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'call_to_action' => ['nullable', 'string', 'max:255'],
                'image_url' => ['nullable', 'string', 'max:500'],
                'price' => ['required', 'integer', 'min:1'],
                'original_price' => ['nullable', 'integer', 'min:1'],
                'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
                'display_position' => ['nullable', 'in:before_payment,after_product,sidebar'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
                'is_pre_selected' => ['nullable', 'boolean'],
                'settings' => ['nullable', 'array'],
            ]);

            // Validate product ownership
            $product = Product::where('id', $request->input('product_id'))
                ->where('account_id', $accountId)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'product_not_found', 'message' => 'Produto não encontrado'],
                ], 404);
            }

            $maxSort = OrderBump::where('checkout_id', $checkoutId)->max('sort_order') ?? -1;

            $bump = OrderBump::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $accountId,
                'checkout_id' => $checkoutId,
                'product_id' => $product->id,
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'call_to_action' => $request->input('call_to_action', 'Sim, eu quero!'),
                'image_url' => $request->input('image_url'),
                'price' => $request->input('price'),
                'original_price' => $request->input('original_price'),
                'discount_percent' => $request->input('discount_percent'),
                'display_position' => $request->input('display_position', 'before_payment'),
                'sort_order' => $request->input('sort_order', $maxSort + 1),
                'is_active' => $request->input('is_active', true),
                'is_pre_selected' => $request->input('is_pre_selected', false),
                'settings' => $request->input('settings', []),
            ]);

            $bump->load('product:id,name,price,image_url,images');

            return response()->json([
                'success' => true,
                'data' => $this->formatBump($bump),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('OrderBumpController::store failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao criar order bump']], 500);
        }
    }

    /**
     * Get a specific order bump
     */
    public function show(Request $request, string $checkoutId, string $bumpId): JsonResponse
    {
        try {
            $bump = $this->findBump($request, $checkoutId, $bumpId);
            if (!$bump) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'not_found', 'message' => 'Order bump não encontrado'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatBump($bump),
            ]);
        } catch (\Throwable $e) {
            Log::error('OrderBumpController::show failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao buscar order bump']], 500);
        }
    }

    /**
     * Update an order bump
     */
    public function update(Request $request, string $checkoutId, string $bumpId): JsonResponse
    {
        try {
            $bump = $this->findBump($request, $checkoutId, $bumpId);
            if (!$bump) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'not_found', 'message' => 'Order bump não encontrado'],
                ], 404);
            }

            $request->validate([
                'product_id' => ['sometimes', 'uuid'],
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'call_to_action' => ['nullable', 'string', 'max:255'],
                'image_url' => ['nullable', 'string', 'max:500'],
                'price' => ['sometimes', 'integer', 'min:1'],
                'original_price' => ['nullable', 'integer', 'min:1'],
                'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
                'display_position' => ['nullable', 'in:before_payment,after_product,sidebar'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
                'is_pre_selected' => ['nullable', 'boolean'],
                'settings' => ['nullable', 'array'],
            ]);

            // Validate new product if changing
            if ($request->has('product_id') && $request->input('product_id') !== $bump->product_id) {
                $product = Product::where('id', $request->input('product_id'))
                    ->where('account_id', $request->user()->account_id)
                    ->first();
                if (!$product) {
                    return response()->json([
                        'success' => false,
                        'error' => ['code' => 'product_not_found', 'message' => 'Produto não encontrado'],
                    ], 404);
                }
            }

            $updateData = $request->only([
                'product_id', 'title', 'description', 'call_to_action', 'image_url',
                'price', 'original_price', 'discount_percent', 'display_position',
                'sort_order', 'is_active', 'is_pre_selected', 'settings',
            ]);

            $bump->update(array_filter($updateData, fn ($v) => $v !== null));
            $bump->load('product:id,name,price,image_url,images');

            return response()->json([
                'success' => true,
                'data' => $this->formatBump($bump->fresh()),
            ]);
        } catch (\Throwable $e) {
            Log::error('OrderBumpController::update failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao atualizar order bump']], 500);
        }
    }

    /**
     * Delete an order bump
     */
    public function destroy(Request $request, string $checkoutId, string $bumpId): JsonResponse
    {
        try {
            $bump = $this->findBump($request, $checkoutId, $bumpId);
            if (!$bump) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'not_found', 'message' => 'Order bump não encontrado'],
                ], 404);
            }

            $bump->delete();

            return response()->json(['success' => true, 'message' => 'Order bump removido']);
        } catch (\Throwable $e) {
            Log::error('OrderBumpController::destroy failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao remover order bump']], 500);
        }
    }

    /**
     * Reorder bumps
     */
    public function reorder(Request $request, string $checkoutId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $checkout = Checkout::where('id', $checkoutId)
                ->where('account_id', $accountId)
                ->first();

            if (!$checkout) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'checkout_not_found', 'message' => 'Checkout não encontrado'],
                ], 404);
            }

            $request->validate([
                'order' => ['required', 'array'],
                'order.*' => ['uuid'],
            ]);

            foreach ($request->input('order') as $index => $bumpId) {
                OrderBump::where('id', $bumpId)
                    ->where('checkout_id', $checkoutId)
                    ->update(['sort_order' => $index]);
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('OrderBumpController::reorder failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao reordenar order bumps']], 500);
        }
    }

    private function findBump(Request $request, string $checkoutId, string $bumpId): ?OrderBump
    {
        return OrderBump::where('id', $bumpId)
            ->where('checkout_id', $checkoutId)
            ->whereHas('checkout', fn ($q) => $q->where('account_id', $request->user()->account_id))
            ->with('product:id,name,price,image_url,images')
            ->first();
    }

    private function formatBump(OrderBump $bump): array
    {
        $data = $bump->toArray();
        $data['conversion_rate'] = $bump->conversion_rate;
        if ($bump->product) {
            $data['product'] = [
                'id' => $bump->product->id,
                'name' => $bump->product->name,
                'price' => $bump->product->price,
                'image_url' => $bump->product->image_url ?? ($bump->product->images[0] ?? null),
            ];
        }
        return $data;
    }
}
