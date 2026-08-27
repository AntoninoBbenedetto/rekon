<?php

namespace App\Modules\Reconciliation\Application;

use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\SharedKernel\Domain\Actor;

final class MatchTransactionService
{
    public function match(Transaction $transaction, Actor $actor, string $causationId, string $correlationId): void
    {
        $candidates = ExpectedPayment::query()
            ->where('reference', $transaction->reference())
            ->get();

        match ($candidates->count()) {
            0 => $transaction->markUnmatched($actor, $causationId, $correlationId),
            1 => $this->resolveSingleCandidate($transaction, $candidates->first(), $actor, $causationId, $correlationId),
            default => $transaction->markAmbiguous(
                $candidates->pluck('id')->all(),
                'multiple_candidates',
                $actor,
                $causationId,
                $correlationId,
            ),
        };
    }

    private function resolveSingleCandidate(
        Transaction $transaction,
        ExpectedPayment $candidate,
        Actor $actor,
        string $causationId,
        string $correlationId,
    ): void {
        $money = $transaction->money();

        if ($candidate->amount_minor_units === $money->amountMinorUnits && $candidate->currency === $money->currency->value) {
            $transaction->markMatched($candidate->id, $actor, $causationId, $correlationId);

            return;
        }

        $transaction->markAmbiguous([$candidate->id], 'partial_amount_match', $actor, $causationId, $correlationId);
    }
}
