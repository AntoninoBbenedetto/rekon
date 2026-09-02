<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Domain;

use DateTimeImmutable;

abstract class AbstractDomainEvent implements DomainEvent
{
    public function __construct(
        private readonly string $aggregateId,
        private readonly DateTimeImmutable $occurredAt,
        private readonly Actor $actor,
        private readonly string $causationId,
        private readonly string $correlationId,
    ) {}

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actor(): Actor
    {
        return $this->actor;
    }

    public function causationId(): string
    {
        return $this->causationId;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }
}
