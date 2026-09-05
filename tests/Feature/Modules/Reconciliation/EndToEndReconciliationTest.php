<?php

use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Domain\TransactionId;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('reconciles a transaction end-to-end through the API on an exact match', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);

    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    // ADR-009: il matching non parte più in sincrono col dispatch dall'import — l'outbox
    // porta l'intenzione, il relay la trasforma in job. QUEUE_CONNECTION=sync in .env.testing
    // fa sì che il relay esegua il job inline non appena lo dispatcha.
    $this->artisan('reconciliation:relay-outbox');

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

    $this->artisan('reconciliation:relay-outbox');

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

    $this->artisan('reconciliation:relay-outbox');

    $this->getJson("/api/transactions/{$id}")->assertJsonPath('state', 'Unmatched');
});

it('rejects the loser of two concurrent resolutions with a ConcurrencyConflictException', function () {
    $repository = new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map()));

    ExpectedPayment::factory()->count(2)->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    $this->artisan('reconciliation:relay-outbox');

    // Il matching per REF-1 con due candidati esatti produce NeedsReview (multiple_candidates, spec §6.2).
    $transactionId = TransactionId::fromString($id);
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
    expect($repository->find($transactionId)->state())->toBe(TransactionState::Reconciled);
});

it('collapses two real, concurrent OS-process imports of identical statement content into a single aggregate', function () {
    // Reference unica per run: le insert dei due processi figli sono commit reali su connessioni
    // separate, quindi non vengono annullate dal rollback della transazione RefreshDatabase del
    // processo padre. Derivando un aggregate id mai usato prima si evita qualunque collisione di
    // valore con il residuo del proprio run precedente o con altri test del file che riusano REF-1.
    $reference = 'REF-CONCURRENT-'.Str::uuid();
    $csvPath = storage_path('app/concurrent-import-test-'.Str::uuid().'.csv');
    file_put_contents($csvPath, "reference,amount_minor_units,currency,statement_date\n{$reference},12345,EUR,2026-07-31");

    // Due processi php artisan reali (niente pcntl nell'immagine), stessa connessione Postgres reale
    // (--env=testing forza .env.testing) avviati in modo asincrono per sovrapporre davvero le due
    // INSERT su event_store(aggregate_id, version). Sotto `pest --parallel`, RefreshDatabase sposta
    // il processo padre su un DB per-worker (`{database}_test_{TOKEN}`, comportamento di default di
    // Laravel quando la test class usa RefreshDatabase) che .env.testing non conosce: passiamo quindi
    // esplicitamente il nome DB effettivo del padre come env var, che Symfony Process somma
    // all'ambiente ereditato (host/porta/credenziali restano identici tra padre e figli).
    $processes = array_map(
        fn (string $actor) => new Process([
            PHP_BINARY, 'artisan', 'reconciliation:import-statement', $csvPath,
            "--actor={$actor}", '--env=testing',
        ], base_path(), ['DB_DATABASE' => DB::connection()->getDatabaseName()]),
        ['reviewer-1', 'reviewer-2'],
    );

    foreach ($processes as $process) {
        $process->start();
    }
    foreach ($processes as $process) {
        $process->wait();
    }

    unlink($csvPath);

    foreach ($processes as $process) {
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    $outcomes = array_map(fn (Process $process) => json_decode($process->getOutput(), true), $processes);
    $rowsImported = array_column($outcomes, 'rows_imported');
    $rowsAlreadyImported = array_column($outcomes, 'rows_already_imported');

    expect($rowsImported)->toEqualCanonicalizing([1, 0])
        ->and($rowsAlreadyImported)->toEqualCanonicalizing([0, 1]);

    $transactionId = collect($outcomes)->firstWhere('rows_imported', 1)['transaction_ids'][0];

    // Connessione PDO indipendente, non gestita da RefreshDatabase: le due connessioni figlie hanno
    // già fatto commit reale, quindi il cleanup deve avvenire fuori dalla transazione (rolled back)
    // del processo padre, altrimenti le righe restano visibili ad altri test dello stesso processo
    // che fanno assert su conteggi assoluti della tabella (non filtrati per reference).
    $rawConnection = new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            config('database.connections.pgsql.host'),
            config('database.connections.pgsql.port'),
            config('database.connections.pgsql.database'),
        ),
        config('database.connections.pgsql.username'),
        config('database.connections.pgsql.password'),
    );

    try {
        expect(DB::table('event_store')->where('aggregate_id', $transactionId)->where('version', 1)->count())->toBe(1)
            ->and(DB::table('transactions_read_model')->where('reference', $reference)->count())->toBe(1);
    } finally {
        // ADR-009: l'import ora scrive anche una riga outbox nella stessa transazione reale
        // dei due sottoprocessi — va ripulita qui per lo stesso motivo delle altre due righe:
        // commit reale su connessione figlia, invisibile al rollback di RefreshDatabase del padre.
        $rawConnection->prepare("delete from outbox where payload->>'transaction_id' = ?")->execute([$transactionId]);
        $rawConnection->prepare('delete from event_store where aggregate_id = ?')->execute([$transactionId]);
        $rawConnection->prepare('delete from transactions_read_model where reference = ?')->execute([$reference]);
    }
});
