<?php

namespace App\Support\Transactions;

use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
            $query->orderByRaw(
                $this->groupExpression().$this->rawDirection($this->groupDirection()),
                $this->groupExpressionBindings(),
            );
        }

        /**
         * The date sort reads the date the row is shown for, so a row that
         * travelled into this month sits where its accounting date puts it
         * rather than at whichever end of the month it was booked in.
         */
        if ($this->filters->sort === 'date') {
            $query->orderByRaw(
                $this->displayDateExpression().$this->rawDirection($this->filters->sortDirection),
                $this->displayDateBindings(),
            );
        } else {
            $query->orderBy(
                TransactionFilters::SORTS[$this->filters->sort],
                $this->filters->sortDirection,
            );
        }

        return $query
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
            ->selectRaw($this->countExpression().' as aggregate_count', $this->countBindings())
            ->selectRaw($this->moneyInExpression().' as money_in', $this->moneyBindings())
            ->selectRaw($this->moneyOutExpression().' as money_out', $this->moneyBindings())
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
        $groupBindings = $this->groupExpressionBindings();

        return $this->base()
            ->toBase()
            ->selectRaw($expression.' as group_key', $groupBindings)
            ->selectRaw($this->countExpression().' as aggregate_count', $this->countBindings())
            ->selectRaw($this->moneyInExpression().' as money_in', $this->moneyBindings())
            ->selectRaw($this->moneyOutExpression().' as money_out', $this->moneyBindings())
            ->groupByRaw($expression, $groupBindings)
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
        $date = $this->displayDateFor($transaction);

        return match ($this->filters->groupBy) {
            'day' => $date->toDateString(),
            'week' => $date->format('o-\WW'),
            'month' => $date->format('Y-m'),
            'category' => $transaction->category ?? '',
            'account' => (string) $transaction->account_id,
            'merchant' => $transaction->merchant_name ?? $transaction->name ?? '',
            default => null,
        };
    }

    /**
     * The date a row is shown for in the month being shown: its booked date
     * when that falls in this month, otherwise its accounting date. The same
     * row therefore reads as one date in the month it landed in and another
     * in the month it counts towards.
     *
     * Mirrors displayDateExpression(); TransactionGroupingTest asserts that
     * the two agree.
     */
    public function displayDateFor(Transaction $transaction): CarbonInterface
    {
        return $transaction->booked_at->isSameMonth($this->filters->monthStart())
            ? $transaction->booked_at
            : ($transaction->accounting_date ?? $transaction->booked_at);
    }

    /**
     * Whether a row is only visiting the month being shown. A ghost was booked
     * here but counts towards another month, so it reads greyed out and adds
     * nothing to the figures; an arrival counts here but was booked elsewhere.
     * Null covers an ordinary row and one moved within its own month, neither
     * of which has anything to explain.
     *
     * @return 'ghost'|'arrival'|null
     */
    public function monthRoleFor(Transaction $transaction): ?string
    {
        if ($transaction->accounting_date === null) {
            return null;
        }

        $month = $this->filters->monthStart();
        $bookedHere = $transaction->booked_at->isSameMonth($month);
        $countsHere = $transaction->accounting_date->isSameMonth($month);

        return match (true) {
            $bookedHere && $countsHere => null,
            $countsHere => 'arrival',
            default => 'ghost',
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

        return [
            'categories' => array_values($categories),
            'types' => array_values(array_map(strval(...), $types)),
            /** Shared with the rules page, so it lives on the model. */
            'tags' => Transaction::tagsFor($this->userId),
        ];
    }

    /**
     * Every row in the month being shown that the user has not marked off yet.
     *
     * Deliberately not built on base(): "mark all as processed" works on the
     * whole month, not on whatever the filter bar has narrowed the table to,
     * so a filter left switched on cannot quietly shrink what it touches.
     *
     * @return Builder<Transaction>
     */
    public function monthUnprocessed(): Builder
    {
        return Transaction::query()
            ->where('transactions.user_id', $this->userId)
            ->whereBetween('booked_at', [$this->filters->monthStart(), $this->filters->monthEnd()])
            ->where('processed', false);
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
            /**
             * One month per page, so the month is always part of the query. A
             * row is on this page if it was booked here or counts here, which
             * is what puts a row with an accounting date in both months. The
             * closure groups the alternative; without it the OR would leak
             * across every filter clause below.
             */
            ->where(fn (Builder $q): Builder => $q
                ->whereBetween('booked_at', [$filters->monthStart(), $filters->monthEnd()])
                ->orWhere(fn (Builder $inner): Builder => $inner
                    ->where('accounting_date', '>=', $filters->monthStart()->toDateString())
                    ->where('accounting_date', '<', $filters->monthAfter()->toDateString())))
            /** Narrowing the dates on screen reads the dates on screen. */
            ->when($filters->dateFrom, fn (Builder $q, $from) => $q->whereRaw(
                $this->displayDateExpression().' >= ?',
                [...$this->displayDateBindings(), $from],
            ))
            ->when($filters->dateTo, fn (Builder $q, $to) => $q->whereRaw(
                $this->displayDateExpression().' <= ?',
                [...$this->displayDateBindings(), $to],
            ))
            ->when($filters->accountIds, fn (Builder $q, array $ids) => $q->whereIn('account_id', $ids))
            ->when($filters->categories, fn (Builder $q, array $categories) => $q->whereIn('category', $categories))
            ->when($filters->types, fn (Builder $q, array $types) => $q->whereIn('type', $types))
            /** The unread-mail filter: only rows the user has yet to mark off. */
            ->when($filters->unprocessed, fn (Builder $q) => $q->where('processed', false))
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
     * An excluded row is otherwise untouched: it stays in the list, keeps its
     * own amount and still counts towards the number of transactions. A row
     * counting towards another month is dropped from the count as well, by
     * countExpression().
     *
     * @return literal-string
     */
    private function countableAmountExpression(): string
    {
        return 'CASE WHEN '.$this->excludedFromTotalsCondition()
            .' OR NOT '.$this->countsTowardsMonthCondition()
            .' THEN 0 ELSE amount_minor END';
    }

    /**
     * Whether a row's money and count belong to the month being shown. base()
     * has already narrowed to the rows this month can show, so a null
     * accounting date can only mean "booked here", and a date that is set
     * only counts if it lands here.
     *
     * The range is half open and compared against bare dates on purpose. The
     * date cast writes 'Y-m-d H:i:s' into this column while raw SQL can leave
     * a bare 'Y-m-d', and SQLite compares both as text: a closed range with
     * date bounds drops a timestamped last day of the month, and one with
     * datetime bounds drops a bare first day. The IS NULL arm also keeps the
     * condition from ever being NULL, so NOT can be taken of it safely.
     *
     * @return literal-string
     */
    private function countsTowardsMonthCondition(): string
    {
        return '(accounting_date IS NULL OR (accounting_date >= ? AND accounting_date < ?))';
    }

    /**
     * The month bounds countsTowardsMonthCondition() reads, in placeholder
     * order. Every raw clause built on that condition passes these, repeated
     * once per appearance of it.
     *
     * @return array<int, string>
     */
    private function countsTowardsMonthBindings(): array
    {
        return [
            $this->filters->monthStart()->toDateString(),
            $this->filters->monthAfter()->toDateString(),
        ];
    }

    /**
     * The date a row is shown for, as SQL. Mirrored in PHP by displayDateFor().
     *
     * datetime() normalises the accounting date to the shape booked_at is
     * stored in, so ordering never depends on which of the two formats
     * happens to be in the column.
     *
     * @return literal-string
     */
    private function displayDateExpression(): string
    {
        return 'CASE WHEN booked_at BETWEEN ? AND ? THEN booked_at ELSE datetime(accounting_date) END';
    }

    /**
     * The month bounds displayDateExpression() reads, in placeholder order.
     *
     * @return array<int, string>
     */
    private function displayDateBindings(): array
    {
        return [
            $this->filters->monthStart()->toDateTimeString(),
            $this->filters->monthEnd()->toDateTimeString(),
        ];
    }

    /**
     * A row counting towards another month is on screen but is no part of this
     * month's figures, so the count is a conditional sum rather than a
     * COUNT(*). This is the one way it differs from an excluded category,
     * which is genuinely one of the month's transactions.
     *
     * @return literal-string
     */
    private function countExpression(): string
    {
        return 'COALESCE(SUM(CASE WHEN '.$this->countsTowardsMonthCondition().' THEN 1 ELSE 0 END), 0)';
    }

    /**
     * @return array<int, string>
     */
    private function countBindings(): array
    {
        return $this->countsTowardsMonthBindings();
    }

    /**
     * A money sum reads countableAmountExpression() twice, once to test the
     * sign and once to add it up, so the month bounds are bound twice over.
     *
     * @return array<int, string>
     */
    private function moneyBindings(): array
    {
        return [
            ...$this->countsTowardsMonthBindings(),
            ...$this->countsTowardsMonthBindings(),
        ];
    }

    /**
     * Only the date groupings read the display date; the rest are plain
     * columns with nothing to bind.
     *
     * @return array<int, string>
     */
    private function groupExpressionBindings(): array
    {
        return in_array($this->filters->groupBy, ['day', 'week', 'month'], true)
            ? $this->displayDateBindings()
            : [];
    }

    /**
     * A sort direction as SQL. Narrowed to one of two literals rather than
     * concatenated, so the clause it builds stays a literal string.
     *
     * @return literal-string
     */
    private function rawDirection(string $direction): string
    {
        return $direction === 'asc' ? ' asc' : ' desc';
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
     * user input, and the only values interpolated into them are month bounds
     * formatted by Carbon, so nothing reaches the database unvalidated.
     *
     * @return literal-string
     */
    private function groupExpression(): string
    {
        $date = $this->displayDateExpression();

        return match ($this->filters->groupBy) {
            'day' => "strftime('%Y-%m-%d', ".$date.')',
            'week' => "strftime('%G-W%V', ".$date.')',
            'month' => "strftime('%Y-%m', ".$date.')',
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
