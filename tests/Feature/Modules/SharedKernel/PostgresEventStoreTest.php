<?php

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

final class StoreFakeEvent extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly string $note = '',
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'store_fake.happened';
    }

    public function payload(): array
    {
        return ['note' => $this->note];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self(
            $row->aggregateId,
            $row->occurredAt,
            $row->actor,
            $row->causationId,
            $row->correlationId,
            $row->payload['note'],
        );
    }
}

function makeEventStore(): PostgresEventStore
{
    return new PostgresEventStore(['store_fake.happened' => StoreFakeEvent::class]);
}

it('appends events and loads them back in order', function () {
    $store = makeEventStore();
    $aggregateId = (string) Str::uuid();
    $correlationId = (string) Str::uuid();

    $store->append($aggregateId, 0, [
        new StoreFakeEvent($aggregateId, new DateTimeImmutable(), Actor::system(), (string) Str::uuid(), $correlationId, 'first'),
        new StoreFakeEvent($aggregateId, new DateTimeImmutable(), Actor::system(), (string) Str::uuid(), $correlationId, 'second'),
    ]);

    $events = $store->loadStream($aggregateId);

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(DomainEvent::class)
        ->and($events[0]->payload()['note'])->toBe('first')
        ->and($events[1]->payload()['note'])->toBe('second');
});

it('rejects an append whose expected version does not match', function () {
    $store = makeEventStore();
    $aggregateId = (string) Str::uuid();

    $store->append($aggregateId, 0, [
        new StoreFakeEvent($aggregateId, new DateTimeImmutable(), Actor::system(), (string) Str::uuid(), (string) Str::uuid()),
    ]);

    expect(fn () => $store->append($aggregateId, 0, [
        new StoreFakeEvent($aggregateId, new DateTimeImmutable(), Actor::system(), (string) Str::uuid(), (string) Str::uuid()),
    ]))->toThrow(ConcurrencyConflictException::class);
});

it('returns an empty stream for an aggregate with no events', function () {
    expect(makeEventStore()->loadStream((string) Str::uuid()))->toBe([]);
});
