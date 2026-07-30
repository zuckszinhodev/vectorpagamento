<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\Enums\RoutingStrategy;
use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;

/**
 * Orders eligible acquirers for a transaction.
 *
 * Implementations receive candidates that have ALREADY passed availability
 * filtering, so a strategy never has to worry about health, circuit state or
 * amount limits — it only ranks.
 *
 * Adding a new strategy means adding a class and tagging it in the container;
 * no existing file changes.
 */
interface RoutingStrategyInterface
{
    public function identifier(): RoutingStrategy;

    /**
     * @param array<int, AcquirerCandidate> $candidates
     * @return array<int, AcquirerCandidate> ordered, best first, each carrying its score reason
     */
    public function order(array $candidates, RoutingContext $context): array;
}
