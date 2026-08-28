<?php

use App\Modules\Reconciliation\Infrastructure\CsvStatementParser;
use App\Modules\Reconciliation\Infrastructure\MalformedStatementException;

it('parses each data row into a StatementLine, 1-indexed excluding the header', function () {
    $csv = <<<CSV
    reference,amount_minor_units,currency,statement_date
    REF-1,12345,EUR,2026-07-31
    REF-2,500,USD,2026-08-01
    CSV;

    $lines = (new CsvStatementParser())->parse($csv);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->rowNumber)->toBe(1)
        ->and($lines[0]->reference)->toBe('REF-1')
        ->and($lines[0]->amountMinorUnits)->toBe('12345')
        ->and($lines[0]->currency)->toBe('EUR')
        ->and($lines[0]->statementDate)->toBe('2026-07-31')
        ->and($lines[1]->rowNumber)->toBe(2);
});

it('skips blank lines', function () {
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\n\nREF-2,500,USD,2026-08-01\n";

    expect((new CsvStatementParser())->parse($csv))->toHaveCount(2);
});

it('reports every missing required column', function () {
    $csv = "reference,amount_minor_units\nREF-1,12345";

    try {
        (new CsvStatementParser())->parse($csv);
        $this->fail('Expected MalformedStatementException.');
    } catch (MalformedStatementException $e) {
        expect($e->errors)->toContain('Missing required column: currency')
            ->and($e->errors)->toContain('Missing required column: statement_date');
    }
});

it('rejects an empty file', function () {
    expect(fn () => (new CsvStatementParser())->parse(''))->toThrow(MalformedStatementException::class);
});
