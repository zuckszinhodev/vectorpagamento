<?php

namespace App\Domain\Affiliate\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Affiliate extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'account_id',
        'name',
        'code',
        'email',
        'phone',
        'commission_rate',
        'commission_type',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function account()
    {
        return $this->belongsTo(\App\Domain\Account\Entities\Account::class);
    }

    public function splits()
    {
        return $this->hasMany(Split::class);
    }

    public function getTotalCommissions(): float
    {
        return $this->splits()
            ->where('status', 'processed')
            ->sum('amount');
    }

    public function getPendingCommissions(): float
    {
        return $this->splits()
            ->where('status', 'pending')
            ->sum('amount');
    }

    public function calculateCommission(float $amount): float
    {
        if ($this->commission_type === 'percentage') {
            return ($amount * $this->commission_rate) / 100;
        }

        return $this->commission_rate;
    }
}
