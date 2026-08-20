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
 * Categorisation is entirely this app's own: no bank's categories are read.
 * The value is the stable handle `transactions.category` stores, and the
 * label is only for display, so a category can be renamed without touching
 * a single transaction.
 *
 * Every category is one the user owns. A new account starts with the set
 * below to save filling it in from nothing, and one the user adds gets a
 * value built from its name.
 *
 * php artisan transactions:clear-categories
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
     * The categories a new account starts with, and the names they are shown
     * under. This is the set the user actually files things under; anything
     * else is one they added afterwards.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'bills' => 'Bills',
        'dating' => 'Dating',
        'eating_out' => 'Eating out',
        'groceries' => 'Groceries',
        'holiday' => 'Holidays',
        'james' => 'James',
        'mum' => 'Mum',
        'personal_care' => 'Personal care',
        'social' => 'Social',
        'subscriptions' => 'Subscriptions',
        'transfers' => 'Transfers',
        'transport' => 'Transport',
        'trips' => 'Trips',
    ];

    /**
     * Make sure a category exists for a value, so a transaction already
     * filed under it has something to display. The value stands in as the
     * name for anything not in the starting set.
     */
    public static function ensure(int $userId, string $value): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $userId, 'value' => $value],
            ['label' => self::DEFAULTS[$value] ?? $value],
        );
    }

    /**
     * Create a category the user asked for. Its value is built from the name
     * so it stays readable.
     */
    public static function createCustom(int $userId, string $label): self
    {
        $slug = Str::slug($label, '_');

        /** A name with no latin characters slugs to nothing. */
        $base = $slug !== '' ? $slug : Str::lower(Str::random(8));

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
     * Seed the starting set for a user who has none yet.
     */
    public static function seedDefaults(int $userId): void
    {
        foreach (self::DEFAULTS as $value => $label) {
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
     * rather than an id, so renaming a category leaves every row alone.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'category', 'value');
    }
}
