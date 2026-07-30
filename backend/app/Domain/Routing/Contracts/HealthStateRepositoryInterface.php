<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\HealthState;
use App\Models\AcquirerHealthState;
use Illuminate\Support\Collection;

/**
 * Durable store of acquirer availability.
 *
 * This is the ONE place routing, health checks and the dashboard read from —
 * the split between `acquirers.health_status` and
 * `acquirer_configs.settings['health_status']` is what previously made health
 * checks invisible to the router.
 */
interface HealthStateRepositoryInterface
{
    /**
     * @return Collection<string, AcquirerHealthState> keyed by acquirer_key
     */
    public function all(): Collection;

    public function find(string $acquirerKey): ?AcquirerHealthState;

    /**
     * Get or create the state row for an acquirer.
     */
    public function ensure(string $acquirerKey, ?string $acquirerConfigId = null): AcquirerHealthState;

    /**
     * Persist the outcome of a health probe and derive the health state from it.
     */
    public function recordProbe(
        string $acquirerKey,
        bool $healthy,
        int $latencyMs,
        ?string $error = null,
    ): AcquirerHealthState;

    /**
     * Persist a circuit breaker transition.
     */
    public function recordCircuitTransition(
        string $acquirerKey,
        CircuitState $state,
        ?string $reason = null,
    ): void;

    /**
     * Persist observed latency / errors from real traffic (not health probes).
     */
    public function recordTraffic(
        string $acquirerKey,
        bool $succeeded,
        int $latencyMs,
        ?string $error = null,
    ): void;

    /**
     * Override the health state (admin panel enable/disable, forced offline).
     */
    public function setState(string $acquirerKey, HealthState $state, ?string $reason = null): void;

    public function forget(string $acquirerKey): void;
}
