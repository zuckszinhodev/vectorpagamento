<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->usage_limit && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        $now = now();
        if ($this->valid_from && $now->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && $now->gt($this->valid_until)) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(float $amount): float
    {
        if ($this->min_purchase && $amount < $this->min_purchase) {
            return 0;
        }

        $discount = $this->type === 'percentage'
            ? ($amount * $this->value / 100)
            : $this->value;

        if ($this->max_discount && $discount > $this->max_discount) {
            $discount = $this->max_discount;
        }

        return min($discount, $amount);
    }
}
