<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application;

final class ImportSummary
{
    /**
     * @param  string[]  $transactionIds
     * @param  array<int, array{row_number: int, errors: string[]}>  $invalidRows
     */
    public function __construct(
        public readonly int $rowsReceived,
        public readonly int $rowsImported,
        public readonly int $rowsAlreadyImported,
        public readonly int $rowsInvalid,
        public readonly array $invalidRows,
        public readonly array $transactionIds,
    ) {}
}
