<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Console;

use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RebuildProjectionCommand extends Command
{
    protected $signature = 'reconciliation:rebuild-projection';

    protected $description = 'Truncate transactions_read_model and replay it from event_store, making the projection actually disposable (ADR-009).';

    public function handle(TransactionRepository $repository, TransactionReadModelProjector $projector): int
    {
        $aggregateIds = DB::table('event_store')->distinct()->pluck('aggregate_id');

        TransactionProjection::query()->truncate();

        foreach ($aggregateIds as $aggregateId) {
            $transaction = $repository->find(TransactionId::fromString($aggregateId));

            if ($transaction !== null) {
                $projector->project($transaction);
            }
        }

        $this->line("Rebuilt projection for {$aggregateIds->count()} transaction(s).");

        return self::SUCCESS;
    }
}
