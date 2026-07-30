<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\FailureReason;

/**
 * Per-acquirer circuit breaker.
 *
 * Unlike the previous implementation, every acquirer gets one — including ones
 * registered from the admin panel after boot — and its thresholds come from
 * that acquirer's routing profile rather than from a hard-coded constructor.
 */
interface CircuitBreakerInterface
{
    /**
     * Whether the acquirer may take a request right now.
     *
     * Transitions OPEN → HALF_OPEN when the cooldown has elapsed, and admits a
     * limited number of probes while half-open.
     */
    public function allows(string $acquirerKey): bool;

    public function state(string $acquirerKey): CircuitState;

    /**
     * Record a successful call. Closes the circuit once enough consecutive
     * successes have accumulated in half-open.
     */
    public function recordSuccess(string $acquirerKey, int $latencyMs = 0): void;

    /**
     * Record a failure. Opens the circuit once the failure threshold is hit,
     * or immediately if a half-open probe failed.
     *
     * Reasons that do not indicate an unhealthy acquirer (a declined card, a
     * validation error) are ignored.
     */
    public function recordFailure(string $acquirerKey, FailureReason $reason, ?string $error = null): void;

    /**
     * Force the circuit closed — used by the admin panel and by a successful
     * health check probe.
     */
    public function reset(string $acquirerKey, string $reason = 'manual'): void;

    /**
     * Force the circuit open — used to take an acquirer out of rotation.
     */
    public function trip(string $acquirerKey, string $reason = 'manual'): void;
}
