<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker Pattern for Acquirer Resilience
 *
 * States:
 *   CLOSED  → requests pass through; failures tracked
 *   OPEN    → requests immediately fail (fast-fail); retries after cooldown
 *   HALF_OPEN → allows one probe request; success = close, failure = re-open
 *
 * Persisted in Redis/Cache so state survives process restarts.
 */
class CircuitBreaker
{
    private const STATE_CLOSED    = 'closed';
    private const STATE_OPEN      = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private int $failureThreshold;
    private int $cooldownSeconds;
    private string $service;

    /**
     * @param string $service       Unique key, e.g. "acquirer:turbos"
     * @param int    $threshold     Number of consecutive failures before opening
     * @param int    $cooldownSecs  Seconds to wait before moving to half-open
     */
    public function __construct(string $service, int $threshold = 3, int $cooldownSecs = 60)
    {
        $this->service          = "circuit_breaker:{$service}";
        $this->failureThreshold = $threshold;
        $this->cooldownSeconds  = $cooldownSecs;
    }

    /**
     * Check whether the circuit allows a request.
     */
    public function isAvailable(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            // Check if cooldown has elapsed → move to half-open
            $openedAt = (int) Cache::get("{$this->service}:opened_at", 0);
            if (time() - $openedAt >= $this->cooldownSeconds) {
                $this->setState(self::STATE_HALF_OPEN);
                return true; // allow one probe
            }
            return false;
        }

        // HALF_OPEN → allow one probe
        return true;
    }

    /**
     * Record a successful call — reset failures, close circuit.
     */
    public function recordSuccess(): void
    {
        Cache::put("{$this->service}:failures", 0, now()->addHours(1));
        $this->setState(self::STATE_CLOSED);
    }

    /**
     * Record a failed call — increment failures, potentially open circuit.
     */
    public function recordFailure(): void
    {
        $failures = (int) Cache::get("{$this->service}:failures", 0) + 1;
        Cache::put("{$this->service}:failures", $failures, now()->addHours(1));

        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            // Probe failed → re-open
            $this->trip();
            return;
        }

        if ($failures >= $this->failureThreshold) {
            $this->trip();
        }
    }

    /**
     * Execute a callable through the circuit breaker.
     *
     * @param callable $action     The operation to execute
     * @param callable|null $fallback  Optional fallback when circuit is open
     * @return mixed
     *
     * @throws \RuntimeException when circuit is open and no fallback provided
     */
    public function call(callable $action, ?callable $fallback = null): mixed
    {
        if (!$this->isAvailable()) {
            Log::warning("Circuit breaker OPEN for {$this->service} — fast-failing");

            if ($fallback) {
                return $fallback();
            }

            throw new \RuntimeException("Circuit breaker is open for {$this->service}");
        }

        try {
            $result = $action();
            $this->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            Log::warning("Circuit breaker failure for {$this->service}", [
                'error'    => $e->getMessage(),
                'failures' => Cache::get("{$this->service}:failures", 0),
            ]);
            throw $e;
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function trip(): void
    {
        $this->setState(self::STATE_OPEN);
        Cache::put("{$this->service}:opened_at", time(), now()->addHours(1));

        Log::error("Circuit breaker OPENED for {$this->service}", [
            'failures' => Cache::get("{$this->service}:failures", 0),
            'cooldown' => $this->cooldownSeconds,
        ]);
    }

    private function getState(): string
    {
        return Cache::get("{$this->service}:state", self::STATE_CLOSED);
    }

    private function setState(string $state): void
    {
        Cache::put("{$this->service}:state", $state, now()->addHours(1));
    }
}
