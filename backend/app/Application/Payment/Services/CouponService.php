<?php

namespace App\Application\Payment\Services;

use App\Domain\Payment\Entities\Coupon;
use App\Domain\Payment\Entities\CouponUsage;
use Illuminate\Support\Facades\DB;

class CouponService
{
    /**
     * Validate coupon code and return discount
     */
    public function validateCoupon(
        string $code,
        string $accountId,
        float $amount,
        ?string $customerEmail = null,
        array $productIds = [],
        array $categoryIds = []
    ): array {
        $coupon = Coupon::where('account_id', $accountId)
            ->where('code', strtoupper($code))
            ->active()
            ->first();

        if (!$coupon) {
            return [
                'valid' => false,
                'error' => 'Cupom inválido ou expirado',
            ];
        }

        // Check if coupon is valid
        if (!$coupon->isValid()) {
            return [
                'valid' => false,
                'error' => 'Cupom não disponível',
            ];
        }

        // Check minimum purchase
        if ($coupon->min_purchase && $amount < $coupon->min_purchase) {
            return [
                'valid' => false,
                'error' => sprintf(
                    'Valor mínimo de compra: R$ %.2f',
                    $coupon->min_purchase
                ),
            ];
        }

        // Check first purchase only
        if ($coupon->first_purchase_only && $customerEmail) {
            if ($coupon->hasBeenUsedBy($customerEmail)) {
                return [
                    'valid' => false,
                    'error' => 'Cupom válido apenas para primeira compra',
                ];
            }
        }

        // Check product restrictions
        if (!empty($coupon->product_ids) && !$coupon->appliesToProducts($productIds)) {
            return [
                'valid' => false,
                'error' => 'Cupom não válido para estes produtos',
            ];
        }

        // Check category restrictions
        if (!empty($coupon->category_ids) && !$coupon->appliesToCategories($categoryIds)) {
            return [
                'valid' => false,
                'error' => 'Cupom não válido para esta categoria',
            ];
        }

        // Calculate discount
        $discountAmount = $coupon->calculateDiscount($amount);

        return [
            'valid' => true,
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => $coupon->value,
            'discount_amount' => $discountAmount,
            'final_amount' => max(0, $amount - $discountAmount),
        ];
    }

    /**
     * Apply coupon to a charge
     */
    public function applyCoupon(
        string $couponId,
        string $chargeId,
        string $customerEmail,
        float $discountAmount
    ): CouponUsage {
        return DB::transaction(function () use ($couponId, $chargeId, $customerEmail, $discountAmount) {
            // Create usage record
            $usage = CouponUsage::create([
                'coupon_id' => $couponId,
                'charge_id' => $chargeId,
                'customer_email' => $customerEmail,
                'discount_amount' => $discountAmount,
                'used_at' => now(),
            ]);

            // Increment usage count
            $coupon = Coupon::find($couponId);
            $coupon->incrementUsage();

            return $usage;
        });
    }

    /**
     * Create a new coupon
     */
    public function createCoupon(string $accountId, array $data): Coupon
    {
        $data['account_id'] = $accountId;
        $data['code'] = strtoupper($data['code']);

        return Coupon::create($data);
    }

    /**
     * Update coupon
     */
    public function updateCoupon(string $couponId, array $data): Coupon
    {
        $coupon = Coupon::findOrFail($couponId);

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $coupon->update($data);

        return $coupon->fresh();
    }

    /**
     * Deactivate coupon
     */
    public function deactivateCoupon(string $couponId): bool
    {
        $coupon = Coupon::findOrFail($couponId);
        return $coupon->update(['is_active' => false]);
    }

    /**
     * Get coupon statistics
     */
    public function getCouponStats(string $couponId): array
    {
        $coupon = Coupon::with('usages')->findOrFail($couponId);

        $totalDiscount = $coupon->usages()->sum('discount_amount');
        $uniqueCustomers = $coupon->usages()->distinct('customer_email')->count();

        return [
            'total_uses' => $coupon->usage_count,
            'remaining_uses' => $coupon->remaining_uses,
            'total_discount' => $totalDiscount,
            'unique_customers' => $uniqueCustomers,
            'is_active' => $coupon->is_active,
            'is_valid' => $coupon->isValid(),
        ];
    }

    /**
     * Get account coupons with stats
     */
    public function getAccountCoupons(string $accountId, array $filters = []): array
    {
        $query = Coupon::where('account_id', $accountId);

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['search'])) {
            $query->where('code', 'like', '%' . strtoupper($filters['search']) . '%');
        }

        $coupons = $query->with('usages')
            ->orderBy('created_at', 'desc')
            ->get();

        return $coupons->map(function ($coupon) {
            return [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
                'usage_count' => $coupon->usage_count,
                'usage_limit' => $coupon->usage_limit,
                'remaining_uses' => $coupon->remaining_uses,
                'is_active' => $coupon->is_active,
                'is_valid' => $coupon->isValid(),
                'valid_from' => $coupon->valid_from?->toIso8601String(),
                'valid_until' => $coupon->valid_until?->toIso8601String(),
                'total_discount' => $coupon->usages()->sum('discount_amount'),
                'created_at' => $coupon->created_at->toIso8601String(),
            ];
        })->toArray();
    }
}
