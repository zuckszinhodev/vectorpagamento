<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\ValueObjects\AcquirerMetrics;

/**
 * Rolling performance counters per acquirer.
 *
 * Writes must be cheap: routing calls {@see self::recordAttempt()} on the
 * payment hot path, so the implementation aggregates into minute buckets
 * instead of inserting one row per transaction.
 */
interface MetricsRepositoryInterface
{
    public function recordAttempt(
        string $acquirerKey,
        PaymentMethod $method,
        bool $succeeded,
        int $latencyMs,
        int $amountCents = 0,
        bool $isTimeout = false,
        bool $isFallback = false,
    ): void;

    /**
     * Record that this acquirer handed the transaction over to a fallback.
     */
    public function recordFallbackOut(string $acquirerKey, PaymentMethod $method): void;

    /**
     * Record a candidate that was skipped before being called (circuit open,
     * TPS exceeded, amount out of range).
     */
    public function recordRejection(string $acquirerKey, PaymentMethod $method): void;

    /**
     * Aggregate over the scoring window for one acquirer.
     */
    public function forAcquirer(string $acquirerKey, PaymentMethod $method, ?int $windowMinutes = null): AcquirerMetrics;

    /**
     * Aggregate for many acquirers at once — one query instead of N.
     *
     * @param array<int, string> $acquirerKeys
     * @return array<string, AcquirerMetrics> keyed by acquirer key
     */
    public function forAcquirers(array $acquirerKeys, PaymentMethod $method, ?int $windowMinutes = null): array;

    /**
     * Aggregate across every payment method, for the dashboard.
     *
     * @return array<string, AcquirerMetrics>
     */
    public function overview(int $windowMinutes = 60): array;

    /**
     * Per-minute series for charts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeSeries(?string $acquirerKey, int $windowMinutes = 60): array;

    /**
     * Drop buckets older than the retention window.
     */
    public function prune(int $olderThanDays): int;
}
