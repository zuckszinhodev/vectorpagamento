<?php

namespace App\Domain\Checkout\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Checkout extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'account_id',
        'name',
        'slug',
        'type',
        'config',
        'design',
        'success_url',
        'cancel_url',
        'notification_url',
        'is_active',
        'views_count',
        'conversions_count',
    ];

    protected $casts = [
        'config' => 'array',
        'design' => 'array',
        'is_active' => 'boolean',
        'views_count' => 'integer',
        'conversions_count' => 'integer',
    ];

    public function account()
    {
        return $this->belongsTo(\App\Domain\Account\Entities\Account::class);
    }

    public function charges()
    {
        return $this->hasMany(\App\Domain\Payment\Entities\Charge::class);
    }

    public function getConversionRate(): float
    {
        if ($this->views_count === 0) {
            return 0;
        }

        return ($this->conversions_count / $this->views_count) * 100;
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function incrementConversions(): void
    {
        $this->increment('conversions_count');
    }

    public function getUrl(): string
    {
        return url("/checkout/{$this->slug}");
    }
}
