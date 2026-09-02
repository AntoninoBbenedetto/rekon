<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ];
    }
}
