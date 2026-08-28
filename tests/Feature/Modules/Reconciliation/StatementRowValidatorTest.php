<?php

use App\Modules\Reconciliation\Infrastructure\StatementLine;
use App\Modules\Reconciliation\Infrastructure\StatementRowValidator;
use App\Modules\SharedKernel\Domain\Currency;

it('validates and normalizes a well-formed row', function () {
    $line = new StatementLine(1, ' REF-1 ', '12345', 'eur', '2026-07-31', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($errors)->toBe([])
        ->and($row->reference)->toBe('REF-1')
        ->and($row->amountMinorUnits)->toBe(12345)
        ->and($row->currency)->toBe(Currency::EUR)
        ->and($row->statementDate->format('Y-m-d'))->toBe('2026-07-31');
});

it('rejects a non-numeric amount', function () {
    $line = new StatementLine(1, 'REF-1', 'not-a-number', 'EUR', '2026-07-31', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($row)->toBeNull()->and($errors)->not->toBe([]);
});

it('rejects an unsupported currency code', function () {
    $line = new StatementLine(1, 'REF-1', '12345', 'XXX', '2026-07-31', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($row)->toBeNull()->and($errors)->not->toBe([]);
});

it('rejects a malformed date', function () {
    $line = new StatementLine(1, 'REF-1', '12345', 'EUR', '31/07/2026', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($row)->toBeNull()->and($errors)->not->toBe([]);
});

it('rejects a missing reference', function () {
    $line = new StatementLine(1, '', '12345', 'EUR', '2026-07-31', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($row)->toBeNull()->and($errors)->not->toBe([]);
});
