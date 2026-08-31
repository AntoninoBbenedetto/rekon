<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use App\Modules\Reconciliation\Domain\TransactionState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', Rule::enum(TransactionState::class)],
        ];
    }
}
