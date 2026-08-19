<?php

namespace App\Support\Transactions;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * The state of the transactions screen, parsed out of the query string. Every
 * control on that screen is represented here, which is what lets a view be
 * bookmarked and shared as a URL.
 */
readonly class TransactionFilters
{
    public const DATE_PRESETS = [
        'this_month',
        'last_month',
        'last_3_months',
        'this_year',
        'last_year',
        'custom',
        'all',
    ];

    /**
     * Sortable columns, mapped to the expression they sort by. Anything not
     * listed here is rejected rather than passed to the database.
     */
    public const SORTS = [
        'date' => 'booked_at',
        'amount' => 'amount_minor',
        'name' => 'name',
        'category' => 'category',
        'account' => 'account_id',
        'added' => 'created_at',
    ];

    public const GROUPS = ['none', 'day', 'week', 'month', 'category', 'account', 'merchant'];

    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, string>  $categories
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $types
     * @param  'all'|'in'|'out'  $direction
     * @param  key-of<self::SORTS>  $sort
     * @param  'asc'|'desc'  $sortDirection
     * @param  value-of<self::GROUPS>  $groupBy
     */
    public function __construct(
        /**
         * The month being shown, as its first day. The table shows one month
         * at a time, so this stands in for a page number.
         */
        public Carbon $month = new Carbon,
        public ?Carbon $dateFrom = null,
        public ?Carbon $dateTo = null,
        public string $datePreset = 'all',
        public array $accountIds = [],
        public array $categories = [],
        public string $direction = 'all',
        public ?int $amountMinMinor = null,
        public ?int $amountMaxMinor = null,
        public ?string $search = null,
        public array $tags = [],
        public array $types = [],
        public string $sort = 'date',
        public string $sortDirection = 'desc',
        public string $groupBy = 'none',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $preset = self::oneOf($input['date_preset'] ?? null, self::DATE_PRESETS, 'all');
        [$from, $to] = self::resolveRange($preset, $input);

        return new self(
            month: self::monthOrCurrent($input['month'] ?? null),
            dateFrom: $from,
            dateTo: $to,
            datePreset: $preset,
            accountIds: array_values(array_map(intval(...), self::arrayOf($input['accounts'] ?? null))),
            categories: self::arrayOf($input['categories'] ?? null),
            direction: self::oneOf($input['direction'] ?? null, ['all', 'in', 'out'], 'all'),
            amountMinMinor: self::toMinor($input['amount_min'] ?? null),
            amountMaxMinor: self::toMinor($input['amount_max'] ?? null),
            search: self::stringOrNull($input['search'] ?? null),
            tags: self::arrayOf($input['tags'] ?? null),
            types: self::arrayOf($input['types'] ?? null),
            sort: self::oneOf($input['sort'] ?? null, array_keys(self::SORTS), 'date'),
            sortDirection: self::oneOf($input['sort_direction'] ?? null, ['asc', 'desc'], 'desc'),
            groupBy: self::oneOf($input['group_by'] ?? null, self::GROUPS, 'none'),
        );
    }

    /**
     * Only a custom range carries explicit dates; a named preset recomputes
     * its own bounds, so echoing them back would freeze the view in time.
     */
    public function isCustomRange(): bool
    {
        return in_array($this->datePreset, ['custom', 'all'], true);
    }

    public function isGrouped(): bool
    {
        return $this->groupBy !== 'none';
    }

    /**
     * The first and last moment of the month being shown. Both derive from
     * the stored value rather than trusting it to already be a month start,
     * so a filters object built by hand cannot show half a month.
     */
    public function monthStart(): Carbon
    {
        return $this->month->copy()->startOfMonth();
    }

    public function monthEnd(): Carbon
    {
        return $this->month->copy()->endOfMonth();
    }

    public function isCurrentMonth(): bool
    {
        return $this->month->isSameMonth(Carbon::now());
    }

    /**
     * There is nothing after the current month, so the forward arrow stops
     * there rather than walking into months that cannot hold anything.
     */
    public function nextMonth(): ?Carbon
    {
        return $this->isCurrentMonth()
            ? null
            : $this->monthStart()->addMonthNoOverflow();
    }

    public function previousMonth(): Carbon
    {
        return $this->monthStart()->subMonthNoOverflow();
    }

    /**
     * Reads a "2026-08" from the query string. Anything unparseable falls
     * back to the current month rather than showing an empty table.
     */
    private static function monthOrCurrent(mixed $value): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            try {
                return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
            } catch (Throwable) {
                return Carbon::now()->startOfMonth();
            }
        }

        return Carbon::now()->startOfMonth();
    }

    /**
     * The query string that reproduces this view, with defaults omitted so the
     * URL stays readable.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'date_preset' => $this->datePreset === 'all' ? null : $this->datePreset,
            'date_from' => $this->dateFrom !== null && $this->isCustomRange() ? $this->dateFrom->toDateString() : null,
            'date_to' => $this->dateTo !== null && $this->isCustomRange() ? $this->dateTo->toDateString() : null,
            'accounts' => $this->accountIds ?: null,
            'categories' => $this->categories ?: null,
            'direction' => $this->direction === 'all' ? null : $this->direction,
            'search' => $this->search,
            'tags' => $this->tags ?: null,
            'types' => $this->types ?: null,
            'sort' => $this->sort === 'date' ? null : $this->sort,
            'sort_direction' => $this->sortDirection === 'desc' ? null : $this->sortDirection,
            'group_by' => $this->groupBy === 'none' ? null : $this->groupBy,
            'month' => $this->isCurrentMonth() ? null : $this->monthStart()->format('Y-m'),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private static function resolveRange(string $preset, array $input): array
    {
        $now = Carbon::now();

        return match ($preset) {
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'last_3_months' => [$now->copy()->subMonthsNoOverflow(3)->startOfDay(), $now->copy()->endOfDay()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'last_year' => [
                $now->copy()->subYear()->startOfYear(),
                $now->copy()->subYear()->endOfYear(),
            ],
            default => [
                self::dateOrNull($input['date_from'] ?? null)?->startOfDay(),
                self::dateOrNull($input['date_to'] ?? null)?->endOfDay(),
            ],
        };
    }

    /**
     * Amounts are entered in pounds but stored in pence.
     */
    private static function toMinor(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value * 100);
    }

    /**
     * Narrow arbitrary input down to one of a known set, so the value carries
     * its allowed options into the type system rather than staying a bare
     * string all the way to the query builder.
     *
     * @template TAllowed of string
     *
     * @param  array<int, TAllowed>  $allowed
     * @param  TAllowed  $fallback
     * @return TAllowed
     */
    private static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @return array<int, string>
     */
    private static function arrayOf(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => trim((string) $item), $value),
            fn (string $item): bool => $item !== '',
        ));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private static function dateOrNull(mixed $value): ?Carbon
    {
        $string = self::stringOrNull($value);

        if ($string === null) {
            return null;
        }

        try {
            return Carbon::parse($string);
        } catch (Throwable) {
            return null;
        }
    }
}
