<?php

namespace App\Modules\Reconciliation\Domain\Events;

final class TransactionEventTypes
{
    /** @return array<string, class-string<\App\Modules\SharedKernel\Domain\DomainEvent>> */
    public static function map(): array
    {
        return [
            'transaction.imported' => TransactionImported::class,
            'transaction.matched' => TransactionMatched::class,
            'transaction.marked_unmatched' => TransactionMarkedUnmatched::class,
            'transaction.marked_ambiguous' => TransactionMarkedAmbiguous::class,
            'transaction.reconciled' => TransactionReconciled::class,
            'transaction.rejected' => TransactionRejected::class,
        ];
    }
}
