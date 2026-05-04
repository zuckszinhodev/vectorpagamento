<?php

declare(strict_types=1);

namespace Domain\Shared\Contracts;

use DateTimeImmutable;
use Domain\Shared\ValueObjects\Uuid;

abstract class DomainEvent
{
    private Uuid $eventId;
    private DateTimeImmutable $occurredAt;
    private string $aggregateId;

    public function __construct(string $aggregateId)
    {
        $this->eventId = Uuid::generate();
        $this->occurredAt = new DateTimeImmutable();
        $this->aggregateId = $aggregateId;
    }

    public function getEventId(): Uuid
    {
        return $this->eventId;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    abstract public function getEventName(): string;

    abstract public function toArray(): array;
}
