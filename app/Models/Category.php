<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A category a transaction can be filed under, owned by one user.
 *
 * The value is what `transactions.category` stores and is whatever Monzo
 * sends, so it survives a re-sync. The label is only for display, which is
 * what makes a custom category usable: Monzo sends those as an opaque id and
 * will not tell a third party client their names, so the user supplies one.
 *
 * A category made here rather than in the Monzo app has no bank handle at all,
 * so its value is built from its name behind a `custom_` prefix.
 *
 * @property int $id
 * @property int $user_id
 * @property string $value
 * @property string $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Transaction> $transactions
 */
#[Fillable([
    'user_id',
    'value',
    'label',
])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** Marks a value this app invented, so the page can say where it came from. */
    public const CUSTOM_PREFIX = 'custom_';

    /**
     * Categories whose money is held out of every total.
     *
     * A transfer moves money between accounts the user already owns, so it is
     * not spending or earning. Counting one would add the same figure to
     * money in and to money out and make a month read as busier than it was.
     * Rows in these categories still appear in the list and still count
     * towards the number of transactions; only their value is excluded.
     *
     * @var array<int, string>
     */
    public const EXCLUDED_FROM_TOTALS = [
        'transfers',
    ];

    /**
     * Monzo's built-in categories and the names it shows them under. Every
     * user starts with these; anything else is one of their own.
     *
     * @var array<string, string>
     */
    public const MONZO_DEFAULTS = [
        'general' => 'General',
        'eating_out' => 'Eating out',
        'expenses' => 'Expenses',
        'transport' => 'Transport',
        'cash' => 'Cash',
        'bills' => 'Bills',
        'entertainment' => 'Entertainment',
        'shopping' => 'Shopping',
        'holidays' => 'Holidays',
        'groceries' => 'Groceries',
        'personal_care' => 'Personal care',
        'family' => 'Family',
        'finances' => 'Finances',
        'savings' => 'Savings',
        'charity' => 'Charity',
        'gifts' => 'Gifts',
        'transfers' => 'Transfers',
        'income' => 'Income',
    ];

    /**
     * Make sure a category exists for a value arriving from a sync, so one
     * the user created in the Monzo app shows up here rather than reading as
     * uncategorised. Its id stands in as the name until the user renames it.
     */
    public static function ensure(int $userId, string $value): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $userId, 'value' => $value],
            ['label' => self::MONZO_DEFAULTS[$value] ?? $value],
        );
    }

    /**
     * Create a category the user asked for here rather than in the Monzo app.
     * No sync will ever send this value, so it is built from the name to stay
     * readable, and prefixed to keep it clear of both Monzo's own values and
     * the `category_` ids Monzo gives the ones made in its app.
     */
    public static function createCustom(int $userId, string $label): self
    {
        $slug = Str::slug($label, '_');

        /** A name with no latin characters slugs to nothing. */
        $base = self::CUSTOM_PREFIX.($slug !== '' ? $slug : Str::lower(Str::random(8)));

        /**
         * Two different names can slug the same way, so step past anything
         * already taken rather than letting the unique index reject the row.
         */
        $value = $base;
        $suffix = 1;

        while (self::query()->where('user_id', $userId)->where('value', $value)->exists()) {
            $suffix++;
            $value = $base.'_'.$suffix;
        }

        return self::query()->create([
            'user_id' => $userId,
            'value' => $value,
            'label' => $label,
        ]);
    }

    /**
     * Seed the built-in set for a user who has none yet.
     */
    public static function seedDefaults(int $userId): void
    {
        foreach (self::MONZO_DEFAULTS as $value => $label) {
            self::ensure($userId, $value);
        }
    }

    /**
     * Value to label for one user, ready for a presenter or a select control.
     *
     * @return array<string, string>
     */
    public static function labelsFor(int $userId): array
    {
        return self::query()
            ->where('user_id', $userId)
            ->orderBy('label')
            ->pluck('label', 'value')
            ->all();
    }

    /**
     * Whether a category's transactions are held out of the money totals.
     */
    public static function isExcludedFromTotals(?string $value): bool
    {
        return $value !== null && in_array($value, self::EXCLUDED_FROM_TOTALS, true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Transactions filed under this category. Joined on the stored value
     * rather than an id, because that value is what the bank sends.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'category', 'value');
    }
}
