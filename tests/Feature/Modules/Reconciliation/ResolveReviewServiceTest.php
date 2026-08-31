<?php

use App\Modules\Reconciliation\Application\ResolveReviewService;
use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Domain\Exceptions\IllegalTransactionStateTransition;
use App\Modules\Reconciliation\Domain\Exceptions\InvalidResolutionCandidate;
use App\Modules\Reconciliation\Domain\Exceptions\TransactionNotFound;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// The transactions_read_model.matched_expected_payment_id column is uuid-typed
// (Task 18 migration), and ExpectedPayment ids are genuine UUIDs in production
// (create_expected_payments_table migration), so the recorded candidates below
// use real UUIDs rather than the brief's 'ep-1'/'ep-2' shorthand literals — the
// same non-UUID-literal-hits-uuid-column issue already fixed for causation/
// correlation ids elsewhere in this codebase, just surfacing on a different
// column here.
const RESOLVE_REVIEW_CANDIDATE_A = '11111111-1111-4111-8111-111111111111';
const RESOLVE_REVIEW_CANDIDATE_B = '22222222-2222-4222-8222-222222222222';

function needsReviewTransactionId(): TransactionId
{
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);
    $correlationId = (string) Str::uuid();

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: (string) Str::uuid(), correlationId: $correlationId,
    );
    $transaction->markAmbiguous([RESOLVE_REVIEW_CANDIDATE_A, RESOLVE_REVIEW_CANDIDATE_B], 'multiple_candidates', Actor::system(), (string) Str::uuid(), $correlationId);

    $repository = new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map()));
    $repository->save($transaction);

    return $id;
}

function resolveService(): ResolveReviewService
{
    return new ResolveReviewService(
        new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map())),
        new TransactionReadModelProjector(),
    );
}

it('confirms a NeedsReview transaction against a recorded candidate', function () {
    $id = needsReviewTransactionId();

    $transaction = resolveService()->confirm($id, RESOLVE_REVIEW_CANDIDATE_A, Actor::apiCaller('reviewer-1'));

    expect($transaction->state())->toBe(TransactionState::Reconciled)
        ->and($transaction->matchedExpectedPaymentId())->toBe(RESOLVE_REVIEW_CANDIDATE_A);
});

it('rejects a NeedsReview transaction with a reason', function () {
    $id = needsReviewTransactionId();

    $transaction = resolveService()->reject($id, 'not our payment', Actor::apiCaller('reviewer-1'));

    expect($transaction->state())->toBe(TransactionState::Rejected);
});

it('throws TransactionNotFound for an unknown id', function () {
    expect(fn () => resolveService()->confirm(TransactionId::fromString((string) Str::uuid()), 'ep-1', Actor::apiCaller('reviewer-1')))
        ->toThrow(TransactionNotFound::class);
});

it('throws InvalidResolutionCandidate for a candidate that was never recorded', function () {
    $id = needsReviewTransactionId();

    expect(fn () => resolveService()->confirm($id, 'ep-not-a-candidate', Actor::apiCaller('reviewer-1')))
        ->toThrow(InvalidResolutionCandidate::class);
});

it('throws IllegalTransactionStateTransition when the transaction is not NeedsReview', function () {
    $key = IdempotencyKey::forStatementRow('REF-2', 500, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);
    $transaction = Transaction::import(
        id: $id, money: new Money(500, Currency::EUR), reference: 'REF-2',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-2',
        actor: Actor::apiCaller('caller-1'), causationId: (string) Str::uuid(), correlationId: (string) Str::uuid(),
    );
    (new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map())))->save($transaction);

    expect(fn () => resolveService()->confirm($id, 'ep-1', Actor::apiCaller('reviewer-1')))
        ->toThrow(IllegalTransactionStateTransition::class);
});
