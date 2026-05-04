<?php

namespace App\Domain\Payment\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'charge_id',
        'transaction_id',
        'amount',
        'reason',
        'status',
        'acquirer_refund_id',
        'processed_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'processed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function charge()
    {
        return $this->belongsTo(\App\Models\Charge::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
