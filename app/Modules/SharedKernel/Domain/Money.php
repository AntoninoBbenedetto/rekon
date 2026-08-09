<?php

namespace App\Modules\SharedKernel\Domain;

use InvalidArgumentException;

final class Money
{
    public function __construct(
        public readonly int $amountMinorUnits,
        public readonly Currency $currency,
    ) {
        if ($amountMinorUnits < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }
    }

    public function equals(Money $other): bool
    {
        return $this->amountMinorUnits === $other->amountMinorUnits
            && $this->currency === $other->currency;
    }
}
