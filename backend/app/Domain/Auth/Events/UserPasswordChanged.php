<?php

declare(strict_types=1);

namespace Domain\Auth\Events;

use Domain\Shared\Contracts\DomainEvent;

final class UserPasswordChanged extends DomainEvent
{
    public function __construct(string $userId)
    {
        parent::__construct($userId);
    }

    public function getEventName(): string
    {
        return 'user.password_changed';
    }

    public function toArray(): array
    {
        return [
            'event' => $this->getEventName(),
            'user_id' => $this->getAggregateId(),
            'occurred_at' => $this->getOccurredAt()->format('Y-m-d H:i:s'),
        ];
    }
}
