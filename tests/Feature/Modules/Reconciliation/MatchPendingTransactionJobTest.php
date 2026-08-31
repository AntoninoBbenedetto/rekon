<?php

use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\Reconciliation\Infrastructure\MatchPendingTransactionJob;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        app(\App\Modules\Reconciliation\Application\MatchTransactionService::class),
        app(\App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector::class),
    );

    $found = app(TransactionRepository::class)->find($id);
    expect($found->state())->toBe(TransactionState::Reconciled);

    $projected = \App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection::query()->find($id->value);
    expect($projected->state)->toBe('Reconciled');
});

it('is a no-op when the transaction is no longer Pending (queue redelivery)', function () {
    $id = importedPendingTransaction();
    $repository = app(TransactionRepository::class);
    $matcher = app(\App\Modules\Reconciliation\Application\MatchTransactionService::class);
    $projector = app(\App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector::class);

    (new MatchPendingTransactionJob($id->value, (string) Str::uuid()))->handle($repository, $matcher, $projector);
    $afterFirstRun = $repository->find($id)->version();

    (new MatchPendingTransactionJob($id->value, (string) Str::uuid()))->handle($repository, $matcher, $projector);
    $afterSecondRun = $repository->find($id)->version();

    expect($afterSecondRun)->toBe($afterFirstRun);
});

it('is a no-op for an unknown transaction id', function () {
    $repository = app(TransactionRepository::class);
    $matcher = app(\App\Modules\Reconciliation\Application\MatchTransactionService::class);
    $projector = app(\App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector::class);

    $unknownId = (string) Str::uuid();
    (new MatchPendingTransactionJob($unknownId, (string) Str::uuid()))->handle($repository, $matcher, $projector);

    expect(\App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection::query()->find($unknownId))->toBeNull();
});
