<?php

use App\Modules\Reconciliation\Application\ResolveReviewService;
use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

it('reconciles a transaction end-to-end through the API on an exact match', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);

    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    // QUEUE_CONNECTION=sync in .env.testing: il job di matching è già eseguito.
    $this->getJson("/api/transactions/{$id}")
        ->assertOk()
        ->assertJsonPath('state', 'Reconciled')
        ->assertJsonCount(3, 'history'); // imported, matched, reconciled
});

it('resolves a transaction end-to-end through the API when review is needed', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 999, 'currency' => 'EUR']);

    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    $this->getJson("/api/transactions/{$id}")->assertJsonPath('state', 'NeedsReview');

    $candidateId = ExpectedPayment::query()->where('amount_minor_units', 12345)->first()->id;
    $this->postJson("/api/transactions/{$id}/resolve", ['action' => 'confirm', 'expected_payment_id' => $candidateId])
        ->assertOk()
        ->assertJsonPath('state', 'Reconciled');

    // Stream: 0=transaction.imported, 1=transaction.marked_ambiguous, 2=transaction.reconciled (manual).
    $this->getJson("/api/transactions/{$id}")
        ->assertJsonCount(3, 'history')
        ->assertJsonPath('history.2.event_type', 'transaction.reconciled');
});

it('leaves an Unmatched transaction Unmatched end-to-end', function () {
    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-NO-CANDIDATE,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    $this->getJson("/api/transactions/{$id}")->assertJsonPath('state', 'Unmatched');
});

it('rejects the loser of two concurrent resolutions with a ConcurrencyConflictException', function () {
    $repository = new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map()));

    ExpectedPayment::factory()->count(2)->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    // Il matching per REF-1 con due candidati esatti produce NeedsReview (multiple_candidates, spec §6.2).
    $transactionId = \App\Modules\SharedKernel\Domain\TransactionId::fromString($id);
    $candidateId = ExpectedPayment::query()->where('reference', 'REF-1')->first()->id;

    // Due copie indipendenti dello stesso aggregate, caricate dallo stesso stato iniziale — simula la race:
    // entrambe partono da NeedsReview alla stessa versione, solo la prima save() può vincere.
    $firstCopy = $repository->find($transactionId);
    $secondCopy = $repository->find($transactionId);

    $firstCopy->resolveByConfirming($candidateId, Actor::apiCaller('reviewer-1'), (string) Str::uuid(), (string) Str::uuid());
    $secondCopy->resolveByRejecting('changed my mind', Actor::apiCaller('reviewer-2'), (string) Str::uuid(), (string) Str::uuid());

    $repository->save($firstCopy);

    expect(fn () => $repository->save($secondCopy))->toThrow(ConcurrencyConflictException::class);

    // Lo stato persistito riflette il vincitore della race, non il perdente.
    expect($repository->find($transactionId)->state())->toBe(\App\Modules\Reconciliation\Domain\TransactionState::Reconciled);
});
