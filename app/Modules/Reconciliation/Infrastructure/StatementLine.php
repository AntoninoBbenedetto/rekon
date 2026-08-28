<?php

namespace App\Modules\Reconciliation\Infrastructure;

final class StatementLine
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $reference,
        public readonly string $amountMinorUnits,
        public readonly string $currency,
        public readonly string $statementDate,
        public readonly string $rawLine,
    ) {
    }
}
