<?php

namespace App\Http\Requests\Transactions;

use Illuminate\Foundation\Http\FormRequest;

class TransactionImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the CSV you downloaded from americanexpress.com.',
            'file.mimetypes' => 'That does not look like a CSV. Download your activity from americanexpress.com as CSV.',
            'file.max' => 'That file is larger than 10MB.',
        ];
    }
}
