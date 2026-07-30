<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-minute counters for one acquirer and payment method.
 *
 * Aggregating into minute buckets keeps the routing hot path to a single
 * upsert instead of an insert per transaction, and makes dashboard queries a
 * cheap SUM over a small window.
 */
class AcquirerMetricBucket extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'acquirer_key',
        'payment_method',
        'bucket_started_at',
        'attempts',
        'successes',
        'failures',
        'timeouts',
        'rejections',
        'fallback_ins',
        'fallback_outs',
        'latency_sum_ms',
        'latency_max_ms',
        'volume_cents',
    ];

    protected $casts = [
        'bucket_started_at' => 'datetime',
        'attempts' => 'integer',
        'successes' => 'integer',
        'failures' => 'integer',
        'timeouts' => 'integer',
        'rejections' => 'integer',
        'fallback_ins' => 'integer',
        'fallback_outs' => 'integer',
        'latency_sum_ms' => 'integer',
        'latency_max_ms' => 'integer',
        'volume_cents' => 'integer',
    ];
}
