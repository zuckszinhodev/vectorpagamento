<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    /**
     * List all coupons
     */
    public function index(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;
        $perPage = min($request->input('per_page', 20), 100);

        $query = Coupon::where('account_id', $accountId);

        // Filters
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $query->where('code', 'like', '%' . $request->input('search') . '%');
        }

        $coupons = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $coupons->items(),
            'meta' => [
                'current_page' => $coupons->currentPage(),
                'per_page' => $coupons->perPage(),
                'total' => $coupons->total(),
                'last_page' => $coupons->lastPage(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CouponController::index error', [
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
     * Create a new coupon
     */
    public function store(Request $request): JsonResponse
    {
        try {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:coupons,code'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'first_purchase_only' => ['boolean'],
            'product_ids' => ['nullable', 'array'],
            'category_ids' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $coupon = Coupon::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $request->user()->account_id,
            'code' => strtoupper($validated['code']),
            'type' => $validated['type'],
            'value' => $validated['value'],
            'min_purchase' => $validated['min_purchase'] ?? null,
            'max_discount' => $validated['max_discount'] ?? null,
            'usage_limit' => $validated['usage_limit'] ?? null,
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'first_purchase_only' => $validated['first_purchase_only'] ?? false,
            'product_ids' => $validated['product_ids'] ?? null,
            'category_ids' => $validated['category_ids'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'data' => $coupon,
            'message' => 'Cupom criado com sucesso',
        ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CouponController::store error', [
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
     * Get a specific coupon
     */
    public function show(Request $request, string $couponId): JsonResponse
    {
        try {
        $coupon = Coupon::where('account_id', $request->user()->account_id)
            ->find($couponId);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Cupom não encontrado',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $coupon,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CouponController::show error', [
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
     * Update a coupon
     */
    public function update(Request $request, string $couponId): JsonResponse
    {
        try {
        $coupon = Coupon::where('account_id', $request->user()->account_id)
            ->find($couponId);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Cupom não encontrado',
            ], 404);
        }

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', 'unique:coupons,code,' . $couponId],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'first_purchase_only' => ['boolean'],
            'product_ids' => ['nullable', 'array'],
            'category_ids' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $coupon->update($validated);

        return response()->json([
            'success' => true,
            'data' => $coupon->fresh(),
            'message' => 'Cupom atualizado com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CouponController::update error', [
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
     * Delete a coupon
     */
    public function destroy(Request $request, string $couponId): JsonResponse
    {
        try {
        $coupon = Coupon::where('account_id', $request->user()->account_id)
            ->find($couponId);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Cupom não encontrado',
            ], 404);
        }

        $coupon->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cupom removido com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CouponController::destroy error', [
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
     * Validate a coupon code (public endpoint)
     */
    public function validateCode(Request $request): JsonResponse
    {
        try {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $coupon = Coupon::where('code', strtoupper($validated['code']))
            ->where('is_active', true)
            ->first();

        if (!$coupon || !$coupon->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Cupom inválido ou expirado',
            ], 404);
        }

        $discount = $coupon->calculateDiscount((float) $validated['amount']);

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
                'discount_amount' => $discount,
                'final_amount' => $validated['amount'] - $discount,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CouponController::validateCode error', [
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
