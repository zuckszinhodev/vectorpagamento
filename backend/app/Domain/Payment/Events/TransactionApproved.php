<?php

declare(strict_types=1);

namespace Domain\Payment\Events;

use Domain\Shared\Contracts\DomainEvent;

final class TransactionApproved extends DomainEvent
{
    private string $chargeId;
    private int $amount;
    private string $authorizationCode;

    public function __construct(
        string $transactionId,
        string $chargeId,
        int $amount,
        string $authorizationCode
    ) {
        parent::__construct($transactionId);
        $this->chargeId = $chargeId;
        $this->amount = $amount;
        $this->authorizationCode = $authorizationCode;
    }

    public function getEventName(): string
    {
        return 'transaction.approved';
    }

    public function getChargeId(): string
    {
        return $this->chargeId;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getAuthorizationCode(): string
    {
        return $this->authorizationCode;
    }

    public function toArray(): array
    {
        return [
            'event' => $this->getEventName(),
            'transaction_id' => $this->getAggregateId(),
            'charge_id' => $this->chargeId,
            'amount' => $this->amount,
            'authorization_code' => $this->authorizationCode,
            'occurred_at' => $this->getOccurredAt()->format('Y-m-d H:i:s'),
        ];
    }
}
