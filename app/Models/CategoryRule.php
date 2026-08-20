<?php

namespace App\Models;

use Database\Factories\CategoryRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $account_id
 * @property string $name
 * @property string $match_field
 * @property string $match_type
 * @property array<int, string> $match_values
 * @property int|null $amount_min_minor
 * @property int|null $amount_max_minor
 * @property int|null $amount_minor
 * @property int|null $day_of_month
 * @property string|null $set_category
 * @property string|null $set_name
 * @property array<int, string>|null $set_tags
 * @property int $priority
 * @property bool $stops_processing
 * @property bool $is_active
 * @property int $times_applied
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Account|null $account
 */
#[Fillable([
    'user_id',
    'account_id',
    'name',
    'match_field',
    'match_type',
    'match_values',
    'amount_min_minor',
    'amount_max_minor',
    'amount_minor',
    'day_of_month',
    'set_category',
    'set_name',
    'set_tags',
    'priority',
    'stops_processing',
    'is_active',
])]
class CategoryRule extends Model
{
    /** @use HasFactory<CategoryRuleFactory> */
    use HasFactory;

    /**
     * Transaction columns a rule is allowed to test against.
     *
     * @var array<int, string>
     */
    public const MATCH_FIELDS = ['any', 'name', 'description', 'merchant_name'];

    /**
     * @var array<int, string>
     */
    public const MATCH_TYPES = ['contains', 'equals', 'starts_with', 'regex'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_values' => 'array',
            'set_tags' => 'array',
            'stops_processing' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'times_applied' => 'integer',
            'amount_min_minor' => 'integer',
            'amount_max_minor' => 'integer',
            'amount_minor' => 'integer',
            'day_of_month' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Active rules in the order they should be evaluated.
     *
     * @param  Builder<CategoryRule>  $query
     * @return Builder<CategoryRule>
     */
    public function scopeActiveInPriorityOrder(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id');
    }

    /**
     * Whether this rule applies to the given transaction.
     */
    public function matches(Transaction $transaction): bool
    {
        if ($this->account_id !== null && $this->account_id !== $transaction->account_id) {
            return false;
        }

        if ($this->amount_min_minor !== null && $transaction->amount_minor < $this->amount_min_minor) {
            return false;
        }

        if ($this->amount_max_minor !== null && $transaction->amount_minor > $this->amount_max_minor) {
            return false;
        }

        /**
         * Exact conditions, sitting alongside the bounds rather than replacing
         * them. Both are signed and both narrow the rule: a rule still has to
         * match its text before either is consulted.
         */
        if ($this->amount_minor !== null && $transaction->amount_minor !== $this->amount_minor) {
            return false;
        }

        /**
         * The day of the month on its own, ignoring which month and year the
         * transaction landed in, so a rule can follow a recurring payment
         * that arrives on the same day every month.
         */
        if ($this->day_of_month !== null && $transaction->booked_at->day !== $this->day_of_month) {
            return false;
        }

        foreach ($this->haystacks($transaction) as $haystack) {
            if ($this->matchesHaystack($haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The strings this rule tests against, given its match field.
     *
     * @return array<int, string>
     */
    private function haystacks(Transaction $transaction): array
    {
        $candidates = $this->match_field === 'any'
            ? [$transaction->name, $transaction->description, $transaction->merchant_name]
            : [$transaction->{$this->match_field}];

        return array_values(array_filter(
            $candidates,
            fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
    }

    /**
     * A rule holds a list of strings and matches on any of them, so the first
     * one that matches settles it.
     */
    private function matchesHaystack(string $haystack): bool
    {
        foreach ($this->match_values ?? [] as $needle) {
            if ($this->matchesNeedle($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function matchesNeedle(string $haystack, string $needle): bool
    {
        return match ($this->match_type) {
            'equals' => mb_strtolower($haystack) === mb_strtolower($needle),
            'starts_with' => Str::startsWith(mb_strtolower($haystack), mb_strtolower($needle)),
            'regex' => $this->matchesRegex($haystack, $needle),
            default => Str::contains($haystack, $needle, ignoreCase: true),
        };
    }

    /**
     * A malformed pattern is treated as a non-match rather than allowed to
     * blow up an entire import.
     */
    private function matchesRegex(string $haystack, string $pattern): bool
    {
        $result = @preg_match('/'.str_replace('/', '\/', $pattern).'/i', $haystack);

        return $result === 1;
    }
}
