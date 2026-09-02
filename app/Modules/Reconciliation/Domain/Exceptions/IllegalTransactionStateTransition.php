<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Exceptions;

use App\Modules\Reconciliation\Domain\TransactionState;
use RuntimeException;

final class IllegalTransactionStateTransition extends RuntimeException
{
    public function __construct(
        public readonly string $transactionId,
        public readonly TransactionState $currentState,
        public readonly TransactionState $expectedState,
    ) {
        parent::__construct(
            "Transaction {$transactionId} is in state {$currentState->value}, expected {$expectedState->value}.",
        );
    }
}
