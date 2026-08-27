<?php

namespace App\Modules\Reconciliation\Application;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\SharedKernel\Application\EventStore;
use App\Modules\SharedKernel\Domain\TransactionId;

final class TransactionRepository
{
    public function __construct(private readonly EventStore $eventStore)
    {
    }

    public function find(TransactionId $id): ?Transaction
    {
        $events = $this->eventStore->loadStream($id->value);

        if ($events === []) {
            return null;
        }

        return Transaction::reconstituteFromStream($events);
    }

    public function save(Transaction $transaction): void
    {
        $events = $transaction->releaseEvents();

        if ($events === []) {
            return;
        }

        $expectedVersion = $transaction->version() - count($events);

        $this->eventStore->append($transaction->aggregateId(), $expectedVersion, $events);
    }
}
