<?php

use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('imports a valid CSV statement and reports a correlation id', function () {
    Queue::fake();

    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-2,500,EUR,2026-07-31";
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    $response = $this->postJson('/api/imports', ['file' => $file], ['X-Actor-Id' => 'caller-1']);

    $response->assertOk()
        ->assertJsonPath('rows_received', 2)
        ->assertJsonPath('rows_imported', 2)
        ->assertJsonPath('rows_already_imported', 0)
        ->assertJsonPath('rows_invalid', 0)
        ->assertJsonStructure(['correlation_id', 'transaction_ids']);

    expect(TransactionProjection::query()->count())->toBe(2);
});

it('returns 422 for a structurally invalid CSV', function () {
    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units\nREF-1,12345");

    $response = $this->postJson('/api/imports', ['file' => $file]);

    $response->assertStatus(422)->assertJsonStructure(['errors']);
});

it('returns 422 when no file is provided', function () {
    $response = $this->postJson('/api/imports', []);

    $response->assertStatus(422);
});

it('reports content-invalid rows in the response without failing the request', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-2,not-a-number,EUR,2026-07-31";
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    $response = $this->postJson('/api/imports', ['file' => $file]);

    $response->assertOk()
        ->assertJsonPath('rows_imported', 1)
        ->assertJsonPath('rows_invalid', 1);
});

it('is idempotent over HTTP: resubmitting the same statement imports nothing new', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31";

    $file1 = UploadedFile::fake()->createWithContent('statement.csv', $csv);
    $this->postJson('/api/imports', ['file' => $file1])->assertOk();

    $file2 = UploadedFile::fake()->createWithContent('statement.csv', $csv);
    $response = $this->postJson('/api/imports', ['file' => $file2]);

    $response->assertJsonPath('rows_imported', 0)->assertJsonPath('rows_already_imported', 1);
});
