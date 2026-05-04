<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reconciliation extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'account_id',
        'period_start',
        'period_end',
        'type',
        'status',
        'total_charges',
        'total_amount',
        'total_fees',
        'total_net',
        'total_refunds',
        'total_chargebacks',
        'discrepancies',
        'summary',
        'reconciled_by',
        'reconciled_at',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'reconciled_at' => 'datetime',
        'total_amount' => 'integer',
        'total_fees' => 'integer',
        'total_net' => 'integer',
        'total_refunds' => 'integer',
        'total_chargebacks' => 'integer',
        'total_charges' => 'integer',
        'discrepancies' => 'array',
        'summary' => 'array',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    const TYPE_DAILY = 'daily';
    const TYPE_WEEKLY = 'weekly';
    const TYPE_MONTHLY = 'monthly';
    const TYPE_MANUAL = 'manual';

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function reconciledByUser()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function hasDiscrepancies(): bool
    {
        return !empty($this->discrepancies);
    }
}
