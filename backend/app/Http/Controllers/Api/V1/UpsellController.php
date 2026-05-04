<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Models\Upsell;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UpsellController extends Controller
{
    /**
     * List upsells for a checkout
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

            $upsells = Upsell::where('checkout_id', $checkoutId)
                ->with('product:id,name,price,image_url,images')
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $upsells->map(fn ($u) => $this->formatUpsell($u)),
            ]);
        } catch (\Throwable $e) {
            Log::error('UpsellController::index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => []]);
        }
    }

    /**
     * Create a new upsell
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
                'headline' => ['nullable', 'string', 'max:500'],
                'call_to_action' => ['nullable', 'string', 'max:255'],
                'decline_text' => ['nullable', 'string', 'max:255'],
                'image_url' => ['nullable', 'string', 'max:500'],
                'video_url' => ['nullable', 'string', 'max:500'],
                'price' => ['required', 'integer', 'min:1'],
                'original_price' => ['nullable', 'integer', 'min:1'],
                'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
                'type' => ['nullable', 'in:upsell,downsell'],
                'next_upsell_id' => ['nullable', 'uuid'],
                'downsell_id' => ['nullable', 'uuid'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
                'one_click_purchase' => ['nullable', 'boolean'],
                'timer_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
                'settings' => ['nullable', 'array'],
                'benefits' => ['nullable', 'array'],
                'benefits.*' => ['string', 'max:500'],
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

            // Validate chain references
            if ($request->input('next_upsell_id')) {
                $exists = Upsell::where('id', $request->input('next_upsell_id'))
                    ->where('checkout_id', $checkoutId)
                    ->exists();
                if (!$exists) {
                    return response()->json([
                        'success' => false,
                        'error' => ['code' => 'invalid_reference', 'message' => 'Upsell referenciado não encontrado'],
                    ], 422);
                }
            }

            if ($request->input('downsell_id')) {
                $exists = Upsell::where('id', $request->input('downsell_id'))
                    ->where('checkout_id', $checkoutId)
                    ->exists();
                if (!$exists) {
                    return response()->json([
                        'success' => false,
                        'error' => ['code' => 'invalid_reference', 'message' => 'Downsell referenciado não encontrado'],
                    ], 422);
                }
            }

            $maxSort = Upsell::where('checkout_id', $checkoutId)->max('sort_order') ?? -1;

            $upsell = Upsell::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $accountId,
                'checkout_id' => $checkoutId,
                'product_id' => $product->id,
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'headline' => $request->input('headline'),
                'call_to_action' => $request->input('call_to_action', 'Aproveitar oferta'),
                'decline_text' => $request->input('decline_text', 'Não, obrigado'),
                'image_url' => $request->input('image_url'),
                'video_url' => $request->input('video_url'),
                'price' => $request->input('price'),
                'original_price' => $request->input('original_price'),
                'discount_percent' => $request->input('discount_percent'),
                'type' => $request->input('type', 'upsell'),
                'next_upsell_id' => $request->input('next_upsell_id'),
                'downsell_id' => $request->input('downsell_id'),
                'sort_order' => $request->input('sort_order', $maxSort + 1),
                'is_active' => $request->input('is_active', true),
                'one_click_purchase' => $request->input('one_click_purchase', true),
                'timer_seconds' => $request->input('timer_seconds'),
                'settings' => $request->input('settings', []),
                'benefits' => $request->input('benefits', []),
            ]);

            $upsell->load('product:id,name,price,image_url,images');

            return response()->json([
                'success' => true,
                'data' => $this->formatUpsell($upsell),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('UpsellController::store failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao criar upsell']], 500);
        }
    }

    /**
     * Get a specific upsell
     */
    public function show(Request $request, string $checkoutId, string $upsellId): JsonResponse
    {
        try {
            $upsell = $this->findUpsell($request, $checkoutId, $upsellId);
            if (!$upsell) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'not_found', 'message' => 'Upsell não encontrado'],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatUpsell($upsell),
            ]);
        } catch (\Throwable $e) {
            Log::error('UpsellController::show failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao buscar upsell']], 500);
        }
    }

    /**
     * Update an upsell
     */
    public function update(Request $request, string $checkoutId, string $upsellId): JsonResponse
    {
        try {
            $upsell = $this->findUpsell($request, $checkoutId, $upsellId);
            if (!$upsell) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'not_found', 'message' => 'Upsell não encontrado'],
                ], 404);
            }

            $request->validate([
                'product_id' => ['sometimes', 'uuid'],
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'headline' => ['nullable', 'string', 'max:500'],
                'call_to_action' => ['nullable', 'string', 'max:255'],
                'decline_text' => ['nullable', 'string', 'max:255'],
                'image_url' => ['nullable', 'string', 'max:500'],
                'video_url' => ['nullable', 'string', 'max:500'],
                'price' => ['sometimes', 'integer', 'min:1'],
                'original_price' => ['nullable', 'integer', 'min:1'],
                'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
                'type' => ['nullable', 'in:upsell,downsell'],
                'next_upsell_id' => ['nullable', 'uuid'],
                'downsell_id' => ['nullable', 'uuid'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
                'one_click_purchase' => ['nullable', 'boolean'],
                'timer_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
                'settings' => ['nullable', 'array'],
                'benefits' => ['nullable', 'array'],
                'benefits.*' => ['string', 'max:500'],
            ]);

            // Validate new product if changing
            if ($request->has('product_id') && $request->input('product_id') !== $upsell->product_id) {
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
                'product_id', 'title', 'description', 'headline', 'call_to_action', 'decline_text',
                'image_url', 'video_url', 'price', 'original_price', 'discount_percent',
                'type', 'next_upsell_id', 'downsell_id', 'sort_order', 'is_active',
                'one_click_purchase', 'timer_seconds', 'settings', 'benefits',
            ]);

            $upsell->update(array_filter($updateData, fn ($v) => $v !== null));
            $upsell->load('product:id,name,price,image_url,images');

            return response()->json([
                'success' => true,
                'data' => $this->formatUpsell($upsell->fresh()),
            ]);
        } catch (\Throwable $e) {
            Log::error('UpsellController::update failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao atualizar upsell']], 500);
        }
    }

    /**
     * Delete an upsell
     */
    public function destroy(Request $request, string $checkoutId, string $upsellId): JsonResponse
    {
        try {
            $upsell = $this->findUpsell($request, $checkoutId, $upsellId);
            if (!$upsell) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'not_found', 'message' => 'Upsell não encontrado'],
                ], 404);
            }

            // Clear references from other upsells pointing to this one
            Upsell::where('checkout_id', $checkoutId)
                ->where('next_upsell_id', $upsellId)
                ->update(['next_upsell_id' => null]);

            Upsell::where('checkout_id', $checkoutId)
                ->where('downsell_id', $upsellId)
                ->update(['downsell_id' => null]);

            $upsell->delete();

            return response()->json(['success' => true, 'message' => 'Upsell removido']);
        } catch (\Throwable $e) {
            Log::error('UpsellController::destroy failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao remover upsell']], 500);
        }
    }

    /**
     * Reorder upsells
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

            foreach ($request->input('order') as $index => $upsellId) {
                Upsell::where('id', $upsellId)
                    ->where('checkout_id', $checkoutId)
                    ->update(['sort_order' => $index]);
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('UpsellController::reorder failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao reordenar upsells']], 500);
        }
    }

    private function findUpsell(Request $request, string $checkoutId, string $upsellId): ?Upsell
    {
        return Upsell::where('id', $upsellId)
            ->where('checkout_id', $checkoutId)
            ->whereHas('checkout', fn ($q) => $q->where('account_id', $request->user()->account_id))
            ->with('product:id,name,price,image_url,images')
            ->first();
    }

    private function formatUpsell(Upsell $upsell): array
    {
        $data = $upsell->toArray();
        $data['conversion_rate'] = $upsell->conversion_rate;
        if ($upsell->product) {
            $data['product'] = [
                'id' => $upsell->product->id,
                'name' => $upsell->product->name,
                'price' => $upsell->product->price,
                'image_url' => $upsell->product->image_url ?? ($upsell->product->images[0] ?? null),
            ];
        }
        return $data;
    }
}
