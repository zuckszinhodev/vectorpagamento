<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per routing decision, holding the full attempt chain.
 *
 * `correlation_id` is the unique transaction identifier that ties this row to
 * every application log line emitted while routing it.
 */
class AcquirerRoutingLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'correlation_id',
        'charge_id',
        'account_id',
        'payment_method',
        'amount_cents',
        'strategy',
        'selected_acquirer',
        'selection_reason',
        'outcome',
        'attempt_count',
        'total_duration_ms',
        'attempts',
        'candidates',
        'error',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'attempt_count' => 'integer',
        'total_duration_ms' => 'integer',
        'attempts' => 'array',
        'candidates' => 'array',
    ];
}
