<?php

declare(strict_types=1);

namespace Domain\Auth\Events;

use Domain\Shared\Contracts\DomainEvent;

final class UserCreated extends DomainEvent
{
    private string $email;
    private string $name;

    public function __construct(string $userId, string $email, string $name)
    {
        parent::__construct($userId);
        $this->email = $email;
        $this->name = $name;
    }

    public function getEventName(): string
    {
        return 'user.created';
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function toArray(): array
    {
        return [
            'event' => $this->getEventName(),
            'user_id' => $this->getAggregateId(),
            'email' => $this->email,
            'name' => $this->name,
            'occurred_at' => $this->getOccurredAt()->format('Y-m-d H:i:s'),
        ];
    }
}
