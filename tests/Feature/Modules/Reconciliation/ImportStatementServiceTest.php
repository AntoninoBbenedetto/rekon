<?php

use App\Modules\Reconciliation\Application\ImportStatementService;
use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Infrastructure\CsvStatementParser;
use App\Modules\Reconciliation\Infrastructure\MalformedStatementException;
use App\Modules\Reconciliation\Infrastructure\Outbox\OutboxWriter;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use App\Modules\Reconciliation\Infrastructure\StatementRowValidator;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\TransactionId;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function importService(): ImportStatementService
{
    return new ImportStatementService(
        new CsvStatementParser,
        new StatementRowValidator,
        new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map())),
        new TransactionReadModelProjector,
        new OutboxWriter,
    );
}

function outboxTransactionIds(): array
{
    return DB::table('outbox')
        ->where('message_type', 'match_pending_transaction')
        ->pluck('payload')
        ->map(fn (string $payload) => json_decode($payload, true)['transaction_id'])
        ->all();
}

const VALID_STATEMENT_CSV = <<<'CSV'
reference,amount_minor_units,currency,statement_date
REF-1,12345,EUR,2026-07-31
REF-2,500,EUR,2026-07-31
CSV;

it('imports every valid row and writes an outbox message for each', function () {
    $summary = importService()->import(VALID_STATEMENT_CSV, Actor::apiCaller('caller-1'), (string) Str::uuid());

    expect($summary->rowsReceived)->toBe(2)
        ->and($summary->rowsImported)->toBe(2)
        ->and($summary->rowsAlreadyImported)->toBe(0)
        ->and($summary->rowsInvalid)->toBe(0)
        ->and($summary->transactionIds)->toHaveCount(2);

    expect(outboxTransactionIds())->toEqualCanonicalizing($summary->transactionIds);
});

it('is idempotent: re-importing the same statement imports nothing new and writes no new outbox rows', function () {
    importService()->import(VALID_STATEMENT_CSV, Actor::apiCaller('caller-1'), (string) Str::uuid());
    $second = importService()->import(VALID_STATEMENT_CSV, Actor::apiCaller('caller-1'), (string) Str::uuid());

    expect($second->rowsImported)->toBe(0)
        ->and($second->rowsAlreadyImported)->toBe(2)
        ->and(DB::table('outbox')->count())->toBe(2);
});

it('imports two genuinely identical rows as two distinct transactions, and a resubmission of both as a no-op', function () {
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-1,12345,EUR,2026-07-31";

    $summary = importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid());

    expect($summary->rowsImported)->toBe(2)
        ->and($summary->transactionIds[0])->not->toBe($summary->transactionIds[1]);

    $resubmitted = importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid());
    expect($resubmitted->rowsImported)->toBe(0)
        ->and($resubmitted->rowsAlreadyImported)->toBe(2)
        ->and(DB::table('outbox')->count())->toBe(2);
});

it('reports content-invalid rows without failing the rest of the import', function () {
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-2,not-a-number,EUR,2026-07-31";

    $summary = importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid());

    expect($summary->rowsImported)->toBe(1)
        ->and($summary->rowsInvalid)->toBe(1)
        ->and($summary->invalidRows[0]['row_number'])->toBe(2);
});

it('re-projects the transaction current state on an idempotent re-import', function () {
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31";

    $summary = importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid());
    $id = TransactionId::fromString($summary->transactionIds[0]);

    // Advance the transaction beyond Pending, as a matching run would.
    $repository = app(TransactionRepository::class);
    $transaction = $repository->find($id);
    $transaction->markMatched((string) Str::uuid(), Actor::system(), (string) Str::uuid(), (string) Str::uuid());
    $repository->save($transaction);

    // Simulate the read model having missed that state change (stale row).
    TransactionProjection::query()->where('transaction_id', $id->value)->update(['state' => 'Pending']);

    importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid());

    expect(TransactionProjection::query()->find($id->value)->state)->toBe('Reconciled');
});

it('throws for a structurally invalid CSV', function () {
    $csv = "reference,amount_minor_units\nREF-1,12345";

    expect(fn () => importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid()))
        ->toThrow(MalformedStatementException::class);
});
