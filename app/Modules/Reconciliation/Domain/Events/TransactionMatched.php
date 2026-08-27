<?php

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionMatched extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly string $expectedPaymentId,
        public readonly string $matchType,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.matched';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'expected_payment_id' => $this->expectedPaymentId,
            'match_type' => $this->matchType,
        ];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self(
            $row->aggregateId,
            $row->occurredAt,
            $row->actor,
            $row->causationId,
            $row->correlationId,
            expectedPaymentId: $row->payload['expected_payment_id'],
            matchType: $row->payload['match_type'],
        );
    }
}
