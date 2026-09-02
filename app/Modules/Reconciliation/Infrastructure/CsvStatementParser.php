<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure;

final class CsvStatementParser
{
    private const REQUIRED_COLUMNS = ['reference', 'amount_minor_units', 'currency', 'statement_date'];

    /** @return StatementLine[] */
    public function parse(string $csvContents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContents));

        if ($lines === false || $lines === ['']) {
            throw new MalformedStatementException(['The CSV file is empty.']);
        }

        $header = str_getcsv(array_shift($lines));
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $header));

        if ($missingColumns !== []) {
            throw new MalformedStatementException(array_map(
                static fn (string $column) => "Missing required column: {$column}",
                $missingColumns,
            ));
        }

        $columnIndex = array_flip($header);
        $statementLines = [];

        foreach ($lines as $index => $rawLine) {
            if (trim($rawLine) === '') {
                continue;
            }

            $fields = str_getcsv($rawLine);

            $statementLines[] = new StatementLine(
                rowNumber: $index + 1,
                reference: $fields[$columnIndex['reference']] ?? '',
                amountMinorUnits: $fields[$columnIndex['amount_minor_units']] ?? '',
                currency: $fields[$columnIndex['currency']] ?? '',
                statementDate: $fields[$columnIndex['statement_date']] ?? '',
                rawLine: $rawLine,
            );
        }

        return $statementLines;
    }
}
