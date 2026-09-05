<?php

use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function persistedTransactionWithoutProjection(string $reference = 'REF-1'): TransactionId
{
    $key = IdempotencyKey::forStatementRow($reference, 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: $reference,
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: (string) Str::uuid(), correlationId: (string) Str::uuid(),
    );

    app(TransactionRepository::class)->save($transaction);

    return $id;
}

it('rebuilds the read model from the event store after it has diverged (crash-after-append window)', function () {
    $id = persistedTransactionWithoutProjection();

    // Simulate the crash window from ADR-009: event exists, read model row never landed.
    expect(TransactionProjection::query()->find($id->value))->toBeNull();

    $this->artisan('reconciliation:rebuild-projection')->assertExitCode(0);

    $projected = TransactionProjection::query()->find($id->value);
    expect($projected)->not->toBeNull()
        ->and($projected->state)->toBe('Pending')
        ->and($projected->reference)->toBe('REF-1');
});

it('discards read model rows that no longer correspond to any event stream', function () {
    persistedTransactionWithoutProjection();

    TransactionProjection::query()->insert([
        'transaction_id' => (string) Str::uuid(),
        'state' => 'Pending',
        'version' => 1,
        'amount_minor_units' => 100,
        'currency' => 'EUR',
        'reference' => 'GHOST',
        'statement_date' => '2026-07-31',
        'matched_expected_payment_id' => null,
        'imported_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('reconciliation:rebuild-projection')->assertExitCode(0);

    expect(TransactionProjection::query()->where('reference', 'GHOST')->exists())->toBeFalse()
        ->and(TransactionProjection::query()->count())->toBe(1);
});
