<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountWallet extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'account_id',
        'currency',
        'available_balance',
        'pending_balance',
        'blocked_balance',
    ];

    protected $casts = [
        'available_balance' => 'integer',
        'pending_balance' => 'integer',
        'blocked_balance' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function getTotalBalanceAttribute(): int
    {
        return $this->available_balance + $this->pending_balance + $this->blocked_balance;
    }
}
