<?php

declare(strict_types=1);

namespace Domain\Payment\Events;

use Domain\Shared\Contracts\DomainEvent;

final class ChargeExpired extends DomainEvent
{
    public function __construct(string $chargeId)
    {
        parent::__construct($chargeId);
    }

    public function getEventName(): string
    {
        return 'charge.expired';
    }

    public function toArray(): array
    {
        return [
            'event' => $this->getEventName(),
            'charge_id' => $this->getAggregateId(),
            'occurred_at' => $this->getOccurredAt()->format('Y-m-d H:i:s'),
        ];
    }
}
