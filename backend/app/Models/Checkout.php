<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Checkout extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'account_id',
        'product_id',
        'name',
        'slug',
        'type',
        'status',
        'theme',
        'fields',
        'settings',
        'success_url',
        'cancel_url',
        'webhook_url',
        'views_count',
        'conversions_count',
        'conversion_rate',
        'total_revenue',
    ];

    protected $casts = [
        'theme' => 'array',
        'fields' => 'array',
        'settings' => 'array',
        'views_count' => 'integer',
        'conversions_count' => 'integer',
        'conversion_rate' => 'decimal:2',
        'total_revenue' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function orderBumps(): HasMany
    {
        return $this->hasMany(OrderBump::class);
    }

    public function upsells(): HasMany
    {
        return $this->hasMany(Upsell::class);
    }

    /**
     * Increment views count
     */
    public function incrementViews(): void
    {
        $this->increment('views_count');
        $this->updateConversionRate();
    }

    /**
     * Increment conversions count
     */
    public function incrementConversions(int $revenue = 0): void
    {
        $this->increment('conversions_count');
        $this->increment('total_revenue', $revenue);
        $this->updateConversionRate();
    }

    /**
     * Update conversion rate
     */
    public function updateConversionRate(): void
    {
        if ($this->views_count > 0) {
            $this->conversion_rate = round(($this->conversions_count / $this->views_count) * 100, 2);
            $this->save();
        }
    }

    /**
     * Get checkout URL (uses custom domain if available)
     */
    public function getUrlAttribute(): string
    {
        try {
            $customDomain = \App\Models\CustomDomain::where('account_id', $this->account_id)
                ->where('status', 'verified')
                ->where('is_primary', true)
                ->first();
            
            if ($customDomain) {
                $protocol = $customDomain->ssl_enabled ? 'https' : 'http';
                return "{$protocol}://{$customDomain->domain}/checkout/{$this->slug}";
            }
        } catch (\Throwable $e) {
            // custom_domains table may not exist yet
        }
        
        $baseUrl = config('app.frontend_url') ?? config('app.url');
        return rtrim($baseUrl, '/') . '/checkout/' . $this->slug;
    }
}
