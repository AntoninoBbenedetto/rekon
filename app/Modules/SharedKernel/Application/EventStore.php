<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Application;

use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;

interface EventStore
{
    /**
     * @param  DomainEvent[]  $events
     *
     * @throws ConcurrencyConflictException
     */
    public function append(string $aggregateId, int $expectedVersion, array $events): void;

    /** @return DomainEvent[] */
    public function loadStream(string $aggregateId): array;
}
