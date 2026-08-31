<?php

use App\Modules\Reconciliation\Application\ImportStatementService;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\SharedKernel\Domain\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function importAndReturnNeedsReviewId(): string
{
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 999, 'currency' => 'EUR']);

    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31";
    $summary = app(ImportStatementService::class)->import($csv, Actor::system(), (string) Str::uuid());
    $id = $summary->transactionIds[0];

    // Il job di matching gira sync in test (QUEUE_CONNECTION=sync in .env.testing).
    return $id;
}

it('confirms a NeedsReview transaction against a valid candidate', function () {
    $id = importAndReturnNeedsReviewId();
    $candidateId = ExpectedPayment::query()->where('reference', 'REF-1')->where('amount_minor_units', 12345)->first()->id;

    $response = $this->postJson("/api/transactions/{$id}/resolve", [
        'action' => 'confirm',
        'expected_payment_id' => $candidateId,
    ]);

    $response->assertOk()->assertJsonPath('state', 'Reconciled');
});

it('rejects a NeedsReview transaction with a reason', function () {
    $id = importAndReturnNeedsReviewId();

    $response = $this->postJson("/api/transactions/{$id}/resolve", [
        'action' => 'reject',
        'reason' => 'not our payment',
    ]);

    $response->assertOk()->assertJsonPath('state', 'Rejected');
});

it('returns 422 when rejecting without a reason', function () {
    $id = importAndReturnNeedsReviewId();

    $response = $this->postJson("/api/transactions/{$id}/resolve", ['action' => 'reject']);

    $response->assertStatus(422);
});

it('returns 422 for a candidate that was never recorded', function () {
    $id = importAndReturnNeedsReviewId();

    $response = $this->postJson("/api/transactions/{$id}/resolve", [
        'action' => 'confirm',
        'expected_payment_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(422);
});

it('returns 409 when the transaction is not currently NeedsReview', function () {
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-NOMATCH,12345,EUR,2026-07-31";
    $summary = app(ImportStatementService::class)->import($csv, Actor::system(), (string) Str::uuid());
    $id = $summary->transactionIds[0]; // Unmatched, non NeedsReview

    $response = $this->postJson("/api/transactions/{$id}/resolve", [
        'action' => 'reject',
        'reason' => 'irrelevant',
    ]);

    $response->assertStatus(409)->assertJsonStructure(['message', 'current_state']);
});

it('returns 404 for an unknown transaction id', function () {
    $response = $this->postJson('/api/transactions/' . (string) Str::uuid() . '/resolve', [
        'action' => 'reject',
        'reason' => 'irrelevant',
    ]);

    $response->assertStatus(404);
});
