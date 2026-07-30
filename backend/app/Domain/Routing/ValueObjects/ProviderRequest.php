<?php

declare(strict_types=1);

namespace App\Domain\Routing\ValueObjects;

use App\Domain\Routing\Enums\PaymentMethod;
use App\Models\Charge;

/**
 * One operation to execute against one acquirer.
 *
 * Carries the effective timeouts for this attempt, which come from the
 * acquirer's routing profile rather than being hard-coded inside each
 * acquirer class as they were before.
 */
final class ProviderRequest
{
    /**
     * @param array<string, mixed> $payload card data for a card charge,
     *                                      transfer data for a payout
     */
    public function __construct(
        public readonly PaymentMethod $method,
        public readonly RoutingContext $context,
        public readonly ?Charge $charge = null,
        public readonly array $payload = [],
        public readonly int $timeoutMs = 15000,
        public readonly int $connectTimeoutMs = 3000,
    ) {
    }

    public function withTimeouts(int $timeoutMs, int $connectTimeoutMs): self
    {
        return new self(
            method: $this->method,
            context: $this->context,
            charge: $this->charge,
            payload: $this->payload,
            timeoutMs: $timeoutMs,
            connectTimeoutMs: $connectTimeoutMs,
        );
    }

    public function amountCents(): int
    {
        return $this->context->amountCents;
    }

    /**
     * Whole seconds, rounded up — Laravel's HTTP client timeouts are typed
     * `int`, and truncating a sub-second budget to 0 disables the timeout.
     */
    public function timeoutSeconds(): int
    {
        return max(1, (int) ceil($this->timeoutMs / 1000));
    }

    public function connectTimeoutSeconds(): int
    {
        return max(1, (int) ceil($this->connectTimeoutMs / 1000));
    }
}
