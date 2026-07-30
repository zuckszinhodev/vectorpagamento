<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\HealthState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Live availability of an acquirer: health state, circuit breaker state,
 * latency and error history.
 *
 * This is the single source of truth the router reads. The old split — health
 * checks writing to `acquirers.health_status` while routing read
 * `acquirer_configs.settings['health_status']` — is what made health checks
 * invisible to routing.
 */
class AcquirerHealthState extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'acquirer_config_id',
        'acquirer_key',
        'state',
        'circuit_state',
        'consecutive_failures',
        'consecutive_successes',
        'circuit_opened_at',
        'circuit_half_open_at',
        'circuit_trip_count',
        'last_latency_ms',
        'avg_latency_ms',
        'last_error',
        'last_error_at',
        'last_success_at',
        'last_check_at',
        'checks_total',
        'checks_ok',
    ];

    protected $casts = [
        'consecutive_failures' => 'integer',
        'consecutive_successes' => 'integer',
        'circuit_trip_count' => 'integer',
        'last_latency_ms' => 'integer',
        'avg_latency_ms' => 'integer',
        'checks_total' => 'integer',
        'checks_ok' => 'integer',
        'circuit_opened_at' => 'datetime',
        'circuit_half_open_at' => 'datetime',
        'last_error_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_check_at' => 'datetime',
    ];

    public function healthState(): HealthState
    {
        return HealthState::tryNormalize($this->state);
    }

    public function circuitState(): CircuitState
    {
        return CircuitState::tryNormalize($this->circuit_state);
    }

    /**
     * Availability as a percentage of successful health checks.
     */
    public function availabilityPercent(): float
    {
        $total = (int) $this->checks_total;

        if ($total <= 0) {
            return 100.0;
        }

        return round(((int) $this->checks_ok / $total) * 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'acquirer_key' => $this->acquirer_key,
            'state' => $this->state,
            'state_label' => $this->healthState()->label(),
            'circuit_state' => $this->circuit_state,
            'circuit_state_label' => $this->circuitState()->label(),
            'consecutive_failures' => (int) $this->consecutive_failures,
            'circuit_trip_count' => (int) $this->circuit_trip_count,
            'last_latency_ms' => $this->last_latency_ms,
            'avg_latency_ms' => $this->avg_latency_ms,
            'availability_percent' => $this->availabilityPercent(),
            'last_error' => $this->last_error,
            'last_error_at' => $this->last_error_at?->toIso8601String(),
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'last_check_at' => $this->last_check_at?->toIso8601String(),
        ];
    }
}
