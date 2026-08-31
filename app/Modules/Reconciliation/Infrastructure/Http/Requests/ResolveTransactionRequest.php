<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:confirm,reject'],
            'expected_payment_id' => ['required_if:action,confirm', 'uuid'],
            'reason' => ['required_if:action,reject', 'string'],
        ];
    }
}
