<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionReconciled extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly string $expectedPaymentId,
        public readonly string $resolution,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.reconciled';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'expected_payment_id' => $this->expectedPaymentId,
            'resolution' => $this->resolution,
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
            resolution: $row->payload['resolution'],
        );
    }
}
