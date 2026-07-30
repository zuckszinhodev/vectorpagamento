<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use App\Domain\Routing\Contracts\RoutingStrategyInterface;
use App\Domain\Routing\Enums\RoutingStrategy;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;

/**
 * Sends traffic to whichever acquirer has the most headroom against its TPS
 * ceiling — the strategy to pick when the goal is to stay far from any
 * acquirer's rate limit rather than to optimise latency or approval.
 *
 * Acquirers with no ceiling configured report zero utilisation, so they
 * naturally absorb the overflow.
 */
final class LeastUtilizationStrategy implements RoutingStrategyInterface
{
    public function identifier(): RoutingStrategy
    {
        return RoutingStrategy::LEAST_UTILIZATION;
    }

    public function order(array $candidates, RoutingContext $context): array
    {
        $scored = array_map(static function (AcquirerCandidate $candidate): AcquirerCandidate {
            $utilization = $candidate->utilization();

            $reason = $candidate->maxTps === null || $candidate->maxTps <= 0
                ? sprintf('sem limite de TPS (%.2f TPS observado)', $candidate->currentTps)
                : sprintf(
                    'utilização %.1f%% (%.2f de %d TPS)',
                    $utilization * 100,
                    $candidate->currentTps,
                    $candidate->maxTps
                );

            return $candidate->withScore(1.0 - $utilization, $reason);
        }, $candidates);

        usort($scored, static fn (AcquirerCandidate $a, AcquirerCandidate $b): int => [-$a->score, $a->priority, $a->key] <=> [-$b->score, $b->priority, $b->key]);

        return array_values($scored);
    }
}
