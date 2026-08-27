<?php

use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function repository(): TransactionRepository
{
    return new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map()));
}

it('returns null for an unknown transaction', function () {
    expect(repository()->find(TransactionId::fromString((string) Str::uuid())))->toBeNull();
});

it('saves a newly imported transaction and finds it again', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id,
        money: new Money(12345, Currency::EUR),
        reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'),
        occurrenceIndex: 0,
        idempotencyKey: $key,
        rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'),
        causationId: 'c1',
        correlationId: 'r1',
    );

    repository()->save($transaction);

    $found = repository()->find($id);

    expect($found)->not->toBeNull()
        ->and($found->reference())->toBe('REF-1')
        ->and($found->version())->toBe(1);
});

it('persists events recorded across multiple save calls at the correct version', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: 'c1', correlationId: 'r1',
    );
    repository()->save($transaction);

    $reloaded = repository()->find($id);
    $reloaded->markMatched('ep-1', Actor::system(), 'c2', 'r1');
    repository()->save($reloaded);

    $final = repository()->find($id);

    expect($final->version())->toBe(3)
        ->and($final->state())->toBe(TransactionState::Reconciled);
});
