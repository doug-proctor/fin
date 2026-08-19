<?php

namespace App\Http\Requests\Transactions;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof Category
            && $category->user_id === $this->user()->id;
    }

    /**
     * Only the label is editable. The value is the bank's handle on the
     * category and changing it would orphan every transaction using it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
        ];
    }
}
