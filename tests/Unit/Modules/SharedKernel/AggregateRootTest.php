<?php

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\AggregateRoot;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;

final class FakeIncrementedEvent extends AbstractDomainEvent
{
    public function eventType(): string
    {
        return 'fake.incremented';
    }

    public function payload(): array
    {
        return [];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self($row->aggregateId, $row->occurredAt, $row->actor, $row->causationId, $row->correlationId);
    }
}

final class FakeCounter extends AggregateRoot
{
    private string $id;
    private int $count = 0;

    public static function start(string $id): self
    {
        $counter = self::createEmpty();
        $counter->id = $id;
        $counter->record(new FakeIncrementedEvent($id, new DateTimeImmutable(), Actor::system(), 'c1', 'r1'));

        return $counter;
    }

    public function incrementAgain(): void
    {
        $this->record(new FakeIncrementedEvent($this->id, new DateTimeImmutable(), Actor::system(), 'c2', 'r1'));
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function count(): int
    {
        return $this->count;
    }

    protected function apply(DomainEvent $event): void
    {
        $this->id = $event->aggregateId();
        $this->count++;
    }

    protected static function createEmpty(): static
    {
        return new self();
    }
}

it('applies recorded events immediately and tracks version', function () {
    $counter = FakeCounter::start('agg-1');

    expect($counter->count())->toBe(1)
        ->and($counter->version())->toBe(1);

    $counter->incrementAgain();

    expect($counter->count())->toBe(2)
        ->and($counter->version())->toBe(2);
});

it('releases recorded events exactly once', function () {
    $counter = FakeCounter::start('agg-1');
    $counter->incrementAgain();

    $events = $counter->releaseEvents();

    expect($events)->toHaveCount(2)
        ->and($counter->releaseEvents())->toHaveCount(0);
});

it('reconstitutes state and version by replaying a stream', function () {
    $events = [
        new FakeIncrementedEvent('agg-1', new DateTimeImmutable(), Actor::system(), 'c1', 'r1'),
        new FakeIncrementedEvent('agg-1', new DateTimeImmutable(), Actor::system(), 'c2', 'r1'),
        new FakeIncrementedEvent('agg-1', new DateTimeImmutable(), Actor::system(), 'c3', 'r1'),
    ];

    $counter = FakeCounter::reconstituteFromStream($events);

    expect($counter->count())->toBe(3)
        ->and($counter->version())->toBe(3)
        ->and($counter->releaseEvents())->toHaveCount(0);
});
