<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Domain;

abstract class AggregateRoot
{
    /** @var DomainEvent[] */
    private array $recordedEvents = [];

    private int $version = 0;

    abstract public function aggregateId(): string;

    protected function record(DomainEvent $event): void
    {
        $this->apply($event);
        $this->version++;
        $this->recordedEvents[] = $event;
    }

    abstract protected function apply(DomainEvent $event): void;

    /** @return DomainEvent[] */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @param DomainEvent[] $events */
    public static function reconstituteFromStream(array $events): static
    {
        $aggregate = static::createEmpty();

        foreach ($events as $event) {
            $aggregate->apply($event);
            $aggregate->version++;
        }

        return $aggregate;
    }

    abstract protected static function createEmpty(): static;
}
