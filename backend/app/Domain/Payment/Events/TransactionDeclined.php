<?php

declare(strict_types=1);

namespace Domain\Payment\Events;

use Domain\Shared\Contracts\DomainEvent;

final class TransactionDeclined extends DomainEvent
{
    private string $chargeId;
    private string $errorCode;
    private string $errorMessage;

    public function __construct(
        string $transactionId,
        string $chargeId,
        string $errorCode,
        string $errorMessage
    ) {
        parent::__construct($transactionId);
        $this->chargeId = $chargeId;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    public function getEventName(): string
    {
        return 'transaction.declined';
    }

    public function getChargeId(): string
    {
        return $this->chargeId;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function toArray(): array
    {
        return [
            'event' => $this->getEventName(),
            'transaction_id' => $this->getAggregateId(),
            'charge_id' => $this->chargeId,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'occurred_at' => $this->getOccurredAt()->format('Y-m-d H:i:s'),
        ];
    }
}
