<?php

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;

final class FakeEvent extends AbstractDomainEvent
{
    public function eventType(): string
    {
        return 'fake.happened';
    }

    public function payload(): array
    {
        return ['note' => 'hello'];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self($row->aggregateId, $row->occurredAt, $row->actor, $row->causationId, $row->correlationId);
    }
}

it('exposes the envelope fields it was constructed with', function () {
    $occurredAt = new DateTimeImmutable('2026-08-01T10:00:00+00:00');
    $actor = Actor::system();

    $event = new FakeEvent('agg-1', $occurredAt, $actor, 'causation-1', 'correlation-1');

    expect($event)->toBeInstanceOf(DomainEvent::class)
        ->and($event->aggregateId())->toBe('agg-1')
        ->and($event->occurredAt())->toBe($occurredAt)
        ->and($event->actor())->toBe($actor)
        ->and($event->causationId())->toBe('causation-1')
        ->and($event->correlationId())->toBe('correlation-1')
        ->and($event->eventType())->toBe('fake.happened')
        ->and($event->payload())->toBe(['note' => 'hello']);
});

it('reconstructs from a StoredEventRow', function () {
    $row = new StoredEventRow(
        aggregateId: 'agg-1',
        version: 1,
        eventType: 'fake.happened',
        payload: ['note' => 'hello'],
        occurredAt: new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        actor: Actor::system(),
        causationId: 'causation-1',
        correlationId: 'correlation-1',
    );

    $event = FakeEvent::fromStoredRow($row);

    expect($event->aggregateId())->toBe('agg-1');
});
