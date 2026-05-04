<?php

namespace App\Domain\Account\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'document',
        'email',
        'phone',
        'type',
        'is_active',
        'kyc_status',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function users()
    {
        return $this->hasMany(\App\Domain\Auth\Entities\User::class);
    }

    public function wallet()
    {
        return $this->hasOne(AccountWallet::class);
    }

    public function apiKeys()
    {
        return $this->hasMany(\App\Domain\Auth\Entities\ApiKey::class);
    }

    public function charges()
    {
        return $this->hasMany(\App\Domain\Payment\Entities\Charge::class);
    }

    public function products()
    {
        return $this->hasMany(\App\Domain\Product\Entities\Product::class);
    }

    public function checkouts()
    {
        return $this->hasMany(\App\Domain\Checkout\Entities\Checkout::class);
    }
}
