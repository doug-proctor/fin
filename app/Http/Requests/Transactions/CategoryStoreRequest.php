<?php

namespace App\Http\Requests\Transactions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryStoreRequest extends FormRequest
{
    /** The route is behind auth and there is no existing row to own yet. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the name is asked for. The value is derived from it, because a
     * category made here has no handle from the bank to store.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => [
                'required',
                'string',
                'max:60',
                Rule::unique('categories', 'label')->where('user_id', $this->user()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.unique' => 'You already have a category with that name.',
        ];
    }
}
