<?php

use App\Modules\Reconciliation\Application\MatchTransactionService;
use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\Reconciliation\Infrastructure\MatchPendingTransactionJob;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function importedPendingTransaction(): TransactionId
{
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: (string) Str::uuid(), correlationId: (string) Str::uuid(),
    );

    app(TransactionRepository::class)->save($transaction);

    return $id;
}

it('matches a Pending transaction and projects the outcome', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    $id = importedPendingTransaction();

    (new MatchPendingTransactionJob($id->value, (string) Str::uuid()))->handle(
        app(TransactionRepository::class),
        app(MatchTransactionService::class),
        app(TransactionReadModelProjector::class),
    );

    $found = app(TransactionRepository::class)->find($id);
    expect($found->state())->toBe(TransactionState::Reconciled);

    $projected = TransactionProjection::query()->find($id->value);
    expect($projected->state)->toBe('Reconciled');
});

it('is a no-op when the transaction is no longer Pending (queue redelivery)', function () {
    $id = importedPendingTransaction();
    $repository = app(TransactionRepository::class);
    $matcher = app(MatchTransactionService::class);
    $projector = app(TransactionReadModelProjector::class);

    (new MatchPendingTransactionJob($id->value, (string) Str::uuid()))->handle($repository, $matcher, $projector);
    $afterFirstRun = $repository->find($id)->version();

    (new MatchPendingTransactionJob($id->value, (string) Str::uuid()))->handle($repository, $matcher, $projector);
    $afterSecondRun = $repository->find($id)->version();

    expect($afterSecondRun)->toBe($afterFirstRun);
});

it('re-projects the current state on redelivery even though matching is a no-op', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    $id = importedPendingTransaction();
    $repository = app(TransactionRepository::class);
    $matcher = app(MatchTransactionService::class);
    $projector = app(TransactionReadModelProjector::class);

    (new MatchPendingTransactionJob($id->value, (string) Str::uuid()))->handle($repository, $matcher, $projector);

    // Simulate the first run's own projection having failed to land (stale row).
    TransactionProjection::query()
        ->where('transaction_id', $id->value)
        ->update(['state' => 'Pending']);

    (new MatchPendingTransactionJob($id->value, (string) Str::uuid()))->handle($repository, $matcher, $projector);

    $projected = TransactionProjection::query()->find($id->value);
    expect($projected->state)->toBe('Reconciled');
});

it('is a no-op for an unknown transaction id', function () {
    $repository = app(TransactionRepository::class);
    $matcher = app(MatchTransactionService::class);
    $projector = app(TransactionReadModelProjector::class);

    $unknownId = (string) Str::uuid();
    (new MatchPendingTransactionJob($unknownId, (string) Str::uuid()))->handle($repository, $matcher, $projector);

    expect(TransactionProjection::query()->find($unknownId))->toBeNull();
});

it('retries a transient failure instead of stranding the transaction in Pending', function () {
    $job = new MatchPendingTransactionJob((string) Str::uuid(), (string) Str::uuid());

    // Redelivery is a no-op unless the transaction is still Pending (covered
    // above), so retrying costs nothing and rescues a transient failure.
    expect($job->tries)->toBeGreaterThan(1)
        ->and($job->backoff)->not->toBeEmpty();

    $sorted = $job->backoff;
    sort($sorted);
    expect($job->backoff)->toBe($sorted); // grows, so a lost race is not retried immediately
});

it('records the transaction identity when matching permanently fails', function () {
    Log::spy();

    $transactionId = (string) Str::uuid();
    $correlationId = (string) Str::uuid();

    (new MatchPendingTransactionJob($transactionId, $correlationId))
        ->failed(new RuntimeException('queue backend unreachable'));

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context) => str_contains($message, 'Pending')
            && $context['transaction_id'] === $transactionId
            && $context['correlation_id'] === $correlationId
            && $context['exception'] === 'queue backend unreachable',
    );
});
