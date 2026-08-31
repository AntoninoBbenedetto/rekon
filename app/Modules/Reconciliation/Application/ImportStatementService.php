<?php

namespace App\Modules\Reconciliation\Application;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\CsvStatementParser;
use App\Modules\Reconciliation\Infrastructure\MatchPendingTransactionJob;
use App\Modules\Reconciliation\Infrastructure\StatementRowValidator;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Support\Str;

final class ImportStatementService
{
    public function __construct(
        private readonly CsvStatementParser $parser,
        private readonly StatementRowValidator $rowValidator,
        private readonly TransactionRepository $repository,
        private readonly TransactionReadModelProjector $projector,
    ) {
    }

    public function import(string $csvContents, Actor $actor, string $correlationId): ImportSummary
    {
        $lines = $this->parser->parse($csvContents);

        $validRows = [];
        $invalidRows = [];

        foreach ($lines as $line) {
            [$row, $errors] = $this->rowValidator->validate($line);

            if ($row === null) {
                $invalidRows[] = ['row_number' => $line->rowNumber, 'errors' => $errors];
                continue;
            }

            $validRows[] = $row;
        }

        $groups = [];
        foreach ($validRows as $row) {
            $groupKey = implode('|', [
                $row->reference,
                $row->amountMinorUnits,
                $row->currency->value,
                $row->statementDate->format('Y-m-d'),
            ]);
            $groups[$groupKey][] = $row;
        }

        $importedIds = [];
        $alreadyImportedCount = 0;

        foreach ($groups as $rowsInGroup) {
            foreach (array_values($rowsInGroup) as $occurrenceIndex => $row) {
                $idempotencyKey = IdempotencyKey::forStatementRow(
                    $row->reference,
                    $row->amountMinorUnits,
                    $row->currency,
                    $row->statementDate,
                    $occurrenceIndex,
                );
                $transactionId = TransactionId::deriveFrom($idempotencyKey);

                $transaction = Transaction::import(
                    id: $transactionId,
                    money: new Money($row->amountMinorUnits, $row->currency),
                    reference: $row->reference,
                    statementDate: $row->statementDate,
                    occurrenceIndex: $occurrenceIndex,
                    idempotencyKey: $idempotencyKey,
                    rawRowChecksum: hash('sha256', $row->rawLine),
                    actor: $actor,
                    causationId: (string) Str::uuid(),
                    correlationId: $correlationId,
                );

                try {
                    $this->repository->save($transaction);
                } catch (ConcurrencyConflictException) {
                    $alreadyImportedCount++;

                    $existing = $this->repository->find($transactionId);
                    if ($existing !== null) {
                        $this->projector->project($existing);
                    }

                    continue;
                }

                $this->projector->project($transaction);
                $importedIds[] = $transactionId->value;

                MatchPendingTransactionJob::dispatch($transactionId->value, $correlationId);
            }
        }

        return new ImportSummary(
            rowsReceived: count($lines),
            rowsImported: count($importedIds),
            rowsAlreadyImported: $alreadyImportedCount,
            rowsInvalid: count($invalidRows),
            invalidRows: $invalidRows,
            transactionIds: $importedIds,
        );
    }
}
