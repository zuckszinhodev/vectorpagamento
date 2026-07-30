<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

/**
 * Enforces the per-acquirer TPS ceiling and concurrency limit.
 *
 * The router asks before dispatching; when an acquirer is saturated it is
 * skipped and the next candidate takes the transaction, which is exactly the
 * behaviour a TPS limit should produce — shed load sideways, not drop it.
 */
interface RateLimiterInterface
{
    /**
     * Try to consume one slot. Returns false when the acquirer is at its limit.
     */
    public function tryAcquire(string $acquirerKey, ?int $maxTps, ?int $maxConcurrent = null): bool;

    /**
     * Release a concurrency slot taken by {@see self::tryAcquire()}.
     */
    public function release(string $acquirerKey): void;

    /**
     * Transactions per second currently observed for this acquirer.
     */
    public function currentTps(string $acquirerKey): float;

    /**
     * In-flight requests for this acquirer.
     */
    public function currentConcurrency(string $acquirerKey): int;
}
