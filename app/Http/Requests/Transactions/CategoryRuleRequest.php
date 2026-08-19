<?php

namespace App\Http\Requests\Transactions;

use App\Models\Category;
use App\Models\CategoryRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CategoryRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('categoryRule');

        /** Absent on store; on update the rule must belong to the signed in user. */
        if (! $rule instanceof CategoryRule) {
            return true;
        }

        return $rule->user_id === $this->user()->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'match_field' => ['required', Rule::in(CategoryRule::MATCH_FIELDS)],
            'match_type' => ['required', Rule::in(CategoryRule::MATCH_TYPES)],
            'match_value' => ['required', 'string', 'max:255'],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('user_id', $this->user()->id),
            ],
            'amount_min_minor' => ['nullable', 'integer'],
            'amount_max_minor' => ['nullable', 'integer'],
            'amount_minor' => ['nullable', 'integer'],
            'booked_on' => ['nullable', 'date'],
            'set_category' => ['nullable', Rule::exists(Category::class, 'value')
                ->where('user_id', $this->user()->id)],
            'set_tags' => ['nullable', 'array'],
            'set_tags.*' => ['string', 'max:50'],
            'priority' => ['nullable', 'integer', 'between:-1000,1000'],
            'stops_processing' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Reject rules that would match rows and then do nothing to them.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /**
             * A rule that sets nothing would match rows and then do nothing to
             * them, which reads as a bug rather than a choice.
             */
            if (blank($this->input('set_category')) && blank($this->input('set_tags'))) {
                $validator->errors()->add('set_category', 'Choose a category or at least one tag to apply.');
            }

            /**
             * An exact amount and a range are two answers to the same
             * question. Together they describe a rule that can only ever
             * match the overlap, which is never what someone filling in both
             * meant, so it is refused rather than silently narrowed.
             */
            if ($this->filled('amount_minor') && ($this->filled('amount_min_minor') || $this->filled('amount_max_minor'))) {
                $validator->errors()->add('amount_minor', 'Use an exact amount or a range, not both.');
            }

            /** A range with its ends the wrong way round can never match. */
            if ($this->filled('amount_min_minor') && $this->filled('amount_max_minor')
                && (int) $this->input('amount_max_minor') < (int) $this->input('amount_min_minor')) {
                $validator->errors()->add('amount_max_minor', 'The most must not be less than the least.');
            }

            if ($this->input('match_type') === 'regex' && @preg_match('/'.str_replace('/', '\/', (string) $this->input('match_value')).'/', '') === false) {
                $validator->errors()->add('match_value', 'That is not a valid regular expression.');
            }
        });
    }
}
