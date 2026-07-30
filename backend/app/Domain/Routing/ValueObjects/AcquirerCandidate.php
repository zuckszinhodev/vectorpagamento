<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

use App\Domain\Routing\Enums\CircuitState;
use App\Domain\Routing\Enums\HealthState;

/**
 * An acquirer being considered for one transaction, with everything the
 * strategies need to rank it: static configuration, live health and metrics.
 *
 * Built once per routing decision, then passed through the strategy pipeline.
 */
final class AcquirerCandidate
{
    public function __construct(
        public readonly string $key,
        public readonly string $displayName,
        public readonly string $configId,
        public readonly int $priority,
        public readonly int $weight,
        public readonly HealthState $health,
        public readonly CircuitState $circuit,
        public readonly AcquirerMetrics $metrics,
        public readonly ?int $maxTps = null,
        public readonly ?int $maxConcurrent = null,
        public readonly int $timeoutMs = 15000,
        public readonly int $connectTimeoutMs = 3000,
        public readonly int $maxRetries = 1,
        public readonly ?int $minAmountCents = null,
        public readonly ?int $maxAmountCents = null,
        public readonly float $currentTps = 0.0,
        /** @var array<string, mixed> */
        public readonly array $rules = [],
        /** Score assigned by the active strategy; higher wins. */
        public readonly float $score = 0.0,
        /** Human-readable justification recorded in the routing log. */
        public readonly string $scoreReason = '',
    ) {
    }

    public function withScore(float $score, string $reason): self
    {
        return $this->copyWith(['score' => $score, 'scoreReason' => $reason]);
    }

    public function withCurrentTps(float $tps): self
    {
        return $this->copyWith(['currentTps' => $tps]);
    }

    /**
     * Whether this acquirer is allowed to take traffic right now.
     *
     * An OPEN circuit or an OFFLINE health state is a hard no — the router
     * never "relaxes" this rule, even when it leaves zero candidates.
     */
    public function isRoutable(bool $strictHealthCheck = true): bool
    {
        if (!$this->circuit->allowsTraffic()) {
            return false;
        }

        if ($strictHealthCheck && !$this->health->isRoutable()) {
            return false;
        }

        return true;
    }

    public function acceptsAmount(int $amountCents): bool
    {
        if ($this->minAmountCents !== null && $amountCents < $this->minAmountCents) {
            return false;
        }

        if ($this->maxAmountCents !== null && $amountCents > $this->maxAmountCents) {
            return false;
        }

        return true;
    }

    /**
     * Utilisation against the configured TPS ceiling, in [0,1].
     * Returns 0 when no ceiling is set — an unlimited acquirer is never
     * considered "busy" by the least-utilization strategy.
     */
    public function utilization(): float
    {
        if ($this->maxTps === null || $this->maxTps <= 0) {
            return 0.0;
        }

        return min(1.0, round($this->currentTps / $this->maxTps, 4));
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'acquirer' => $this->key,
            'priority' => $this->priority,
            'weight' => $this->weight,
            'health' => $this->health->value,
            'circuit' => $this->circuit->value,
            'score' => round($this->score, 4),
            'reason' => $this->scoreReason,
            'approval_rate' => $this->metrics->approvalRate(),
            'avg_latency_ms' => $this->metrics->avgLatencyMs(),
            'utilization' => $this->utilization(),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copyWith(array $overrides): self
    {
        return new self(
            key: $overrides['key'] ?? $this->key,
            displayName: $overrides['displayName'] ?? $this->displayName,
            configId: $overrides['configId'] ?? $this->configId,
            priority: $overrides['priority'] ?? $this->priority,
            weight: $overrides['weight'] ?? $this->weight,
            health: $overrides['health'] ?? $this->health,
            circuit: $overrides['circuit'] ?? $this->circuit,
            metrics: $overrides['metrics'] ?? $this->metrics,
            maxTps: $overrides['maxTps'] ?? $this->maxTps,
            maxConcurrent: $overrides['maxConcurrent'] ?? $this->maxConcurrent,
            timeoutMs: $overrides['timeoutMs'] ?? $this->timeoutMs,
            connectTimeoutMs: $overrides['connectTimeoutMs'] ?? $this->connectTimeoutMs,
            maxRetries: $overrides['maxRetries'] ?? $this->maxRetries,
            minAmountCents: $overrides['minAmountCents'] ?? $this->minAmountCents,
            maxAmountCents: $overrides['maxAmountCents'] ?? $this->maxAmountCents,
            currentTps: $overrides['currentTps'] ?? $this->currentTps,
            rules: $overrides['rules'] ?? $this->rules,
            score: $overrides['score'] ?? $this->score,
            scoreReason: $overrides['scoreReason'] ?? $this->scoreReason,
        );
    }
}
