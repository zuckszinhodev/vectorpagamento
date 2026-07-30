<?php

declare(strict_types=1);

namespace App\Domain\Routing\Exceptions;

/**
 * No acquirer passed the eligibility filters for this transaction.
 *
 * This is a hard stop: the router never falls back to an acquirer it has
 * already ruled out as unavailable.
 */
final class NoAcquirerAvailableException extends RoutingException
{
    public function __construct(string $message = 'Nenhuma adquirente disponível para esta transação.', array $context = [])
    {
        parent::__construct($message, 'no_acquirer_available', $context);
    }

    public function publicMessage(): string
    {
        return 'Nenhuma adquirente disponível para processar o pagamento no momento. Tente novamente em instantes.';
    }
}
