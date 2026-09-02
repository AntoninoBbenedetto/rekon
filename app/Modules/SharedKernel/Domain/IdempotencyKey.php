<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Domain;

use DateTimeImmutable;

final class IdempotencyKey
{
    private function __construct(public readonly string $value) {}

    public static function forStatementRow(
        string $reference,
        int $amountMinorUnits,
        Currency $currency,
        DateTimeImmutable $statementDate,
        int $occurrenceIndex,
    ): self {
        $normalized = implode('|', [
            trim($reference),
            (string) $amountMinorUnits,
            $currency->value,
            $statementDate->format('Y-m-d'),
            (string) $occurrenceIndex,
        ]);

        return new self(hash('sha256', $normalized));
    }

    public function equals(IdempotencyKey $other): bool
    {
        return $this->value === $other->value;
    }
}
