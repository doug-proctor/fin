<?php

namespace App\Http\Requests\Transactions;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionUpdateRequest extends FormRequest
{
    /**
     * Fields the user may edit directly. Each one that is sent is recorded as
     * an override so no later sync can undo the edit.
     *
     * @var array<int, string>
     */
    public const EDITABLE = [
        'booked_at',
        'name',
        'description',
        'category',
        'type',
        'merchant_name',
        'notes',
        'tags',
        'amount_minor',
    ];

    public function authorize(): bool
    {
        /** @var Transaction $transaction */
        $transaction = $this->route('transaction');

        return $transaction->user_id === $this->user()->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'booked_at' => ['sometimes', 'date'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'nullable', Rule::exists(Category::class, 'value')
                ->where('user_id', $this->user()->id)],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'merchant_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'amount_minor' => ['sometimes', 'integer', 'between:-100000000,100000000'],
        ];
    }

    /**
     * The subset of editable bank fields actually present on this request.
     *
     * @return array<string, mixed>
     */
    public function editedFields(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::EDITABLE));
    }
}
