<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Routing knobs for one acquirer: priority, weight, TPS ceiling, timeouts,
 * retry policy and circuit breaker thresholds.
 *
 * Kept apart from AcquirerConfig (which owns commercial data such as fees) so
 * that operational tuning never risks touching billing fields.
 */
class AcquirerRoutingProfile extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'acquirer_config_id',
        'acquirer_key',
        'routing_enabled',
        'priority',
        'weight',
        'max_tps',
        'max_concurrent',
        'connect_timeout_ms',
        'timeout_ms',
        'max_retries',
        'retry_backoff_ms',
        'retry_backoff_max_ms',
        'cb_failure_threshold',
        'cb_cooldown_seconds',
        'cb_half_open_max_calls',
        'cb_success_threshold',
        'health_check_enabled',
        'health_check_url',
        'health_check_interval_seconds',
        'min_amount_cents',
        'max_amount_cents',
        'rules',
    ];

    protected $casts = [
        'routing_enabled' => 'boolean',
        'health_check_enabled' => 'boolean',
        'priority' => 'integer',
        'weight' => 'integer',
        'max_tps' => 'integer',
        'max_concurrent' => 'integer',
        'connect_timeout_ms' => 'integer',
        'timeout_ms' => 'integer',
        'max_retries' => 'integer',
        'retry_backoff_ms' => 'integer',
        'retry_backoff_max_ms' => 'integer',
        'cb_failure_threshold' => 'integer',
        'cb_cooldown_seconds' => 'integer',
        'cb_half_open_max_calls' => 'integer',
        'cb_success_threshold' => 'integer',
        'health_check_interval_seconds' => 'integer',
        'min_amount_cents' => 'integer',
        'max_amount_cents' => 'integer',
        'rules' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(AcquirerConfig::class, 'acquirer_config_id');
    }

    /**
     * Build a profile pre-filled with the configured defaults.
     */
    public static function defaultsFor(AcquirerConfig $config): self
    {
        $defaults = (array) config('acquirer_routing.profile_defaults', []);

        return new self(array_merge($defaults, [
            'acquirer_config_id' => $config->id,
            'acquirer_key' => $config->acquirer_key,
            'routing_enabled' => (bool) $config->enabled,
            'priority' => (int) ($config->priority ?? $defaults['priority'] ?? 100),
        ]));
    }

    /**
     * Retry delay for a given attempt, using exponential backoff with jitter.
     * Jitter avoids synchronised retry storms across workers.
     */
    public function backoffDelayMs(int $attempt): int
    {
        $base = max(0, $this->retry_backoff_ms ?? 200);

        if ($base === 0) {
            return 0;
        }

        $ceiling = max($base, $this->retry_backoff_max_ms ?? 2000);
        $exponential = min($ceiling, (int) ($base * (2 ** max(0, $attempt - 1))));

        // Full jitter: uniform in [0, exponential].
        return random_int(0, $exponential);
    }
}
