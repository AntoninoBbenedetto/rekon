<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application;

use App\Modules\SharedKernel\Domain\Currency;
use DateTimeImmutable;

final class ImportStatementRow
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $reference,
        public readonly int $amountMinorUnits,
        public readonly Currency $currency,
        public readonly DateTimeImmutable $statementDate,
        public readonly string $rawLine,
    ) {
    }
}
