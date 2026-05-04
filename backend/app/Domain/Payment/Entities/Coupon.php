<?php

namespace App\Domain\Payment\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'account_id',
        'code',
        'type',
        'value',
        'min_purchase',
        'max_discount',
        'usage_limit',
        'usage_count',
        'valid_from',
        'valid_until',
        'first_purchase_only',
        'product_ids',
        'category_ids',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_count' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'first_purchase_only' => 'boolean',
        'product_ids' => 'array',
        'category_ids' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function usages()
    {
        return $this->hasMany(CouponUsage::class);
    }

    // Check if coupon is valid
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        if ($this->valid_from && $now->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && $now->gt($this->valid_until)) {
            return false;
        }

        if ($this->usage_limit && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    // Calculate discount amount
    public function calculateDiscount(float $amount): float
    {
        if ($this->min_purchase && $amount < $this->min_purchase) {
            return 0;
        }

        $discount = 0;

        if ($this->type === 'percentage') {
            $discount = ($amount * $this->value) / 100;
        } elseif ($this->type === 'fixed') {
            $discount = $this->value;
        }

        // Apply max discount limit
        if ($this->max_discount && $discount > $this->max_discount) {
            $discount = $this->max_discount;
        }

        // Cannot discount more than the amount
        if ($discount > $amount) {
            $discount = $amount;
        }

        return round($discount, 2);
    }

    // Check if customer already used this coupon
    public function hasBeenUsedBy(string $customerEmail): bool
    {
        return $this->usages()->where('customer_email', $customerEmail)->exists();
    }

    // Check if applies to specific products
    public function appliesToProducts(array $productIds): bool
    {
        if (empty($this->product_ids)) {
            return true; // No restrictions
        }

        return !empty(array_intersect($this->product_ids, $productIds));
    }

    // Check if applies to specific categories
    public function appliesToCategories(array $categoryIds): bool
    {
        if (empty($this->category_ids)) {
            return true; // No restrictions
        }

        return !empty(array_intersect($this->category_ids, $categoryIds));
    }

    // Increment usage count
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    // Scope for active coupons
    public function scopeActive($query)
    {
        return $query->whereRaw('"is_active" = true')
            ->where(function ($q) {
                $q->whereNull('valid_from')
                  ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                  ->orWhere('valid_until', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('usage_limit')
                  ->orWhereColumn('usage_count', '<', 'usage_limit');
            });
    }

    // Get remaining uses
    public function getRemainingUsesAttribute(): ?int
    {
        if (!$this->usage_limit) {
            return null;
        }

        return max(0, $this->usage_limit - $this->usage_count);
    }
}
