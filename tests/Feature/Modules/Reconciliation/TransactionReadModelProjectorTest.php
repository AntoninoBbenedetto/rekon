<?php

use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('projects a Pending transaction into the read model', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: 'c1', correlationId: 'r1',
    );

    (new TransactionReadModelProjector())->project($transaction);

    $row = TransactionProjection::query()->find($id->value);

    expect($row)->not->toBeNull()
        ->and($row->state)->toBe('Pending')
        ->and($row->amount_minor_units)->toBe(12345)
        ->and($row->currency)->toBe('EUR')
        ->and($row->reference)->toBe('REF-1');
});

it('overwrites the row on a later projection of the same aggregate', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: 'c1', correlationId: 'r1',
    );
    $projector = new TransactionReadModelProjector();
    $projector->project($transaction);

    $expectedPaymentId = (string) Str::uuid();
    $transaction->markMatched($expectedPaymentId, Actor::system(), 'c2', 'r1');
    $projector->project($transaction);

    expect(TransactionProjection::query()->count())->toBe(1)
        ->and(TransactionProjection::query()->find($id->value)->state)->toBe('Reconciled');
});
