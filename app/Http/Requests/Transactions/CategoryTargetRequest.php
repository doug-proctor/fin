<?php

namespace App\Http\Requests\Transactions;

use App\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CategoryTargetRequest extends FormRequest
{
    /**
     * The route is behind auth and there is no existing row to own yet.
     * Ownership is enforced by scoping the write to the signed in user, never
     * by trusting anything in the payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Amounts arrive as pounds, the way the filter bar sends amount_min and
     * amount_max, and are converted in targetsInMinor(). Validating them in
     * the units the user typed keeps the messages readable: with pence on the
     * wire a max violation would read "must not be greater than 100000000".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $owned = array_keys(Category::labelsFor($this->user()->id));

        return [
            /**
             * The month arrows stop at the current month, so a target set
             * beyond it would sit in a month the user cannot reach.
             */
            'month' => [
                'required',
                'string',
                'regex:/^\d{4}-\d{2}$/',
                /**
                 * Compared as text, not with lte: that rule measures the
                 * length of a string. 'YYYY-MM' is fixed width and zero
                 * padded, so text order is the order the months happened.
                 */
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value > now()->format('Y-m')) {
                        $fail('A target cannot be set for a month that has not happened yet.');
                    }
                },
            ],
            /**
             * Listing the keys is what rejects a category this user does not
             * own. An account always has Category::DEFAULTS, so the empty case
             * cannot happen, but a malformed rule string would be silent.
             */
            'targets' => $owned === []
                ? ['present', 'array', 'size:0']
                : ['present', 'array:'.implode(',', $owned)],
            'targets.*' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * The targets on this request in minor units, keyed by category value.
     *
     * A null means the field was cleared and the target should be removed, as
     * distinct from a 0, which is a target of spending nothing. Blank input
     * arrives as null because ConvertEmptyStringsToNull runs first.
     *
     * @return array<string, int|null>
     */
    public function targetsInMinor(): array
    {
        /** @var array<string, string|int|float|null> $targets */
        $targets = $this->validated('targets');

        return array_map(
            /** Mirrors TransactionFilters::toMinor(). */
            fn ($value): ?int => $value === null ? null : (int) round((float) $value * 100),
            $targets,
        );
    }
}
