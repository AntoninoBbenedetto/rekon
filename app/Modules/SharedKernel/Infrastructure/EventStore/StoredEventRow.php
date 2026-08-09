<?php

namespace App\Modules\SharedKernel\Infrastructure\EventStore;

use App\Modules\SharedKernel\Domain\Actor;
use DateTimeImmutable;

final class StoredEventRow
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $version,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly DateTimeImmutable $occurredAt,
        public readonly Actor $actor,
        public readonly string $causationId,
        public readonly string $correlationId,
    ) {
    }
}
