<?php

namespace App\Domain\Account\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountWallet extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'available_balance',
        'pending_balance',
        'blocked_balance',
        'currency',
    ];

    protected $casts = [
        'available_balance' => 'decimal:2',
        'pending_balance' => 'decimal:2',
        'blocked_balance' => 'decimal:2',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function getTotalBalance(): float
    {
        return $this->available_balance + $this->pending_balance;
    }

    public function hasSufficientBalance(float $amount): bool
    {
        return $this->available_balance >= $amount;
    }

    public function addBalance(float $amount): void
    {
        $this->increment('available_balance', $amount);
    }

    public function deductBalance(float $amount): void
    {
        if (!$this->hasSufficientBalance($amount)) {
            throw new \Exception('Saldo insuficiente');
        }

        $this->decrement('available_balance', $amount);
    }

    public function blockBalance(float $amount): void
    {
        if (!$this->hasSufficientBalance($amount)) {
            throw new \Exception('Saldo insuficiente para bloquear');
        }

        $this->decrement('available_balance', $amount);
        $this->increment('blocked_balance', $amount);
    }

    public function releaseBalance(float $amount): void
    {
        $this->decrement('blocked_balance', $amount);
        $this->increment('available_balance', $amount);
    }
}
