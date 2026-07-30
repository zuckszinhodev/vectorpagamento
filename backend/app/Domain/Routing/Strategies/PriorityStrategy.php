<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use App\Domain\Routing\Contracts\RoutingStrategyInterface;
use App\Domain\Routing\Enums\RoutingStrategy;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;

/**
 * Strict priority order: the lowest `priority` value wins.
 *
 * Ties break by weight (heavier first) and then alphabetically, so the order
 * is fully deterministic across workers.
 */
final class PriorityStrategy implements RoutingStrategyInterface
{
    public function identifier(): RoutingStrategy
    {
        return RoutingStrategy::PRIORITY;
    }

    public function order(array $candidates, RoutingContext $context): array
    {
        usort($candidates, static function (AcquirerCandidate $a, AcquirerCandidate $b): int {
            return [$a->priority, -$a->weight, $a->key] <=> [$b->priority, -$b->weight, $b->key];
        });

        $total = count($candidates);

        return array_values(array_map(
            static fn (AcquirerCandidate $c, int $index): AcquirerCandidate => $c->withScore(
                // Descending score so "higher is better" holds for every strategy.
                (float) ($total - $index),
                sprintf('prioridade %d (posição %d de %d)', $c->priority, $index + 1, $total)
            ),
            $candidates,
            array_keys($candidates)
        ));
    }
}
