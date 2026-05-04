<?php

namespace App\Domain\Affiliate\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Split extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'charge_id',
        'affiliate_id',
        'recipient_account_id',
        'amount',
        'percentage',
        'type',
        'status',
        'processed_at',
        'failed_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function charge()
    {
        return $this->belongsTo(\App\Domain\Payment\Entities\Charge::class);
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function recipientAccount()
    {
        return $this->belongsTo(\App\Domain\Account\Entities\Account::class, 'recipient_account_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
