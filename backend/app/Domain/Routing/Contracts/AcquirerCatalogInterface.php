<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\ValueObjects\AcquirerCandidate;
use App\Domain\Routing\ValueObjects\RoutingContext;

/**
 * Builds the candidate list for a transaction: every registered acquirer that
 * supports the payment method, enriched with its live health and metrics.
 *
 * Filtering by availability is NOT done here — the catalog reports reality,
 * the router decides what to do with it.
 */
interface AcquirerCatalogInterface
{
    /**
     * @return array<int, AcquirerCandidate>
     */
    public function candidatesFor(RoutingContext $context): array;

    /**
     * Every acquirer known to the routing engine, regardless of method,
     * for the admin dashboard.
     *
     * @return array<int, AcquirerCandidate>
     */
    public function all(): array;

    /**
     * Drop any cached catalog data (called when an acquirer is edited).
     */
    public function flush(): void;
}
