<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'account_id',
        'checkout_id',
        'category_id',
        'category',
        'name',
        'slug',
        'description',
        'type',
        'price',
        'original_price',
        'currency',
        'commission_percent',
        'commission_fixed',
        'affiliate_enabled',
        'stock_enabled',
        'stock_quantity',
        'images',
        'image_url',
        'metadata',
        'seo_title',
        'seo_description',
        'is_active',
    ];

    protected $casts = [
        'price' => 'integer',
        'original_price' => 'integer',
        'commission_percent' => 'decimal:2',
        'commission_fixed' => 'integer',
        'affiliate_enabled' => 'boolean',
        'stock_enabled' => 'boolean',
        'stock_quantity' => 'integer',
        'images' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'admin_blocked_at' => 'datetime',
    ];

    protected $appends = ['status'];

    protected static function booted(): void
    {
        // Produto bloqueado pelo admin não volta a vender por edição do seller, API ou automação
        static::saving(function (Product $product) {
            if ($product->admin_blocked_at) {
                $product->is_active = false;
            }
        });
    }

    public function getStatusAttribute(): string
    {
        return $this->is_active ? 'active' : 'inactive';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function course(): HasOne
    {
        return $this->hasOne(Course::class);
    }
}
