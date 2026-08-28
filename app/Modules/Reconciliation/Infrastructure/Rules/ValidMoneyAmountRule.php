<?php

namespace App\Modules\Reconciliation\Infrastructure\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidMoneyAmountRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '' || !preg_match('/^\d+$/', $value)) {
            $fail('The :attribute must be a non-negative integer number of minor units.');
        }
    }
}
