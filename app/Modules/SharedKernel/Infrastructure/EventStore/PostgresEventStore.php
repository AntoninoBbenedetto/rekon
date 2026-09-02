<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Infrastructure\EventStore;

use App\Modules\SharedKernel\Application\EventStore;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\ActorType;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostgresEventStore implements EventStore
{
    /** @param array<string, class-string<DomainEvent>> $eventClassesByType */
    public function __construct(private readonly array $eventClassesByType)
    {
    }

    public function append(string $aggregateId, int $expectedVersion, array $events): void
    {
        DB::transaction(function () use ($aggregateId, $expectedVersion, $events) {
            $version = $expectedVersion;

            foreach ($events as $event) {
                $version++;

                try {
                    DB::table('event_store')->insert([
                        'aggregate_id' => $aggregateId,
                        'version' => $version,
                        'event_type' => $event->eventType(),
                        'schema_version' => 1,
                        'payload' => json_encode($event->payload()),
                        'occurred_at' => $event->occurredAt(),
                        'actor_type' => $event->actor()->type->value,
                        'actor_id' => $event->actor()->id,
                        'causation_id' => $event->causationId(),
                        'correlation_id' => $event->correlationId(),
                        'recorded_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    throw new ConcurrencyConflictException($aggregateId, $version, $e);
                }
            }
        });
    }

    public function loadStream(string $aggregateId): array
    {
        $rows = DB::table('event_store')
            ->where('aggregate_id', $aggregateId)
            ->orderBy('version')
            ->get();

        return $rows->map(function ($row) {
            $class = $this->eventClassesByType[$row->event_type]
                ?? throw new RuntimeException("Unknown event type: {$row->event_type}");

            $actor = $row->actor_type === ActorType::System->value
                ? Actor::system()
                : Actor::apiCaller($row->actor_id);

            return $class::fromStoredRow(new StoredEventRow(
                aggregateId: $row->aggregate_id,
                version: $row->version,
                eventType: $row->event_type,
                payload: json_decode($row->payload, true),
                occurredAt: new DateTimeImmutable($row->occurred_at),
                actor: $actor,
                causationId: $row->causation_id,
                correlationId: $row->correlation_id,
            ));
        })->all();
    }
}
