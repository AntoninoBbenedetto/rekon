<?php

namespace App\Modules\Reconciliation\Domain;

use App\Modules\Reconciliation\Domain\Events\TransactionImported;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\AggregateRoot;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use DateTimeImmutable;
use InvalidArgumentException;

final class Transaction extends AggregateRoot
{
    private string $id;
    private TransactionState $state;
    private Money $money;
    private string $reference;
    private DateTimeImmutable $statementDate;
    private DateTimeImmutable $importedAt;

    public static function import(
        TransactionId $id,
        Money $money,
        string $reference,
        DateTimeImmutable $statementDate,
        int $occurrenceIndex,
        IdempotencyKey $idempotencyKey,
        string $rawRowChecksum,
        Actor $actor,
        string $causationId,
        string $correlationId,
    ): self {
        $transaction = self::createEmpty();

        $transaction->record(new TransactionImported(
            $id->value,
            new DateTimeImmutable(),
            $actor,
            $causationId,
            $correlationId,
            amountMinorUnits: $money->amountMinorUnits,
            currency: $money->currency,
            reference: $reference,
            statementDate: $statementDate,
            occurrenceIndex: $occurrenceIndex,
            idempotencyKey: $idempotencyKey->value,
            rawRowChecksum: $rawRowChecksum,
        ));

        return $transaction;
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function state(): TransactionState
    {
        return $this->state;
    }

    public function money(): Money
    {
        return $this->money;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function statementDate(): DateTimeImmutable
    {
        return $this->statementDate;
    }

    public function importedAt(): DateTimeImmutable
    {
        return $this->importedAt;
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof TransactionImported => $this->applyImported($event),
            default => throw new InvalidArgumentException('Unknown event: ' . $event::class),
        };
    }

    private function applyImported(TransactionImported $event): void
    {
        $this->id = $event->aggregateId();
        $this->state = TransactionState::Pending;
        $this->money = new Money($event->amountMinorUnits, $event->currency);
        $this->reference = $event->reference;
        $this->statementDate = $event->statementDate;
        $this->importedAt = $event->occurredAt();
    }

    protected static function createEmpty(): static
    {
        return new self();
    }
}
