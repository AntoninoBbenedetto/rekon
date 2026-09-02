<?php

declare(strict_types=1);

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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class MatchPendingTransactionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * Retrying is safe by construction: handle() re-reads the transaction's
     * current state and no-ops unless it is still Pending, so a redelivery
     * cannot double-apply matching. Without an explicit policy the transaction
     * would instead be stranded in Pending by any transient failure.
     */
    public int $tries = 5;

    /**
     * Growing backoff, not a fixed delay: the loser of an optimistic-concurrency
     * race (ADR-003) must give the winner time to commit. Retrying immediately
     * would contend for the same aggregate row and lose again.
     *
     * @var int[]
     */
    public array $backoff = [5, 15, 60, 180];

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

        if ($transaction === null) {
            return;
        }

        if ($transaction->state() !== TransactionState::Pending) {
            // A previous run already advanced this transaction; re-project its
            // current state in case that run's own projection did not land.
            $projector->project($transaction);

            return;
        }

        $matcher->match($transaction, Actor::system(), (string) Str::uuid(), $this->correlationId);

        $repository->save($transaction);
        $projector->project($transaction);
    }

    /**
     * Once retries are exhausted the transaction stays Pending indefinitely and
     * nothing else in v1 re-drives it. Record that explicitly: an unmatched
     * transaction nobody knows about is the failure mode worth surfacing, not
     * the exception itself.
     *
     * Identifiers only — statement contents are financial data and stay out of
     * the logs (PROJECT_CONTEXT.md, Security).
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Matching permanently failed; transaction left Pending.', [
            'transaction_id' => $this->transactionId,
            'correlation_id' => $this->correlationId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
