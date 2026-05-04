<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Split extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'transaction_id',
        'recipient_id',
        'recipient_type',
        'type',
        'amount',
        'percentage',
        'fee_amount',
        'net_amount',
        'status',
        'transferred_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'percentage' => 'decimal:2',
        'fee_amount' => 'integer',
        'net_amount' => 'integer',
        'transferred_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_TRANSFERRED = 'transferred';
    const STATUS_FAILED = 'failed';

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function recipientAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'recipient_id');
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'recipient_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function markAsTransferred(): void
    {
        $this->update([
            'status' => self::STATUS_TRANSFERRED,
            'transferred_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => self::STATUS_FAILED]);
    }
}
