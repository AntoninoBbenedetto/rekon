<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;

final class TransactionReadModelProjector
{
    public function project(Transaction $transaction): void
    {
        TransactionProjection::query()->updateOrInsert(
            ['transaction_id' => $transaction->aggregateId()],
            [
                'state' => $transaction->state()->value,
                'version' => $transaction->version(),
                'amount_minor_units' => $transaction->money()->amountMinorUnits,
                'currency' => $transaction->money()->currency->value,
                'reference' => $transaction->reference(),
                'statement_date' => $transaction->statementDate()->format('Y-m-d'),
                'matched_expected_payment_id' => $transaction->matchedExpectedPaymentId(),
                'imported_at' => $transaction->importedAt(),
                'updated_at' => now(),
            ],
        );
    }
}
