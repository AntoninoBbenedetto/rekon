<?php

use App\Modules\Reconciliation\Infrastructure\MatchPendingTransactionJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function insertOutboxRow(string $messageType, array $payload, ?string $correlationId = null, ?DateTimeInterface $createdAt = null): int
{
    return DB::table('outbox')->insertGetId([
        'message_type' => $messageType,
        'payload' => json_encode($payload),
        'correlation_id' => $correlationId ?? (string) Str::uuid(),
        'created_at' => $createdAt ?? now(),
    ]);
}

function insertMatchPendingOutboxRow(?string $transactionId = null, ?DateTimeInterface $createdAt = null): int
{
    return insertOutboxRow(
        'match_pending_transaction',
        ['transaction_id' => $transactionId ?? (string) Str::uuid()],
        createdAt: $createdAt,
    );
}

it('dispatches a job for each outbox row and deletes it', function () {
    Queue::fake();

    insertMatchPendingOutboxRow();
    insertMatchPendingOutboxRow();

    $this->artisan('reconciliation:relay-outbox')->assertExitCode(0);

    Queue::assertPushed(MatchPendingTransactionJob::class, 2);
    expect(DB::table('outbox')->count())->toBe(0);
});

it('respects the --limit option, leaving the remaining rows for the next run', function () {
    Queue::fake();

    insertMatchPendingOutboxRow();
    insertMatchPendingOutboxRow();
    insertMatchPendingOutboxRow();

    $this->artisan('reconciliation:relay-outbox', ['--limit' => 2])->assertExitCode(0);

    Queue::assertPushed(MatchPendingTransactionJob::class, 2);
    expect(DB::table('outbox')->count())->toBe(1);
});

it('drops rows with an unknown message type instead of failing the whole batch', function () {
    Queue::fake();

    insertOutboxRow('something_unknown', []);
    insertMatchPendingOutboxRow();

    $this->artisan('reconciliation:relay-outbox')->assertExitCode(0);

    Queue::assertPushed(MatchPendingTransactionJob::class, 1);
    expect(DB::table('outbox')->count())->toBe(0);
});

it('warns when the remaining backlog is older than the staleness threshold', function () {
    Queue::fake();
    Log::spy();

    insertMatchPendingOutboxRow(createdAt: now()->subSeconds(600));
    insertMatchPendingOutboxRow(createdAt: now()->subSeconds(600));

    $this->artisan('reconciliation:relay-outbox', ['--limit' => 1, '--stale-after' => 60])->assertExitCode(0);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => str_contains($message, 'aging')
            && $context['age_seconds'] >= 600
            && $context['threshold_seconds'] === 60,
    );
});

it('does not warn when the outbox is empty', function () {
    Log::spy();

    $this->artisan('reconciliation:relay-outbox')->assertExitCode(0);

    Log::shouldNotHaveReceived('warning');
});
