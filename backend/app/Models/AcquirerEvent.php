<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail of everything that changed an acquirer's operational state:
 * status transitions, circuit breaker trips, health checks, config edits.
 */
class AcquirerEvent extends Model
{
    use HasUuids;

    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_CIRCUIT_OPENED = 'circuit_opened';
    public const TYPE_CIRCUIT_HALF_OPEN = 'circuit_half_open';
    public const TYPE_CIRCUIT_CLOSED = 'circuit_closed';
    public const TYPE_HEALTH_CHECK = 'health_check';
    public const TYPE_CONFIG_CHANGED = 'config_changed';
    public const TYPE_CREDENTIAL_ROTATED = 'credential_rotated';
    public const TYPE_TPS_THROTTLED = 'tps_throttled';
    public const TYPE_FALLBACK = 'fallback';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'acquirer_key',
        'type',
        'severity',
        'message',
        'context',
        'correlation_id',
        'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];
}
