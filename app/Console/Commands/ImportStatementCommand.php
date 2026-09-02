<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Reconciliation\Application\ImportStatementService;
use App\Modules\SharedKernel\Domain\Actor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ImportStatementCommand extends Command
{
    protected $signature = 'reconciliation:import-statement {path} {--actor=cli-process} {--correlation=}';

    protected $description = 'Import a statement CSV via ImportStatementService. Testing-only: entry point for spawning real concurrent OS processes in tests.';

    public function handle(ImportStatementService $service): int
    {
        if (! $this->laravel->environment('testing')) {
            $this->error('reconciliation:import-statement can only run in the testing environment.');

            return self::FAILURE;
        }

        $csv = file_get_contents($this->argument('path'));

        $summary = $service->import(
            $csv,
            Actor::apiCaller($this->option('actor')),
            $this->option('correlation') ?: (string) Str::uuid(),
        );

        $this->line(json_encode([
            'rows_imported' => $summary->rowsImported,
            'rows_already_imported' => $summary->rowsAlreadyImported,
            'transaction_ids' => $summary->transactionIds,
        ]));

        return self::SUCCESS;
    }
}
