<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'charge_id',
        'acquirer_id',
        'amount',
        'fee',
        'net_amount',
        'status',
        'type',
        'acquirer_tid',
        'acquirer_nsu',
        'authorization_code',
        'card_brand',
        'card_last_digits',
        'installments',
        'gateway_response',
        'error_code',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'fee' => 'integer',
        'net_amount' => 'integer',
        'installments' => 'integer',
        'gateway_response' => 'array',
        'processed_at' => 'datetime',
    ];

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function acquirer(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Acquirer\Entities\Acquirer::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(Split::class);
    }
}
