<?php

declare(strict_types=1);

namespace App\Domain\Routing\Strategies;

use App\Domain\Routing\Contracts\RoutingStrategyInterface;
use App\Domain\Routing\Enums\RoutingStrategy;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;

/**
 * Routes to the acquirer approving the highest share of transactions.
 *
 * Uses the lower bound of a Wilson score interval instead of the raw ratio:
 * 3 approvals out of 3 is a 100% raw rate but a weak signal, and would
 * otherwise beat an acquirer at 97% over 2000 transactions. The Wilson bound
 * discounts small samples automatically, so no arbitrary cut-off is needed.
 */
final class BestApprovalRateStrategy implements RoutingStrategyInterface
{
    /** z for a 95% confidence interval. */
    private const Z = 1.96;

    public function __construct(
        private readonly int $minSamples = 20,
    ) {
    }

    public function identifier(): RoutingStrategy
    {
        return RoutingStrategy::BEST_APPROVAL;
    }

    public function order(array $candidates, RoutingContext $context): array
    {
        $scored = array_map(function (AcquirerCandidate $candidate): AcquirerCandidate {
            $attempts = $candidate->metrics->attempts;

            if ($attempts <= 0) {
                return $candidate->withScore(
                    0.5,
                    'sem histórico na janela — mantém prioridade configurada'
                );
            }

            $bound = $this->wilsonLowerBound($candidate->metrics->successes, $attempts);
            $note = $attempts < $this->minSamples ? ' (amostra pequena)' : '';

            return $candidate->withScore(
                $bound,
                sprintf(
                    'aprovação %.1f%% em %d transações, score ajustado %.3f%s',
                    $candidate->metrics->approvalRate() * 100,
                    $attempts,
                    $bound,
                    $note
                )
            );
        }, $candidates);

        usort($scored, static fn (AcquirerCandidate $a, AcquirerCandidate $b): int => [-$a->score, $a->priority, $a->key] <=> [-$b->score, $b->priority, $b->key]);

        return array_values($scored);
    }

    /**
     * Wilson score interval, lower bound.
     */
    private function wilsonLowerBound(int $successes, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        $phat = $successes / $total;
        $z2 = self::Z ** 2;

        $numerator = $phat + $z2 / (2 * $total)
            - self::Z * sqrt(($phat * (1 - $phat) + $z2 / (4 * $total)) / $total);
        $denominator = 1 + $z2 / $total;

        return round(max(0.0, $numerator / $denominator), 6);
    }
}
