<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use App\Modules\Reconciliation\Domain\TransactionState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class ListTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string|Enum>> */
    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', Rule::enum(TransactionState::class)],
        ];
    }
}
