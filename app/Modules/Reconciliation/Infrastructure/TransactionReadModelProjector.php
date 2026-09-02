<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use Illuminate\Database\UniqueConstraintViolationException;

final class TransactionReadModelProjector
{
    public function project(Transaction $transaction): void
    {
        $attributes = [
            'state' => $transaction->state()->value,
            'version' => $transaction->version(),
            'amount_minor_units' => $transaction->money()->amountMinorUnits,
            'currency' => $transaction->money()->currency->value,
            'reference' => $transaction->reference(),
            'statement_date' => $transaction->statementDate()->format('Y-m-d'),
            'matched_expected_payment_id' => $transaction->matchedExpectedPaymentId(),
            'imported_at' => $transaction->importedAt(),
            'updated_at' => now(),
        ];

        try {
            TransactionProjection::query()->updateOrInsert(
                ['transaction_id' => $transaction->aggregateId()],
                $attributes,
            );
        } catch (UniqueConstraintViolationException) {
            // updateOrInsert() non è atomico (SELECT poi INSERT/UPDATE): con due processi reali che
            // proiettano lo stesso aggregate in concomitanza, entrambi possono vedere "nessuna riga"
            // ed entrambi tentare l'INSERT. Chi perde la corsa converte il conflitto in un update,
            // visto che a questo punto la riga esiste di sicuro (l'ha appena scritta l'altro processo).
            TransactionProjection::query()
                ->where('transaction_id', $transaction->aggregateId())
                ->update($attributes);
        }
    }
}
