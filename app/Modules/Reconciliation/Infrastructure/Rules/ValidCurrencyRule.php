<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Rules;

use App\Modules\SharedKernel\Domain\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidCurrencyRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Currency::tryFrom(strtoupper($value)) === null) {
            $fail('The :attribute must be a supported ISO 4217 currency code.');
        }
    }
}
