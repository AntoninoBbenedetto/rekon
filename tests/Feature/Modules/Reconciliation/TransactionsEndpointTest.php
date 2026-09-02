<?php

use App\Modules\Reconciliation\Application\ImportStatementService;
use App\Modules\SharedKernel\Domain\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('lists transactions, optionally filtered by state', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-2,500,EUR,2026-07-31";
    app(ImportStatementService::class)->import($csv, Actor::system(), (string) Str::uuid());

    $response = $this->getJson('/api/transactions');
    $response->assertOk()->assertJsonCount(2, 'data');

    $filtered = $this->getJson('/api/transactions?state=Pending');
    $filtered->assertOk()->assertJsonCount(2, 'data');

    $none = $this->getJson('/api/transactions?state=Reconciled');
    $none->assertOk()->assertJsonCount(0, 'data');
});

it('shows a transaction with its full event history', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31";
    $summary = app(ImportStatementService::class)->import($csv, Actor::system(), (string) Str::uuid());
    $id = $summary->transactionIds[0];

    $response = $this->getJson("/api/transactions/{$id}");

    $response->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('state', 'Pending')
        ->assertJsonPath('history.0.event_type', 'transaction.imported')
        ->assertJsonCount(1, 'history');
});

it('returns 404 for an unknown transaction id', function () {
    $response = $this->getJson('/api/transactions/'.(string) Str::uuid());

    $response->assertStatus(404);
});

it('returns 404 for a non-uuid transaction id', function () {
    $response = $this->getJson('/api/transactions/not-a-uuid');

    $response->assertStatus(404);
});

it('returns 422 for an unrecognized state filter value', function () {
    $response = $this->getJson('/api/transactions?state=NotARealState');

    $response->assertStatus(422)->assertJsonValidationErrors('state');
});
