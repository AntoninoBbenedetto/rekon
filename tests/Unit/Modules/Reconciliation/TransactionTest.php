<?php

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
        ->and($events[0])->toBeInstanceOf(\App\Modules\Reconciliation\Domain\Events\TransactionImported::class);
});
