<?php

namespace App\Domain\Acquirer\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Acquirer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'supported_methods',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'supported_methods' => 'array',
        'settings' => 'array',
    ];

    public function supportsMethod(string $method): bool
    {
        return in_array($method, $this->supported_methods ?? []);
    }

    public function isHealthy(): bool
    {
        return $this->is_active;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSupporting($query, string $method)
    {
        return $query->whereJsonContains('supported_methods', $method);
    }
}
