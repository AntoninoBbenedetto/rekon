<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Domain;

use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

interface DomainEvent
{
    public function aggregateId(): string;

    public function eventType(): string;

    public function occurredAt(): DateTimeImmutable;

    public function actor(): Actor;

    public function causationId(): string;

    public function correlationId(): string;

    /** @return array<string, mixed> */
    public function payload(): array;

    public static function fromStoredRow(StoredEventRow $row): static;
}
