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

The rows are untouched: still listed, still in `count`, still showing their own amount. Only the value is dropped, so the transfers group reads count N with £0 of money. The table greys and strikes those amounts and the summary states the exclusion, because the numbers otherwise look wrong.

TransactionFactory's random category deliberately excludes this list — a factory row landing on 'transfers' would silently drop out of any money assertion. Use the `transfer()` state when a test wants one.
