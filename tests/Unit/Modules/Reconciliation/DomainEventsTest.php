<?php

use App\Modules\Reconciliation\Domain\Events\TransactionImported;
use App\Modules\Reconciliation\Domain\Events\TransactionMarkedAmbiguous;
use App\Modules\Reconciliation\Domain\Events\TransactionMarkedUnmatched;
use App\Modules\Reconciliation\Domain\Events\TransactionMatched;
use App\Modules\Reconciliation\Domain\Events\TransactionReconciled;
use App\Modules\Reconciliation\Domain\Events\TransactionRejected;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;

function envelope(array $payload, string $eventType): StoredEventRow
{
    return new StoredEventRow(
        aggregateId: 'txn-1',
        version: 1,
        eventType: $eventType,
        payload: $payload,
        occurredAt: new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        actor: Actor::system(),
        causationId: 'causation-1',
        correlationId: 'correlation-1',
    );
}

it('round-trips TransactionImported', function () {
    $event = new TransactionImported(
        'txn-1', new DateTimeImmutable, Actor::system(), 'c1', 'r1',
        amountMinorUnits: 12345,
        currency: Currency::EUR,
        reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'),
        occurrenceIndex: 0,
        idempotencyKey: 'abc123',
        rawRowChecksum: 'def456',
    );

    expect($event->eventType())->toBe('transaction.imported')
        ->and($event->payload())->toBe([
            'transaction_id' => 'txn-1',
            'amount_minor_units' => 12345,
            'currency' => 'EUR',
            'reference' => 'REF-1',
            'statement_date' => '2026-07-31',
            'occurrence_index' => 0,
            'idempotency_key' => 'abc123',
            'raw_row_checksum' => 'def456',
        ]);

    $reconstructed = TransactionImported::fromStoredRow(envelope($event->payload(), 'transaction.imported'));

    expect($reconstructed->reference)->toBe('REF-1')
        ->and($reconstructed->currency)->toBe(Currency::EUR)
        ->and($reconstructed->statementDate->format('Y-m-d'))->toBe('2026-07-31');
});

it('round-trips TransactionMatched', function () {
    $event = new TransactionMatched('txn-1', new DateTimeImmutable, Actor::system(), 'c1', 'r1', expectedPaymentId: 'ep-1', matchType: 'exact');

    expect($event->eventType())->toBe('transaction.matched')
        ->and($event->payload())->toBe(['transaction_id' => 'txn-1', 'expected_payment_id' => 'ep-1', 'match_type' => 'exact']);

    $reconstructed = TransactionMatched::fromStoredRow(envelope($event->payload(), 'transaction.matched'));
    expect($reconstructed->expectedPaymentId)->toBe('ep-1');
});

it('round-trips TransactionMarkedUnmatched', function () {
    $event = new TransactionMarkedUnmatched('txn-1', new DateTimeImmutable, Actor::system(), 'c1', 'r1', reason: 'no_candidate_found');

    expect($event->eventType())->toBe('transaction.marked_unmatched')
        ->and($event->payload())->toBe(['transaction_id' => 'txn-1', 'reason' => 'no_candidate_found']);

    $reconstructed = TransactionMarkedUnmatched::fromStoredRow(envelope($event->payload(), 'transaction.marked_unmatched'));
    expect($reconstructed->reason)->toBe('no_candidate_found');
});

it('round-trips TransactionMarkedAmbiguous', function () {
    $event = new TransactionMarkedAmbiguous('txn-1', new DateTimeImmutable, Actor::system(), 'c1', 'r1', candidateExpectedPaymentIds: ['ep-1', 'ep-2'], reason: 'multiple_candidates');

    expect($event->eventType())->toBe('transaction.marked_ambiguous')
        ->and($event->payload())->toBe([
            'transaction_id' => 'txn-1',
            'candidate_expected_payment_ids' => ['ep-1', 'ep-2'],
            'reason' => 'multiple_candidates',
        ]);

    $reconstructed = TransactionMarkedAmbiguous::fromStoredRow(envelope($event->payload(), 'transaction.marked_ambiguous'));
    expect($reconstructed->candidateExpectedPaymentIds)->toBe(['ep-1', 'ep-2']);
});

it('round-trips TransactionReconciled', function () {
    $event = new TransactionReconciled('txn-1', new DateTimeImmutable, Actor::system(), 'c1', 'r1', expectedPaymentId: 'ep-1', resolution: 'auto');

    expect($event->eventType())->toBe('transaction.reconciled')
        ->and($event->payload())->toBe(['transaction_id' => 'txn-1', 'expected_payment_id' => 'ep-1', 'resolution' => 'auto']);

    $reconstructed = TransactionReconciled::fromStoredRow(envelope($event->payload(), 'transaction.reconciled'));
    expect($reconstructed->resolution)->toBe('auto');
});

it('round-trips TransactionRejected', function () {
    $event = new TransactionRejected('txn-1', new DateTimeImmutable, Actor::system(), 'c1', 'r1', reason: 'duplicate payment claimed elsewhere');

    expect($event->eventType())->toBe('transaction.rejected')
        ->and($event->payload())->toBe(['transaction_id' => 'txn-1', 'reason' => 'duplicate payment claimed elsewhere']);

    $reconstructed = TransactionRejected::fromStoredRow(envelope($event->payload(), 'transaction.rejected'));
    expect($reconstructed->reason)->toBe('duplicate payment claimed elsewhere');
});
