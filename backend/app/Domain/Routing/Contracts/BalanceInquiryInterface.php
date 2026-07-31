<?php

declare(strict_types=1);

namespace App\Domain\Routing\Contracts;

use App\Domain\Routing\ValueObjects\AcquirerBalance;

/**
 * Optional capability: an acquirer that can report how much money it holds.
 *
 * Kept separate from {@see PaymentProviderInterface} because most acquirers do
 * not expose it, and forcing every module to stub it out would be noise. The
 * payout path uses it to split a withdrawal across nominal balances when no
 * single acquirer holds enough.
 */
interface BalanceInquiryInterface
{
    public function balance(): AcquirerBalance;
}
