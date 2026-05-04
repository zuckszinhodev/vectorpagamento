<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chargeback extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'charge_id',
        'transaction_id',
        'account_id',
        'amount',
        'reason_code',
        'reason',
        'status',
        'evidence',
        'acquirer_reference',
        'deadline_at',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    protected $casts = [
        'amount' => 'integer',
        'evidence' => 'array',
        'deadline_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    const STATUS_OPEN = 'open';
    const STATUS_UNDER_REVIEW = 'under_review';
    const STATUS_EVIDENCE_SENT = 'evidence_sent';
    const STATUS_WON = 'won';
    const STATUS_LOST = 'lost';
    const STATUS_EXPIRED = 'expired';

    public function charge()
    {
        return $this->belongsTo(Charge::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function resolvedByUser()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_UNDER_REVIEW, self::STATUS_EVIDENCE_SENT]);
    }

    public function scopeExpiring($query, int $days = 3)
    {
        return $query->active()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now()->addDays($days));
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_WON, self::STATUS_LOST, self::STATUS_EXPIRED]);
    }
}
