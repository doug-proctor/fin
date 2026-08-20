<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $amex_sync_report_id
 * @property string|null $external_id
 * @property string $dedupe_hash
 * @property Carbon $booked_at
 * @property Carbon|null $accounting_date
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $name
 * @property string|null $description
 * @property string|null $category
 * @property string|null $type
 * @property string|null $merchant_name
 * @property string|null $notes
 * @property array<int, string>|null $tags
 * @property array<string, bool>|null $overrides
 * @property string|null $categorised_by
 * @property int|null $category_rule_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $money_in_minor
 * @property-read int $money_out_minor
 * @property-read Category|null $categoryModel
 * @property-read User $user
 * @property-read Account $account
 * @property-read CategoryRule|null $categoryRule
 * @property-read AmexSyncReport|null $amexSyncReport
 */
#[Fillable([
    'user_id',
    'account_id',
    'amex_sync_report_id',
    'external_id',
    'dedupe_hash',
    'booked_at',
    'accounting_date',
    'amount_minor',
    'currency',
    'name',
    'description',
    'category',
    'type',
    'merchant_name',
    'notes',
    'tags',
    'overrides',
    'categorised_by',
    'category_rule_id',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * Fields a sync or import is allowed to refresh from the provider, and so
     * the only fields an override can protect. Anything outside this list is
     * either local state or identity and is never touched by an import.
     *
     * @var array<int, string>
     */
    public const BANK_FIELDS = [
        'booked_at',
        'amount_minor',
        'currency',
        'name',
        'description',
        'category',
        'type',
        'merchant_name',
        'notes',
        'tags',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booked_at' => 'datetime',
            'accounting_date' => 'date',
            'tags' => 'array',
            'overrides' => 'array',
            'amount_minor' => 'integer',
        ];
    }

    /**
     * The category record holding this row's display label, matched on the
     * value the bank sent rather than an id.
     *
     * @return BelongsTo<Category, $this>
     */
    public function categoryModel(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category', 'value');
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
     * @return BelongsTo<CategoryRule, $this>
     */
    public function categoryRule(): BelongsTo
    {
        return $this->belongsTo(CategoryRule::class);
    }

    /**
     * @return BelongsTo<AmexSyncReport, $this>
     */
    public function amexSyncReport(): BelongsTo
    {
        return $this->belongsTo(AmexSyncReport::class);
    }

    /**
     * Money in and money out are presentation only. Storing them as columns
     * would let them disagree with each other; deriving them from one signed
     * integer cannot.
     *
     * @return Attribute<int<0, max>, never>
     */
    protected function moneyInMinor(): Attribute
    {
        return Attribute::get(fn (): int => max($this->amount_minor, 0));
    }

    /**
     * @return Attribute<int<0, max>, never>
     */
    protected function moneyOutMinor(): Attribute
    {
        return Attribute::get(fn (): int => max(-$this->amount_minor, 0));
    }

    /**
     * Whether the user has hand edited this field, in which case no sync or
     * re-import may write over it.
     */
    public function isOverridden(string $field): bool
    {
        return (bool) ($this->overrides[$field] ?? false);
    }

    /**
     * Record that a field now holds a hand edited value.
     *
     * @param  array<int, string>  $fields
     */
    public function markOverridden(array $fields): void
    {
        $overrides = $this->overrides ?? [];

        foreach ($fields as $field) {
            $overrides[$field] = true;
        }

        $this->overrides = $overrides;
    }

    /**
     * Strip out every field the user has taken ownership of, leaving only the
     * attributes an import may safely write.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function withoutOverridden(array $attributes): array
    {
        return array_diff_key($attributes, $this->overrides ?? []);
    }
}
