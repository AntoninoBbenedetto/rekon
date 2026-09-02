<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Infrastructure\EventStore;

use App\Modules\SharedKernel\Domain\Actor;
use DateTimeImmutable;

final class StoredEventRow
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $version,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly DateTimeImmutable $occurredAt,
        public readonly Actor $actor,
        public readonly string $causationId,
        public readonly string $correlationId,
    ) {}
}
