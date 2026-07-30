<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

/**
 * Aggregated performance of one acquirer over the scoring window.
 *
 * Ratios are expressed in [0,1]; the panel converts to percentages.
 */
final class AcquirerMetrics
{
    public function __construct(
        public readonly string $acquirerKey,
        public readonly int $attempts = 0,
        public readonly int $successes = 0,
        public readonly int $failures = 0,
        public readonly int $timeouts = 0,
        public readonly int $fallbackIns = 0,
        public readonly int $fallbackOuts = 0,
        public readonly int $latencySumMs = 0,
        public readonly int $latencyMaxMs = 0,
        public readonly int $volumeCents = 0,
        public readonly int $windowMinutes = 15,
    ) {
    }

    public static function empty(string $acquirerKey, int $windowMinutes = 15): self
    {
        return new self($acquirerKey, windowMinutes: $windowMinutes);
    }

    /**
     * Approval rate. With no traffic we assume 1.0 so a brand-new acquirer is
     * not punished before it has processed anything.
     */
    public function approvalRate(): float
    {
        if ($this->attempts <= 0) {
            return 1.0;
        }

        return round($this->successes / $this->attempts, 4);
    }

    public function errorRate(): float
    {
        if ($this->attempts <= 0) {
            return 0.0;
        }

        return round($this->failures / $this->attempts, 4);
    }

    public function avgLatencyMs(): float
    {
        if ($this->attempts <= 0) {
            return 0.0;
        }

        return round($this->latencySumMs / $this->attempts, 2);
    }

    /**
     * Errors per minute over the window — the figure shown on the dashboard.
     */
    public function errorsPerMinute(): float
    {
        if ($this->windowMinutes <= 0) {
            return 0.0;
        }

        return round($this->failures / $this->windowMinutes, 2);
    }

    /**
     * Transactions per second observed over the window.
     */
    public function observedTps(): float
    {
        $seconds = max(1, $this->windowMinutes * 60);

        return round($this->attempts / $seconds, 4);
    }

    public function hasEnoughSamples(int $minSamples): bool
    {
        return $this->attempts >= $minSamples;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'acquirer_key' => $this->acquirerKey,
            'attempts' => $this->attempts,
            'successes' => $this->successes,
            'failures' => $this->failures,
            'timeouts' => $this->timeouts,
            'fallback_ins' => $this->fallbackIns,
            'fallback_outs' => $this->fallbackOuts,
            'approval_rate' => $this->approvalRate(),
            'error_rate' => $this->errorRate(),
            'avg_latency_ms' => $this->avgLatencyMs(),
            'max_latency_ms' => $this->latencyMaxMs,
            'errors_per_minute' => $this->errorsPerMinute(),
            'observed_tps' => $this->observedTps(),
            'volume_cents' => $this->volumeCents,
            'window_minutes' => $this->windowMinutes,
        ];
    }
}
