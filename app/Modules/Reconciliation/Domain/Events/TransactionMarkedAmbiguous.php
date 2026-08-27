<?php

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionMarkedAmbiguous extends AbstractDomainEvent
{
    /** @param string[] $candidateExpectedPaymentIds */
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly array $candidateExpectedPaymentIds,
        public readonly string $reason,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.marked_ambiguous';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'candidate_expected_payment_ids' => $this->candidateExpectedPaymentIds,
            'reason' => $this->reason,
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
            candidateExpectedPaymentIds: $row->payload['candidate_expected_payment_ids'],
            reason: $row->payload['reason'],
        );
    }
}
