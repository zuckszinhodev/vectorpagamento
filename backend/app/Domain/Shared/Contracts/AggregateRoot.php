<?php

declare(strict_types=1);

namespace Domain\Shared\Contracts;

use Domain\Shared\ValueObjects\Uuid;

abstract class AggregateRoot
{
    protected Uuid $id;
    protected array $domainEvents = [];

    public function getId(): Uuid
    {
        return $this->id;
    }

    protected function recordEvent(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    public function hasDomainEvents(): bool
    {
        return count($this->domainEvents) > 0;
    }
}
