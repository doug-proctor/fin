<?php

namespace App\Http\Requests\Transactions;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Support\Transactions\TransactionData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CategoryRuleRequest extends FormRequest
{
    /**
     * A rule's tags are normalised before they are validated, exactly as a
     * hand edited transaction's are in TransactionUpdateRequest. Without this
     * a rule could write "Work Lunch" onto a row the edit dialog would only
     * ever spell "work-lunch", and the two would sit side by side as separate
     * tags for the rest of the row's life.
     *
     * A list left empty is stored as null, which is what withValidator() reads
     * as "this rule sets no tags".
     */
    protected function prepareForValidation(): void
    {
        $this->prepareMatchValues();

        $tags = $this->input('set_tags');

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

        $this->merge(['set_tags' => TransactionData::normaliseTags(
            array_filter($tags, is_string(...)),
        )]);
    }

    /**
     * A rule looks for a list of strings. The form always sends a list, and
     * the box the user added and then left alone comes through as an empty
     * one, so blanks are dropped rather than reported: an empty box asks for
     * nothing. Exact repeats go too, because a second copy of a string cannot
     * change what the rule matches.
     *
     * A list emptied out this way is left empty, which the rules below refuse.
     */
    private function prepareMatchValues(): void
    {
        $values = $this->input('match_values');

        if (! is_array($values)) {
            return;
        }

        foreach ($values as $value) {
            /** Anything that is not text is left for the rules to report. */
            if (! is_string($value) && $value !== null) {
                return;
            }
        }

        $trimmed = array_map(trim(...), array_filter($values, is_string(...)));

        $this->merge(['match_values' => array_values(array_unique(
            array_filter($trimmed, fn (string $value): bool => $value !== ''),
        ))]);
    }

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
            'match_values' => ['required', 'array', 'min:1'],
            'match_values.*' => ['required', 'string', 'max:255'],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('user_id', $this->user()->id),
            ],
            'amount_min_minor' => ['nullable', 'integer'],
            'amount_max_minor' => ['nullable', 'integer'],
            'amount_minor' => ['nullable', 'integer'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'set_category' => ['nullable', Rule::exists(Category::class, 'value')
                ->where('user_id', $this->user()->id)],
            'set_name' => ['nullable', 'string', 'max:255'],
            'set_tags' => ['nullable', 'array'],
            'set_tags.*' => ['string', 'max:50'],
            'priority' => ['nullable', 'integer', 'between:-1000,1000'],
            'stops_processing' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $atLeastOne = 'Enter at least one piece of text to look for.';

        return [
            'match_values.required' => $atLeastOne,
            'match_values.min' => $atLeastOne,
            'match_values.*.required' => $atLeastOne,
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
            if (blank($this->input('set_category')) && blank($this->input('set_name')) && blank($this->input('set_tags'))) {
                $validator->errors()->add('set_category', 'Choose a category, a new name, or at least one tag to apply.');
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

            /** Each pattern is checked on its own, so the error lands on the box that holds it. */
            if ($this->input('match_type') === 'regex') {
                foreach ((array) $this->input('match_values') as $index => $pattern) {
                    if (! is_string($pattern)) {
                        continue;
                    }

                    if (@preg_match('/'.str_replace('/', '\/', $pattern).'/', '') === false) {
                        $validator->errors()->add("match_values.{$index}", 'That is not a valid regular expression.');
                    }
                }
            }
        });
    }
}
