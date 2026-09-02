<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure;

use App\Modules\Reconciliation\Application\ImportStatementRow;
use App\Modules\Reconciliation\Infrastructure\Rules\ValidCurrencyRule;
use App\Modules\Reconciliation\Infrastructure\Rules\ValidMoneyAmountRule;
use App\Modules\SharedKernel\Domain\Currency;
use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;

final class StatementRowValidator
{
    /** @return array{0: ?ImportStatementRow, 1: string[]} */
    public function validate(StatementLine $line): array
    {
        $validator = Validator::make(
            [
                'reference' => $line->reference,
                'amount_minor_units' => $line->amountMinorUnits,
                'currency' => $line->currency,
                'statement_date' => $line->statementDate,
            ],
            [
                'reference' => ['required', 'string'],
                'amount_minor_units' => ['required', new ValidMoneyAmountRule()],
                'currency' => ['required', new ValidCurrencyRule()],
                'statement_date' => ['required', 'date_format:Y-m-d'],
            ],
        );

        if ($validator->fails()) {
            return [null, $validator->errors()->all()];
        }

        $statementDate = DateTimeImmutable::createFromFormat('!Y-m-d', $line->statementDate);
        assert($statementDate instanceof DateTimeImmutable);

        $row = new ImportStatementRow(
            rowNumber: $line->rowNumber,
            reference: trim($line->reference),
            amountMinorUnits: (int) $line->amountMinorUnits,
            currency: Currency::from(strtoupper($line->currency)),
            statementDate: $statementDate,
            rawLine: $line->rawLine,
        );

        return [$row, []];
    }
}
