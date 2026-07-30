<?php

declare(strict_types=1);

namespace App\Domain\Routing\Exceptions;

/**
 * Base class for every failure raised by the routing engine.
 *
 * Carries a machine-readable code plus a message that is safe to show to the
 * end customer — intermediate acquirer attempts are never exposed.
 */
class RoutingException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'routing_error',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Message safe to return through the public API.
     */
    public function publicMessage(): string
    {
        return 'Não foi possível processar o pagamento no momento. Tente novamente.';
    }
}
