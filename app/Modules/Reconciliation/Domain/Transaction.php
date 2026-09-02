<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain;

use App\Modules\Reconciliation\Domain\Events\TransactionImported;
use App\Modules\Reconciliation\Domain\Events\TransactionMarkedAmbiguous;
use App\Modules\Reconciliation\Domain\Events\TransactionMarkedUnmatched;
use App\Modules\Reconciliation\Domain\Events\TransactionMatched;
use App\Modules\Reconciliation\Domain\Events\TransactionReconciled;
use App\Modules\Reconciliation\Domain\Events\TransactionRejected;
use App\Modules\Reconciliation\Domain\Exceptions\IllegalTransactionStateTransition;
use App\Modules\Reconciliation\Domain\Exceptions\InvalidResolutionCandidate;
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
    private ?string $matchedExpectedPaymentId = null;
    /** @var string[] */
    private array $candidateExpectedPaymentIds = [];

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

    public function markMatched(string $expectedPaymentId, Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::Pending);

        $this->record(new TransactionMatched(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            expectedPaymentId: $expectedPaymentId,
            matchType: 'exact',
        ));

        $this->record(new TransactionReconciled(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            expectedPaymentId: $expectedPaymentId,
            resolution: 'auto',
        ));
    }

    public function markUnmatched(Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::Pending);

        $this->record(new TransactionMarkedUnmatched(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            reason: 'no_candidate_found',
        ));
    }

    /** @param string[] $candidateExpectedPaymentIds */
    public function markAmbiguous(array $candidateExpectedPaymentIds, string $reason, Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::Pending);

        $this->record(new TransactionMarkedAmbiguous(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            candidateExpectedPaymentIds: $candidateExpectedPaymentIds,
            reason: $reason,
        ));
    }

    public function resolveByConfirming(string $expectedPaymentId, Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::NeedsReview);

        if (!in_array($expectedPaymentId, $this->candidateExpectedPaymentIds, true)) {
            throw new InvalidResolutionCandidate($expectedPaymentId);
        }

        $this->record(new TransactionReconciled(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            expectedPaymentId: $expectedPaymentId,
            resolution: 'manual',
        ));
    }

    public function resolveByRejecting(string $reason, Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::NeedsReview);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        $this->record(new TransactionRejected(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            reason: $reason,
        ));
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

    public function matchedExpectedPaymentId(): ?string
    {
        return $this->matchedExpectedPaymentId;
    }

    /** @return string[] */
    public function candidateExpectedPaymentIds(): array
    {
        return $this->candidateExpectedPaymentIds;
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof TransactionImported => $this->applyImported($event),
            $event instanceof TransactionMatched => $this->applyMatched($event),
            $event instanceof TransactionMarkedUnmatched => $this->state = TransactionState::Unmatched,
            $event instanceof TransactionMarkedAmbiguous => $this->applyMarkedAmbiguous($event),
            $event instanceof TransactionReconciled => $this->applyReconciled($event),
            $event instanceof TransactionRejected => $this->state = TransactionState::Rejected,
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

    private function applyMatched(TransactionMatched $event): void
    {
        $this->state = TransactionState::Matched;
        $this->matchedExpectedPaymentId = $event->expectedPaymentId;
    }

    private function applyMarkedAmbiguous(TransactionMarkedAmbiguous $event): void
    {
        $this->state = TransactionState::NeedsReview;
        $this->candidateExpectedPaymentIds = $event->candidateExpectedPaymentIds;
    }

    private function applyReconciled(TransactionReconciled $event): void
    {
        $this->state = TransactionState::Reconciled;
        $this->matchedExpectedPaymentId = $event->expectedPaymentId;
    }

    private function assertState(TransactionState $expected): void
    {
        if ($this->state !== $expected) {
            throw new IllegalTransactionStateTransition($this->id, $this->state, $expected);
        }
    }

    protected static function createEmpty(): static
    {
        return new self();
    }
}
