<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageVariant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'landing_page_id',
        'name',
        'content',
        'sections',
        'traffic_weight',
        'views_count',
        'conversions_count',
        'is_control',
        'is_active',
    ];

    protected $casts = [
        'content' => 'array',
        'sections' => 'array',
        'traffic_weight' => 'integer',
        'views_count' => 'integer',
        'conversions_count' => 'integer',
        'is_control' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Relationships
     */
    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->whereRaw('"is_active" = true');
    }

    /**
     * Get conversion rate
     */
    public function getConversionRate(): float
    {
        if ($this->views_count === 0) {
            return 0;
        }

        return round(($this->conversions_count / $this->views_count) * 100, 2);
    }
}
