<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderBump extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'account_id',
        'checkout_id',
        'product_id',
        'title',
        'description',
        'call_to_action',
        'image_url',
        'price',
        'original_price',
        'discount_percent',
        'display_position',
        'sort_order',
        'is_active',
        'is_pre_selected',
        'settings',
        'views_count',
        'conversions_count',
        'total_revenue',
    ];

    protected $casts = [
        'price' => 'integer',
        'original_price' => 'integer',
        'discount_percent' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_pre_selected' => 'boolean',
        'settings' => 'array',
        'views_count' => 'integer',
        'conversions_count' => 'integer',
        'total_revenue' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function chargeOffers(): HasMany
    {
        return $this->hasMany(ChargeOffer::class, 'offer_id')
            ->where('offer_type', 'order_bump');
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function incrementConversions(int $revenue = 0): void
    {
        $this->increment('conversions_count');
        $this->increment('total_revenue', $revenue);
    }

    public function getConversionRateAttribute(): float
    {
        if ($this->views_count <= 0) {
            return 0;
        }
        return round(($this->conversions_count / $this->views_count) * 100, 2);
    }
}
