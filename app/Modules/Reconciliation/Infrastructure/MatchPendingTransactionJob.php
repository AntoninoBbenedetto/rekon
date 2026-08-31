<?php

namespace App\Modules\Reconciliation\Infrastructure;

use App\Modules\Reconciliation\Application\MatchTransactionService;
use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class MatchPendingTransactionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(
        public readonly string $transactionId,
        public readonly string $correlationId,
    ) {
    }

    public function handle(
        TransactionRepository $repository,
        MatchTransactionService $matcher,
        TransactionReadModelProjector $projector,
    ): void {
        $transaction = $repository->find(TransactionId::fromString($this->transactionId));

        if ($transaction === null || $transaction->state() !== TransactionState::Pending) {
            return;
        }

        $matcher->match($transaction, Actor::system(), (string) Str::uuid(), $this->correlationId);

        $repository->save($transaction);
        $projector->project($transaction);
    }
}
