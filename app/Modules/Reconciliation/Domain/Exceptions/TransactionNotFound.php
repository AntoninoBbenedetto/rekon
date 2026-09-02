<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Exceptions;

use RuntimeException;

final class TransactionNotFound extends RuntimeException
{
    public function __construct(public readonly string $transactionId)
    {
        parent::__construct("Transaction {$transactionId} not found.");
    }
}
