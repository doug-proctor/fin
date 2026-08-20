<?php

namespace App\Http\Requests\Transactions;

use App\Models\Category;
use App\Models\Transaction;
use App\Support\Transactions\TransactionData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionUpdateRequest extends FormRequest
{
    /**
     * Fields the user may edit directly. Each bank field that is sent is
     * recorded as an override so no later sync can undo the edit; the rest is
     * local state no import writes in the first place.
     *
     * @var array<int, string>
     */
    public const EDITABLE = [
        'booked_at',
        'accounting_date',
        'name',
        'description',
        'category',
        'type',
        'merchant_name',
        'notes',
        'tags',
        'amount_minor',
    ];

    /**
     * Tags are normalised before they are validated, so the list that reaches
     * the database is spelled the one way however it was typed, and a list
     * emptied of its tags is stored as null like every other tagless row.
     */
    protected function prepareForValidation(): void
    {
        $tags = $this->input('tags');

        if (! is_array($tags)) {
            return;
        }

        foreach ($tags as $tag) {
            /**
             * Left exactly as it was sent, so the rules below report it rather
             * than having it quietly normalised away. A null is not that case:
             * ConvertEmptyStringsToNull runs first, so an empty tag arrives as
             * one, and it would normalise away to nothing regardless.
             */
            if (! is_string($tag) && $tag !== null) {
                return;
            }
        }

        $this->merge(['tags' => TransactionData::normaliseTags(
            array_filter($tags, is_string(...)),
        )]);
    }

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
            /**
             * No upper bound: a charge can belong to a month that has not
             * happened yet, such as a flight booked in July for a holiday in
             * August. The forward month arrow reaches whichever month is
             * furthest, so the amount stays visible.
             */
            'accounting_date' => ['sometimes', 'nullable', 'date'],
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
            'processed' => ['sometimes', 'boolean'],
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

    /**
     * Whether this request also marks the row processed, or null when it says
     * nothing about it.
     *
     * Deliberately outside EDITABLE: `processed` is the user's own bookkeeping
     * rather than a value the bank owns, so there is nothing for a later sync
     * to undo and nothing to record in the overrides map.
     */
    public function processedFlag(): ?bool
    {
        $validated = $this->validated();

        return array_key_exists('processed', $validated)
            ? (bool) $validated['processed']
            : null;
    }
}
