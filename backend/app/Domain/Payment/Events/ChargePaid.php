<?php

declare(strict_types=1);

namespace Domain\Payment\Events;

use DateTimeImmutable;
use Domain\Shared\Contracts\DomainEvent;

final class ChargePaid extends DomainEvent
{
    private int $amount;
    private DateTimeImmutable $paidAt;

    public function __construct(string $chargeId, int $amount, DateTimeImmutable $paidAt)
    {
        parent::__construct($chargeId);
        $this->amount = $amount;
        $this->paidAt = $paidAt;
    }

    public function getEventName(): string
    {
        return 'charge.paid';
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getPaidAt(): DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function toArray(): array
    {
        return [
            'event' => $this->getEventName(),
            'charge_id' => $this->getAggregateId(),
            'amount' => $this->amount,
            'paid_at' => $this->paidAt->format('Y-m-d H:i:s'),
            'occurred_at' => $this->getOccurredAt()->format('Y-m-d H:i:s'),
        ];
    }
}
