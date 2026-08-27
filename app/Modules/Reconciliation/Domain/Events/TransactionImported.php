<?php

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionImported extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly int $amountMinorUnits,
        public readonly Currency $currency,
        public readonly string $reference,
        public readonly DateTimeImmutable $statementDate,
        public readonly int $occurrenceIndex,
        public readonly string $idempotencyKey,
        public readonly string $rawRowChecksum,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.imported';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'amount_minor_units' => $this->amountMinorUnits,
            'currency' => $this->currency->value,
            'reference' => $this->reference,
            'statement_date' => $this->statementDate->format('Y-m-d'),
            'occurrence_index' => $this->occurrenceIndex,
            'idempotency_key' => $this->idempotencyKey,
            'raw_row_checksum' => $this->rawRowChecksum,
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
            amountMinorUnits: $row->payload['amount_minor_units'],
            currency: Currency::from($row->payload['currency']),
            reference: $row->payload['reference'],
            statementDate: new DateTimeImmutable($row->payload['statement_date']),
            occurrenceIndex: $row->payload['occurrence_index'],
            idempotencyKey: $row->payload['idempotency_key'],
            rawRowChecksum: $row->payload['raw_row_checksum'],
        );
    }
}
