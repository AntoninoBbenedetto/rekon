<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\CsvStatementParser;
use App\Modules\Reconciliation\Infrastructure\Outbox\OutboxWriter;
use App\Modules\Reconciliation\Infrastructure\StatementRowValidator;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportStatementService
{
    public function __construct(
        private readonly CsvStatementParser $parser,
        private readonly StatementRowValidator $rowValidator,
        private readonly TransactionRepository $repository,
        private readonly TransactionReadModelProjector $projector,
        private readonly OutboxWriter $outbox,
    ) {}

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
            foreach ($rowsInGroup as $occurrenceIndex => $row) {
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
                    DB::transaction(function () use ($transaction, $correlationId) {
                        $this->repository->save($transaction);
                        $this->projector->project($transaction);
                        $this->outbox->publish(
                            'match_pending_transaction',
                            ['transaction_id' => $transaction->aggregateId()],
                            $correlationId,
                        );
                    });
                } catch (ConcurrencyConflictException) {
                    $alreadyImportedCount++;

                    $existing = $this->repository->find($transactionId);
                    if ($existing !== null) {
                        $this->projector->project($existing);
                    }

                    continue;
                }

                $importedIds[] = $transactionId->value;
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
