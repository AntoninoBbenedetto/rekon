<?php

use App\Modules\Reconciliation\Application\MatchTransactionService;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pendingTransaction(string $reference = 'REF-1', int $amountMinorUnits = 12345, Currency $currency = Currency::EUR): Transaction
{
    $key = IdempotencyKey::forStatementRow($reference, $amountMinorUnits, $currency, new DateTimeImmutable('2026-07-31'), 0);

    $transaction = Transaction::import(
        id: TransactionId::deriveFrom($key),
        money: new Money($amountMinorUnits, $currency),
        reference: $reference,
        statementDate: new DateTimeImmutable('2026-07-31'),
        occurrenceIndex: 0,
        idempotencyKey: $key,
        rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'),
        causationId: 'c1',
        correlationId: 'r1',
    );
    $transaction->releaseEvents();

    return $transaction;
}

it('auto-reconciles when exactly one candidate matches the amount exactly', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);

    $transaction = pendingTransaction();
    (new MatchTransactionService())->match($transaction, Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::Reconciled);
});

it('marks Unmatched when there is no candidate', function () {
    $transaction = pendingTransaction();
    (new MatchTransactionService())->match($transaction, Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::Unmatched);
});

it('marks NeedsReview with reason partial_amount_match when the single candidate amount differs', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 999, 'currency' => 'EUR']);

    $transaction = pendingTransaction();
    (new MatchTransactionService())->match($transaction, Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::NeedsReview);
});

it('marks NeedsReview with reason multiple_candidates when several candidates share the reference, even if one matches exactly', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 999, 'currency' => 'EUR']);

    $transaction = pendingTransaction();
    (new MatchTransactionService())->match($transaction, Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::NeedsReview)
        ->and($transaction->candidateExpectedPaymentIds())->toHaveCount(2);
});
