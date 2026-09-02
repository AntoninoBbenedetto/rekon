<?php

use App\Modules\Reconciliation\Domain\Events\TransactionImported;
use App\Modules\Reconciliation\Domain\Events\TransactionMatched;
use App\Modules\Reconciliation\Domain\Events\TransactionReconciled;
use App\Modules\Reconciliation\Domain\Events\TransactionRejected;
use App\Modules\Reconciliation\Domain\Exceptions\IllegalTransactionStateTransition;
use App\Modules\Reconciliation\Domain\Exceptions\InvalidResolutionCandidate;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Tests\TestCase;

uses(TestCase::class);

function importedTransaction(): Transaction
{
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    return Transaction::import(
        id: $id,
        money: new Money(12345, Currency::EUR),
        reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'),
        occurrenceIndex: 0,
        idempotencyKey: $key,
        rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'),
        causationId: 'causation-1',
        correlationId: 'correlation-1',
    );
}

it('is born Pending and records exactly one TransactionImported event', function () {
    $transaction = importedTransaction();

    expect($transaction->state())->toBe(TransactionState::Pending)
        ->and($transaction->reference())->toBe('REF-1')
        ->and($transaction->money()->amountMinorUnits)->toBe(12345)
        ->and($transaction->money()->currency)->toBe(Currency::EUR)
        ->and($transaction->statementDate()->format('Y-m-d'))->toBe('2026-07-31')
        ->and($transaction->version())->toBe(1);

    $events = $transaction->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TransactionImported::class);
});

it('auto-reconciles on an exact match', function () {
    $transaction = importedTransaction();
    $transaction->releaseEvents();

    $transaction->markMatched('ep-1', Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::Reconciled)
        ->and($transaction->matchedExpectedPaymentId())->toBe('ep-1')
        ->and($transaction->version())->toBe(3);

    $events = $transaction->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(TransactionMatched::class)
        ->and($events[1])->toBeInstanceOf(TransactionReconciled::class)
        ->and($events[1]->resolution)->toBe('auto');
});

it('becomes Unmatched when no candidate is found', function () {
    $transaction = importedTransaction();
    $transaction->releaseEvents();

    $transaction->markUnmatched(Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::Unmatched);
});

it('becomes NeedsReview with recorded candidates when ambiguous', function () {
    $transaction = importedTransaction();
    $transaction->releaseEvents();

    $transaction->markAmbiguous(['ep-1', 'ep-2'], 'multiple_candidates', Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::NeedsReview)
        ->and($transaction->candidateExpectedPaymentIds())->toBe(['ep-1', 'ep-2']);
});

it('rejects markMatched when the transaction is not Pending', function () {
    $transaction = importedTransaction();
    $transaction->markUnmatched(Actor::system(), 'c2', 'r1');

    expect(fn () => $transaction->markMatched('ep-1', Actor::system(), 'c3', 'r1'))
        ->toThrow(IllegalTransactionStateTransition::class);
});

function needsReviewTransaction(): Transaction
{
    $transaction = importedTransaction();
    $transaction->markAmbiguous(['ep-1', 'ep-2'], 'multiple_candidates', Actor::system(), 'c2', 'r1');
    $transaction->releaseEvents();

    return $transaction;
}

it('reconciles manually when confirming a recorded candidate', function () {
    $transaction = needsReviewTransaction();

    $transaction->resolveByConfirming('ep-1', Actor::apiCaller('reviewer-1'), 'c3', 'r2');

    expect($transaction->state())->toBe(TransactionState::Reconciled)
        ->and($transaction->matchedExpectedPaymentId())->toBe('ep-1');

    $events = $transaction->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]->resolution)->toBe('manual');
});

it('rejects confirming a candidate that was never recorded', function () {
    $transaction = needsReviewTransaction();

    expect(fn () => $transaction->resolveByConfirming('ep-not-a-candidate', Actor::apiCaller('reviewer-1'), 'c3', 'r2'))
        ->toThrow(InvalidResolutionCandidate::class);
});

it('rejects when the transaction is not NeedsReview', function () {
    $transaction = importedTransaction();
    $transaction->markUnmatched(Actor::system(), 'c2', 'r1');

    expect(fn () => $transaction->resolveByConfirming('ep-1', Actor::apiCaller('reviewer-1'), 'c3', 'r2'))
        ->toThrow(IllegalTransactionStateTransition::class);
});

it('rejects with a reason', function () {
    $transaction = needsReviewTransaction();

    $transaction->resolveByRejecting('duplicate payment claimed elsewhere', Actor::apiCaller('reviewer-1'), 'c3', 'r2');

    expect($transaction->state())->toBe(TransactionState::Rejected);

    $events = $transaction->releaseEvents();
    expect($events[0])->toBeInstanceOf(TransactionRejected::class)
        ->and($events[0]->reason)->toBe('duplicate payment claimed elsewhere');
});

it('rejects rejection with an empty reason', function () {
    $transaction = needsReviewTransaction();

    expect(fn () => $transaction->resolveByRejecting('   ', Actor::apiCaller('reviewer-1'), 'c3', 'r2'))
        ->toThrow(InvalidArgumentException::class);
});
