<?php

declare(strict_types=1);

namespace App\Domain\Routing\Exceptions;

use App\Domain\Routing\Enums\FailureReason;

/**
 * A single acquirer attempt failed.
 *
 * The router catches this, records it, and moves on to the next candidate —
 * it never surfaces to the customer.
 */
final class ProviderException extends RoutingException
{
    public function __construct(
        string $message,
        public readonly FailureReason $reason = FailureReason::PROVIDER_ERROR,
        public readonly ?string $acquirerKey = null,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $reason->value, $context, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->reason->isRetryable();
    }
}
