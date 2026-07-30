<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use App\Domain\Routing\Contracts\RotationCursorInterface;
use App\Domain\Routing\Contracts\RoutingStrategyInterface;
use App\Domain\Routing\Enums\RoutingStrategy;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;

/**
 * Round robin proportional to each acquirer's weight.
 *
 * Uses interleaved (smooth) expansion rather than a naive
 * "AAA BB C" slot list: with weights 3/2/1 the resulting order is
 * A B A C A B instead of A A A B B C, so bursts are spread evenly in time
 * instead of hammering one acquirer for three requests in a row.
 */
final class WeightedRoundRobinStrategy implements RoutingStrategyInterface
{
    private const MAX_WEIGHT = 100;

    public function __construct(
        private readonly RotationCursorInterface $cursor,
    ) {
    }

    public function identifier(): RoutingStrategy
    {
        return RoutingStrategy::WEIGHTED;
    }

    public function order(array $candidates, RoutingContext $context): array
    {
        if ($candidates === []) {
            return [];
        }

        usort($candidates, static fn (AcquirerCandidate $a, AcquirerCandidate $b): int => [-$a->weight, $a->priority, $a->key] <=> [-$b->weight, $b->priority, $b->key]);
        $candidates = array_values($candidates);

        $slots = $this->buildSmoothSlots($candidates);

        if ($slots === []) {
            return $candidates;
        }

        $offset = $this->cursor->next($this->cursorKey($context)) % count($slots);
        $primaryKey = $slots[$offset];

        // Primary first, everyone else keeps weight order as the fallback chain.
        $ordered = [];
        foreach ($candidates as $candidate) {
            if ($candidate->key === $primaryKey) {
                array_unshift($ordered, $candidate);
                continue;
            }

            $ordered[] = $candidate;
        }

        $total = count($ordered);
        $totalWeight = array_sum(array_map(static fn (AcquirerCandidate $c): int => max(1, $c->weight), $ordered));

        return array_values(array_map(
            static function (AcquirerCandidate $c, int $index) use ($total, $totalWeight): AcquirerCandidate {
                $share = $totalWeight > 0 ? round((max(1, $c->weight) / $totalWeight) * 100, 1) : 0.0;

                return $c->withScore(
                    (float) ($total - $index),
                    $index === 0
                        ? sprintf('weighted round robin: peso %d (%.1f%% do tráfego)', $c->weight, $share)
                        : sprintf('fallback ponderado: peso %d', $c->weight)
                );
            },
            $ordered,
            array_keys($ordered)
        ));
    }

    /**
     * Interleaved expansion of the weights.
     *
     * Each acquirer is emitted once per "round" until its weight is exhausted,
     * which spreads its slots across the cycle instead of clustering them.
     *
     * @param array<int, AcquirerCandidate> $candidates
     * @return array<int, string>
     */
    private function buildSmoothSlots(array $candidates): array
    {
        $remaining = [];
        $maxWeight = 0;

        foreach ($candidates as $candidate) {
            $weight = max(1, min(self::MAX_WEIGHT, $candidate->weight));
            $remaining[$candidate->key] = $weight;
            $maxWeight = max($maxWeight, $weight);
        }

        $slots = [];

        for ($round = 0; $round < $maxWeight; $round++) {
            foreach ($remaining as $key => $weight) {
                if ($weight > $round) {
                    $slots[] = $key;
                }
            }
        }

        return $slots;
    }

    private function cursorKey(RoutingContext $context): string
    {
        return sprintf('wrr:%s:%s', $context->method->value, $context->accountId ?? 'global');
    }
}
