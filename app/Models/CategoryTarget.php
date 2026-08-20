<?php

namespace App\Models;

use Database\Factories\CategoryTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How much the user means to spend in one category in one month.
 *
 * A row existing is the whole of "there is a target here". No row means the
 * category has not been budgeted that month; a row holding 0 means the user
 * deliberately means to spend nothing. Those read differently the moment a
 * figure is drawn against them, so the column is not nullable and clearing a
 * field deletes the row rather than storing a zero.
 *
 * The amount is positive. A transaction's amount_minor is signed because a
 * transaction has a direction; a target is a magnitude, and the figure it is
 * measured against — TransactionQuery's money out — is already positive.
 *
 * @property int $id
 * @property int $user_id
 * @property string $month
 * @property string $category
 * @property int $amount_minor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'month',
    'category',
    'amount_minor',
])]
class CategoryTarget extends Model
{
    /** @use HasFactory<CategoryTargetFactory> */
    use HasFactory;

    /**
     * The targets set for one month, category value to pence.
     *
     * @return array<string, int>
     */
    public static function forMonth(int $userId, string $month): array
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('month', $month)
            ->pluck('amount_minor', 'category')
            /** Not intval(...): map passes the key as its second argument. */
            ->map(fn (mixed $minor): int => (int) $minor)
            ->all();
    }

    /**
     * What the targets form should open with for a month.
     *
     * A month that has any target of its own shows exactly those, so clearing
     * one category on purpose does not bring it back next time. A month with
     * none is filled in from the most recent earlier month that has some,
     * which is a suggestion only — nothing is stored until the form is saved.
     *
     * @return array{values: array<string, int>, copiedFrom: string|null}
     */
    public static function prefillFor(int $userId, string $month): array
    {
        $own = self::forMonth($userId, $month);

        if ($own !== []) {
            return ['values' => $own, 'copiedFrom' => null];
        }

        $earlier = self::latestMonthBefore($userId, $month);

        if ($earlier === null) {
            return ['values' => [], 'copiedFrom' => null];
        }

        return ['values' => self::forMonth($userId, $earlier), 'copiedFrom' => $earlier];
    }

    /**
     * The most recent month before this one that has any target.
     *
     * 'YYYY-MM' is fixed width and zero padded, so comparing it as text puts
     * the months in the order they happened.
     */
    public static function latestMonthBefore(int $userId, string $month): ?string
    {
        $value = self::query()
            ->where('user_id', $userId)
            ->where('month', '<', $month)
            ->max('month');

        return $value === null ? null : (string) $value;
    }

    /**
     * One month's targets added up, for the figure shown beside the month's
     * money out.
     *
     * Categories held out of the totals are skipped. Their spending is zeroed
     * by TransactionQuery, so counting their target here would make every
     * month read as over. Null when nothing counted is set, which is what
     * hides the figure rather than showing a zero target.
     *
     * @param  array<string, int>  $saved
     */
    public static function totalOf(array $saved): ?int
    {
        $counted = array_diff_key($saved, array_flip(Category::EXCLUDED_FROM_TOTALS));

        return $counted === [] ? null : array_sum($counted);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Deliberately no category() relation. categories.value is unique per
     * user, not globally, so a belongsTo on it could resolve to another
     * user's row.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }
}
