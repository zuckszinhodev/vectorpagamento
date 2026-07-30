<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use App\Domain\Routing\Contracts\RotationCursorInterface;
use App\Domain\Routing\Contracts\RoutingStrategyInterface;
use App\Domain\Routing\Enums\RoutingStrategy;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;

/**
 * Even distribution: each transaction starts one position further along the
 * candidate list than the previous one.
 *
 * The list is sorted deterministically before rotating so every worker agrees
 * on what "position N" means — otherwise the cursor would be meaningless.
 */
final class RoundRobinStrategy implements RoutingStrategyInterface
{
    public function __construct(
        private readonly RotationCursorInterface $cursor,
    ) {
    }

    public function identifier(): RoutingStrategy
    {
        return RoutingStrategy::ROUND_ROBIN;
    }

    public function order(array $candidates, RoutingContext $context): array
    {
        if ($candidates === []) {
            return [];
        }

        $candidates = $this->sortDeterministically($candidates);
        $total = count($candidates);

        $offset = $this->cursor->next($this->cursorKey($context)) % $total;

        // Rotate: the chosen primary first, the rest keep their relative order
        // so the fallback chain stays predictable.
        $rotated = array_merge(
            array_slice($candidates, $offset),
            array_slice($candidates, 0, $offset)
        );

        return array_values(array_map(
            static fn (AcquirerCandidate $c, int $index): AcquirerCandidate => $c->withScore(
                (float) ($total - $index),
                $index === 0
                    ? sprintf('round robin: posição %d de %d', $offset + 1, $total)
                    : sprintf('fallback round robin (posição %d)', $index + 1)
            ),
            $rotated,
            array_keys($rotated)
        ));
    }

    /**
     * @param array<int, AcquirerCandidate> $candidates
     * @return array<int, AcquirerCandidate>
     */
    private function sortDeterministically(array $candidates): array
    {
        usort($candidates, static fn (AcquirerCandidate $a, AcquirerCandidate $b): int => [$a->priority, $a->key] <=> [$b->priority, $b->key]);

        return array_values($candidates);
    }

    /**
     * Cursors are scoped per method and per establishment, so one seller's
     * traffic does not skew another's rotation.
     */
    private function cursorKey(RoutingContext $context): string
    {
        return sprintf('rr:%s:%s', $context->method->value, $context->accountId ?? 'global');
    }
}
