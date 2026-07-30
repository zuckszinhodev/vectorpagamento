<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use App\Domain\Routing\Contracts\RoutingStrategyInterface;
use App\Domain\Routing\Enums\RoutingStrategy;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;

/**
 * Routes to whichever acquirer has been answering fastest.
 *
 * Acquirers without enough traffic in the window fall back to priority order
 * rather than being ranked on a one-sample average — otherwise a single lucky
 * request would promote an unproven acquirer to primary.
 */
final class LeastLatencyStrategy implements RoutingStrategyInterface
{
    public function __construct(
        private readonly int $minSamples = 20,
        private readonly int $latencyCeilingMs = 8000,
    ) {
    }

    public function identifier(): RoutingStrategy
    {
        return RoutingStrategy::LEAST_LATENCY;
    }

    public function order(array $candidates, RoutingContext $context): array
    {
        $scored = array_map(function (AcquirerCandidate $candidate): AcquirerCandidate {
            if (!$candidate->metrics->hasEnoughSamples($this->minSamples)) {
                // Below the sample floor: rank by priority, but always under
                // any acquirer that does have measured latency.
                return $candidate->withScore(
                    $this->priorityScore($candidate) * 0.5,
                    sprintf('amostra insuficiente (%d/%d) — ordenado por prioridade', $candidate->metrics->attempts, $this->minSamples)
                );
            }

            $latency = $candidate->metrics->avgLatencyMs();
            $normalized = 1.0 - min(1.0, $latency / max(1, $this->latencyCeilingMs));

            return $candidate->withScore(
                // Offset keeps measured acquirers above unmeasured ones.
                1.0 + $normalized,
                sprintf('latência média %.0f ms sobre %d transações', $latency, $candidate->metrics->attempts)
            );
        }, $candidates);

        usort($scored, static fn (AcquirerCandidate $a, AcquirerCandidate $b): int => [-$a->score, $a->priority, $a->key] <=> [-$b->score, $b->priority, $b->key]);

        return array_values($scored);
    }

    private function priorityScore(AcquirerCandidate $candidate): float
    {
        // Lower priority number = better. Map into (0,1].
        return 1.0 / max(1, $candidate->priority + 1);
    }
}
