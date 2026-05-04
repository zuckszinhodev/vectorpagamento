<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Withdrawal extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'account_id',
        'user_id',
        'amount',
        'fee',
        'net_amount',
        'status',
        'method',
        'is_instant',
        'acquirer_transfer_id',
        'acquirer',
        'auto_process',
        'destination',
        'notes',
        'failure_reason',
        'processed_at',
        'idempotency_key',
        'idempotency_request_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'destination' => 'array',
        'is_instant' => 'boolean',
        'auto_process' => 'boolean',
        'processed_at' => 'datetime',
    ];

    protected $hidden = [
        'idempotency_key',
        'idempotency_request_hash',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'processed_at' => now(),
        ]);
    }

    public function cancel(): void
    {
        if ($this->canBeCancelled()) {
            $this->update(['status' => 'cancelled']);
        }
    }
}
