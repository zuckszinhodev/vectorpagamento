<?php

namespace App\Domain\Product\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'account_id',
        'category_id',
        'name',
        'description',
        'price',
        'images',
        'stock',
        'max_installments',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'max_installments' => 'integer',
        'images' => 'array',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function account()
    {
        return $this->belongsTo(\App\Domain\Account\Entities\Account::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function charges()
    {
        return $this->hasMany(\App\Domain\Payment\Entities\Charge::class);
    }

    public function hasStock(): bool
    {
        return $this->stock === null || $this->stock > 0;
    }

    public function decrementStock(int $quantity = 1): void
    {
        if ($this->stock !== null) {
            $this->decrement('stock', $quantity);
        }
    }

    public function incrementStock(int $quantity = 1): void
    {
        if ($this->stock !== null) {
            $this->increment('stock', $quantity);
        }
    }
}
