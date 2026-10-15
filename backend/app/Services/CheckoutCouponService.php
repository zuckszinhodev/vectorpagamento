<?php

namespace App\Services;

use App\Models\Checkout;
use App\Models\Coupon;
use Illuminate\Validation\ValidationException;

class CheckoutCouponService
{
    public function calculate(Checkout $checkout, string $code): array
    {
        $coupon = Coupon::where('account_id', $checkout->account_id)
            ->where('code', strtoupper(trim($code)))->first();
        $product = $checkout->product;
        if (!$coupon || !$coupon->isValid() || !$product || !$product->is_active
            || ($coupon->product_ids && !in_array($product->id, $coupon->product_ids, true))
            || ($coupon->category_ids && !in_array($product->category_id, $coupon->category_ids, true))
            || $coupon->first_purchase_only || $coupon->usage_limit) {
            throw ValidationException::withMessages(['coupon_code' => 'Cupom inválido ou indisponível para este produto.']);
        }
        $discount = (int) round($coupon->calculateDiscount((float) $product->price));
        if ($discount <= 0 || $product->price - $discount < 100) {
            throw ValidationException::withMessages(['coupon_code' => 'Este cupom não pode ser aplicado ao valor do produto.']);
        }
        return ['code' => $coupon->code, 'discount_amount' => $discount];
    }
}
