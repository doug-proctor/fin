<?php

namespace App\Support\Transactions;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns the transactions screen's controls into SQL.
 *
 * Filtering, sorting, grouping and totalling all happen in the database, so
 * the browser only ever receives one page of rows no matter how many years of
 * history are stored.
 */
class TransactionQuery
{
    public function __construct(
        private readonly int $userId,
        private readonly TransactionFilters $filters,
    ) {}

    /**
     * The rows for the month being shown, ordered by the group expression
     * first when grouping is on so the list can be walked in order and
     * headers inserted.
     *
     * There is no row limit because the month is the limit.
     *
     * @return Collection<int, Transaction>
     */
    public function rows(): Collection
    {
        $query = $this->base()->with('account:id,name,provider');

        if ($this->filters->isGrouped()) {
            $query->orderBy(DB::raw($this->groupExpression()), $this->groupDirection());
        }

        $column = TransactionFilters::SORTS[$this->filters->sort];

        return $query
            ->orderBy($column, $this->filters->sortDirection)
            /** A tiebreak keeps the order stable when a sort column ties. */
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Totals for the whole month being shown.
     *
     * @return array{count: int, moneyIn: int, moneyOut: int, net: int}
     */
    public function summary(): array
    {
        /**
         * An aggregate row is not a transaction, so this drops to the base
         * query rather than hydrating a model out of the totals.
         *
         * @var object{aggregate_count: int, money_in: int|null, money_out: int|null}|null $row
         */
        $row = $this->base()
            ->toBase()
            ->selectRaw('COUNT(*) as aggregate_count')
            ->selectRaw($this->moneyInExpression().' as money_in')
            ->selectRaw($this->moneyOutExpression().' as money_out')
            ->reorder()
            ->first();

        $moneyIn = (int) ($row->money_in ?? 0);
        $moneyOut = (int) ($row->money_out ?? 0);

        return [
            'count' => (int) ($row->aggregate_count ?? 0),
            'moneyIn' => $moneyIn,
            'moneyOut' => $moneyOut,
            'net' => $moneyIn - $moneyOut,
        ];
    }

    /**
     * Whole-group totals for every group in the filtered set.
     *
     * Computed over the whole month rather than over what is on screen, so a
     * group shows its true total.
     *
     * @return array<string, array{count: int, moneyIn: int, moneyOut: int, net: int}>
     */
    public function groupSubtotals(): array
    {
        if (! $this->filters->isGrouped()) {
            return [];
        }

        $expression = $this->groupExpression();

        return $this->base()
            ->toBase()
            ->selectRaw($expression.' as group_key')
            ->selectRaw('COUNT(*) as aggregate_count')
            ->selectRaw($this->moneyInExpression().' as money_in')
            ->selectRaw($this->moneyOutExpression().' as money_out')
            ->groupBy(DB::raw($expression))
            ->reorder()
            ->get()
            ->mapWithKeys(function (object $row): array {
                $moneyIn = (int) ($row->money_in ?? 0);
                $moneyOut = (int) ($row->money_out ?? 0);

                return [(string) ($row->group_key ?? '') => [
                    'count' => (int) $row->aggregate_count,
                    'moneyIn' => $moneyIn,
                    'moneyOut' => $moneyOut,
                    'net' => $moneyIn - $moneyOut,
                ]];
            })
            ->all();
    }

    /**
     * The group key for a row, computed the same way the database computes it
     * so the frontend can match a row to its subtotal.
     */
    public function groupKeyFor(Transaction $transaction): ?string
    {
        return match ($this->filters->groupBy) {
            'day' => $transaction->booked_at->toDateString(),
            'week' => $transaction->booked_at->format('o-\WW'),
            'month' => $transaction->booked_at->format('Y-m'),
            'category' => $transaction->category ?? '',
            'account' => (string) $transaction->account_id,
            'merchant' => $transaction->merchant_name ?? $transaction->name ?? '',
            default => null,
        };
    }

    /**
     * Distinct values actually present in this user's data, used to populate
     * the filter controls so they never offer an empty result.
     *
     * @return array{categories: array<int, string>, types: array<int, string>, tags: array<int, string>}
     */
    public function facets(): array
    {
        $categories = Transaction::query()
            ->where('user_id', $this->userId)
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->map(strval(...))
            ->all();

        $types = Transaction::query()
            ->where('user_id', $this->userId)
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();

        /**
         * Tags are a json array, so the distinct set is assembled in PHP over
         * the rows that actually carry tags rather than in SQL.
         */
        $tags = Transaction::query()
            ->where('user_id', $this->userId)
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->filter(fn (mixed $tag): bool => is_string($tag) && $tag !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'categories' => array_values($categories),
            'types' => array_values(array_map(strval(...), $types)),
            'tags' => array_values(array_map(strval(...), $tags)),
        ];
    }

    /**
     * The filtered query, shared by the row, summary and subtotal passes.
     *
     * @return Builder<Transaction>
     */
    private function base(): Builder
    {
        $filters = $this->filters;

        return Transaction::query()
            ->where('transactions.user_id', $this->userId)
            /** One month per page, so the month is always part of the query. */
            ->whereBetween('booked_at', [$filters->monthStart(), $filters->monthEnd()])
            ->when($filters->dateFrom, fn (Builder $q, $from) => $q->where('booked_at', '>=', $from))
            ->when($filters->dateTo, fn (Builder $q, $to) => $q->where('booked_at', '<=', $to))
            ->when($filters->accountIds, fn (Builder $q, array $ids) => $q->whereIn('account_id', $ids))
            ->when($filters->categories, fn (Builder $q, array $categories) => $q->whereIn('category', $categories))
            ->when($filters->types, fn (Builder $q, array $types) => $q->whereIn('type', $types))
            ->when(
                $filters->direction === 'in',
                fn (Builder $q) => $q->where('amount_minor', '>', 0),
            )
            ->when(
                $filters->direction === 'out',
                fn (Builder $q) => $q->where('amount_minor', '<', 0),
            )
            /**
             * Amount bounds are compared on the absolute value, so "between
             * £10 and £50" means the same thing for money in and money out.
             */
            ->when(
                $filters->amountMinMinor !== null,
                fn (Builder $q) => $q->whereRaw('ABS(amount_minor) >= ?', [$filters->amountMinMinor]),
            )
            ->when(
                $filters->amountMaxMinor !== null,
                fn (Builder $q) => $q->whereRaw('ABS(amount_minor) <= ?', [$filters->amountMaxMinor]),
            )
            ->when($filters->search, fn (Builder $q, string $search) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('merchant_name', 'like', '%'.$search.'%')
                    ->orWhere('notes', 'like', '%'.$search.'%'),
            ))
            ->when($filters->tags, fn (Builder $q, array $tags) => $q->where(
                function (Builder $inner) use ($tags): void {
                    foreach ($tags as $tag) {
                        $inner->orWhereJsonContains('tags', $tag);
                    }
                },
            ));
    }

    /**
     * Every money sum reads this rather than `amount_minor` directly, so a
     * category held out of the totals contributes nothing to money in, money
     * out or net wherever the figures are added up. See
     * Category::EXCLUDED_FROM_TOTALS for why.
     *
     * The rows themselves are untouched: they stay in the list, keep their own
     * amount and still count towards the number of transactions.
     *
     * @return literal-string
     */
    private function countableAmountExpression(): string
    {
        return 'CASE WHEN '.$this->excludedFromTotalsCondition().' THEN 0 ELSE amount_minor END';
    }

    /**
     * The excluded categories as a SQL condition. Built from the constant so
     * adding a value there is enough; the values are literals from the code,
     * never user input.
     *
     * @return literal-string
     */
    private function excludedFromTotalsCondition(): string
    {
        $values = '';

        foreach (Category::EXCLUDED_FROM_TOTALS as $value) {
            $values .= ($values === '' ? '' : ', ')."'".$value."'";
        }

        return 'category IN ('.$values.')';
    }

    /**
     * @return literal-string
     */
    private function moneyInExpression(): string
    {
        $amount = $this->countableAmountExpression();

        return 'COALESCE(SUM(CASE WHEN '.$amount.' > 0 THEN '.$amount.' ELSE 0 END), 0)';
    }

    /**
     * @return literal-string
     */
    private function moneyOutExpression(): string
    {
        $amount = $this->countableAmountExpression();

        return 'COALESCE(SUM(CASE WHEN '.$amount.' < 0 THEN -('.$amount.') ELSE 0 END), 0)';
    }

    /**
     * The grouping is built from a fixed set of expressions rather than from
     * user input, so nothing reaches the database unvalidated.
     *
     * @return literal-string
     */
    private function groupExpression(): string
    {
        return match ($this->filters->groupBy) {
            'day' => "strftime('%Y-%m-%d', booked_at)",
            'week' => "strftime('%G-W%V', booked_at)",
            'month' => "strftime('%Y-%m', booked_at)",
            'category' => "COALESCE(category, '')",
            'account' => 'CAST(account_id AS TEXT)',
            'merchant' => "COALESCE(merchant_name, name, '')",
            default => "''",
        };
    }

    /**
     * Date groups read newest first alongside the default date sort; the
     * others read alphabetically.
     *
     * @return 'asc'|'desc'
     */
    private function groupDirection(): string
    {
        return in_array($this->filters->groupBy, ['day', 'week', 'month'], true)
            ? $this->filters->sortDirection
            : 'asc';
    }
}
