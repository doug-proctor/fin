---
paths:
  - 'app/Support/Transactions/**'
  - app/Support/Transactions/TransactionEdit.php
---

# Transactions

## SQLite hides a stale column in a relation select
Eager-load column lists are strings, so nothing checks them when a migration drops a column. On SQLite an unresolvable double-quoted identifier is treated as a string literal, not an error, so `with('account:id,name,provider,type')` after `accounts.type` was dropped kept working and quietly set the attribute `"type"` to the string `type`. MySQL and Postgres would have thrown.

When dropping a column, grep for its name in `with(` / `select(` strings as well as in code. Larastan and the test suite will not catch it.

## Rows in 'ignore' are counted but their money is excluded from every total
A transfer moves money between accounts the user already owns, so counting it adds the same figure to money in and money out. Those rows are filed under the `ignore` category. `Category::EXCLUDED_FROM_TOTALS` lists the categories held out — `['ignore']` — and `Category::isExcludedFromTotals()` is the PHP-side check. The category was called `transfers` until 2026-08-20.

Every money sum in TransactionQuery goes through `countableAmountExpression()` rather than reading `amount_minor` directly, so summary() and groupSubtotals() agree and the subtotals still add up to the month. Any new aggregate must do the same.

The rows are untouched: still listed, still in `count`, still showing their own amount. Only the value is dropped, so the ignore group reads count N with £0 of money. The table fades those amounts out; that is the whole of the signal, and it is deliberately not explained in on-screen copy.

TransactionFactory's random category deliberately excludes this list — a factory row that came out as 'ignore' would silently drop out of any money assertion. Use the `transfer()` state when a test wants one; it files the row under `ignore`.

## A row with an accounting_date shows in two months and counts in one
`transactions.accounting_date` is the month a charge belongs to when the bank booked it in another — a May meal settled up with a friend in June. Null means the booked date is already right.

`base()` shows a row whose booked date OR accounting date falls in the month, so the same row appears twice on screen and once in the figures. `countsTowardsMonthCondition()` is what splits the two: it feeds `countableAmountExpression()` (money) and `countExpression()` (count), so a row counting elsewhere adds nothing to either. `TransactionQuery::monthRoleFor()` tells the table which of the two a row is — 'ghost' greys out, 'arrival' gets an alien.

Compare `accounting_date` ONLY as a half-open range against bare dates: `>= monthStart AND < monthAfter`. The date cast writes 'Y-m-d H:i:s' into the column while raw SQL can leave a bare 'Y-m-d', and SQLite compares both as text — a closed range with date bounds drops the timestamped last day of the month, one with datetime bounds drops a bare first day. `TransactionFilters::monthAfter()` is that bound; `nextMonth($lastMonth)` is the navigation arrow and takes its stop from `TransactionQuery::lastMonth()`.

Date sorting and day/week/month grouping read `displayDateExpression()`, not `booked_at`, or an arrival would head a group belonging to the month it left. `groupKeyFor()`/`displayDateFor()` mirror it in PHP and TransactionGroupingTest asserts they agree.

## Raw SQL fragments carry placeholders and their own bindings
Every raw fragment in TransactionQuery is a `literal-string` with `?` placeholders; the month bounds arrive as bindings passed at each call site. Larastan enforces this — interpolating a Carbon date into the string turns it into a plain `string` and every `selectRaw`/`orderByRaw`/`groupByRaw`/`whereRaw` using it fails.

The bindings must match how many times the fragment appears in the clause. A money sum reads `countableAmountExpression()` twice (once to test the sign, once to add it up), which is why `moneyBindings()` repeats `countsTowardsMonthBindings()`; `countBindings()` does not. `groupExpressionBindings()` is empty unless the grouping is by day/week/month.

`orderBy(DB::raw(...))` cannot carry bindings — use `orderByRaw` with `rawDirection()`, which narrows the direction to one of two literals so the clause stays a literal string.

## Hand edits survive a wipe in a json file, not a table
`migrate:fresh` destroys every hand edit, so `transactions:export-edits` writes them to storage/app/transaction-edits.json and `transactions:import-edits` puts them back. A file, because a table goes with the wipe.

Identity is email + account provider + `dedupe_hash`, never an id — ids are reassigned by the wipe. Monzo hashes are sha1('monzo:'+transaction id) and never move. AMEX hashes use the export's `Reference` when there is one; the fallback signature in ImportAmexCsv::dedupeHash() still includes `$account->id`, so a referenceless AMEX row cannot be matched after a wipe. Swap it for `$account->provider` if that ever bites.

Run order: export, `migrate:fresh --seed`, re-authorise Monzo, sync, upload the AMEX CSV, then import-edits LAST — the rules file rows on the way in and these edits outrank them.

TransactionEdit::fromTransaction() narrows the overrides map to `Transaction::BANK_FIELDS` on purpose. Rows edited before category stopped being a bank field still carry `overrides['category']`, and ApplyCategoryRules:102 reads that key to decide whether it may file a row — replaying it would block every rule against that row for good, silently. `categorised_by = 'user'` is what protects a hand-chosen category now.

A category set by a rule is not exported: the rules run again on import and file it again.

## An accounting_date may run into the future, and the arrow follows it
`accounting_date` has no upper bound — a flight booked in July for a holiday in August belongs to August. `TransactionUpdateRequest` validates it as `['sometimes', 'nullable', 'date']` and nothing more.

The forward month arrow is what keeps that reachable. `TransactionQuery::lastMonth()` returns the later of the current month and `MAX(substr(accounting_date, 1, 7))` for that user; `TransactionFilters::nextMonth($lastMonth)` returns null at or past it. Read the column as char(7) with `substr`, never as a date — the cast writes 'Y-m-d H:i:s' and raw SQL writes bare 'Y-m-d'.

Anything that hard-stops the arrow at the current month again puts money in a month the user cannot open.
