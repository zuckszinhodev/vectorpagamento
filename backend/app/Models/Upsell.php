<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Upsell extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'account_id',
        'checkout_id',
        'product_id',
        'title',
        'description',
        'headline',
        'call_to_action',
        'decline_text',
        'image_url',
        'video_url',
        'price',
        'original_price',
        'discount_percent',
        'type',
        'next_upsell_id',
        'downsell_id',
        'sort_order',
        'is_active',
        'one_click_purchase',
        'timer_seconds',
        'settings',
        'benefits',
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
        'one_click_purchase' => 'boolean',
        'timer_seconds' => 'integer',
        'settings' => 'array',
        'benefits' => 'array',
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

    public function nextUpsell(): BelongsTo
    {
        return $this->belongsTo(Upsell::class, 'next_upsell_id');
    }

    public function downsell(): BelongsTo
    {
        return $this->belongsTo(Upsell::class, 'downsell_id');
    }

    public function chargeOffers(): HasMany
    {
        return $this->hasMany(ChargeOffer::class, 'offer_id')
            ->whereIn('offer_type', ['upsell', 'downsell']);
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
