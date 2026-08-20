---
paths:
  - 'app/Support/Transactions/**'
---

# Transactions

## SQLite hides a stale column in a relation select
Eager-load column lists are strings, so nothing checks them when a migration drops a column. On SQLite an unresolvable double-quoted identifier is treated as a string literal, not an error, so `with('account:id,name,provider,type')` after `accounts.type` was dropped kept working and quietly set the attribute `"type"` to the string `type`. MySQL and Postgres would have thrown.

When dropping a column, grep for its name in `with(` / `select(` strings as well as in code. Larastan and the test suite will not catch it.

## Transfers are counted but their money is excluded from every total
A transfer moves money between accounts the user already owns, so counting it adds the same figure to money in and money out. `Category::EXCLUDED_FROM_TOTALS` lists the categories held out; `Category::isExcludedFromTotals()` is the PHP-side check.

Every money sum in TransactionQuery goes through `countableAmountExpression()` rather than reading `amount_minor` directly, so summary() and groupSubtotals() agree and the subtotals still add up to the month. Any new aggregate must do the same.

The rows are untouched: still listed, still in `count`, still showing their own amount. Only the value is dropped, so the transfers group reads count N with £0 of money. The table fades those amounts out; that is the whole of the signal, and it is deliberately not explained in on-screen copy.

TransactionFactory's random category deliberately excludes this list — a factory row landing on 'transfers' would silently drop out of any money assertion. Use the `transfer()` state when a test wants one.

## A row with an accounting_date shows in two months and counts in one
`transactions.accounting_date` is the month a charge belongs to when the bank booked it in another — a May meal settled up with a friend in June. Null means the booked date is already right.

`base()` shows a row whose booked date OR accounting date falls in the month, so the same row appears twice on screen and once in the figures. `countsTowardsMonthCondition()` is what splits the two: it feeds `countableAmountExpression()` (money) and `countExpression()` (count), so a row counting elsewhere adds nothing to either. `TransactionQuery::monthRoleFor()` tells the table which of the two a row is — 'ghost' greys out, 'arrival' gets an alien.

Compare `accounting_date` ONLY as a half-open range against bare dates: `>= monthStart AND < monthAfter`. The date cast writes 'Y-m-d H:i:s' into the column while raw SQL can leave a bare 'Y-m-d', and SQLite compares both as text — a closed range with date bounds drops the timestamped last day of the month, one with datetime bounds drops a bare first day. `TransactionFilters::monthAfter()` is that bound; `nextMonth()` is the navigation arrow and returns null on the current month.

Date sorting and day/week/month grouping read `displayDateExpression()`, not `booked_at`, or an arrival would head a group belonging to the month it left. `groupKeyFor()`/`displayDateFor()` mirror it in PHP and TransactionGroupingTest asserts they agree.

## Raw SQL fragments carry placeholders and their own bindings
Every raw fragment in TransactionQuery is a `literal-string` with `?` placeholders; the month bounds arrive as bindings passed at each call site. Larastan enforces this — interpolating a Carbon date into the string turns it into a plain `string` and every `selectRaw`/`orderByRaw`/`groupByRaw`/`whereRaw` using it fails.

The bindings must match how many times the fragment appears in the clause. A money sum reads `countableAmountExpression()` twice (once to test the sign, once to add it up), which is why `moneyBindings()` repeats `countsTowardsMonthBindings()`; `countBindings()` does not. `groupExpressionBindings()` is empty unless the grouping is by day/week/month.

`orderBy(DB::raw(...))` cannot carry bindings — use `orderByRaw` with `rawDirection()`, which narrows the direction to one of two literals so the clause stays a literal string.
