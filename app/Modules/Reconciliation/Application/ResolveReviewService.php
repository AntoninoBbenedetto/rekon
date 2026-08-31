<?php

namespace App\Modules\Reconciliation\Application;

use App\Modules\Reconciliation\Domain\Exceptions\TransactionNotFound;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Support\Str;

final class ResolveReviewService
{
    public function __construct(
        private readonly TransactionRepository $repository,
        private readonly TransactionReadModelProjector $projector,
    ) {
    }

    public function confirm(TransactionId $id, string $expectedPaymentId, Actor $actor): Transaction
    {
        $transaction = $this->load($id);

        $transaction->resolveByConfirming($expectedPaymentId, $actor, (string) Str::uuid(), (string) Str::uuid());

        $this->repository->save($transaction);
        $this->projector->project($transaction);

        return $transaction;
    }

    public function reject(TransactionId $id, string $reason, Actor $actor): Transaction
    {
        $transaction = $this->load($id);

        $transaction->resolveByRejecting($reason, $actor, (string) Str::uuid(), (string) Str::uuid());

        $this->repository->save($transaction);
        $this->projector->project($transaction);

        return $transaction;
    }

    private function load(TransactionId $id): Transaction
    {
        return $this->repository->find($id) ?? throw new TransactionNotFound($id->value);
    }
}
