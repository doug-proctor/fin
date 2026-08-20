---
paths:
  - app/Models/Transaction.php
  - app/Models/Category.php
  - app/Models/CategoryTarget.php
---

# Models

## Money is signed minor units; hand edits are protected by the overrides map
`amount_minor` is a single signed integer in pence — negative is money out. Money In / Money Out are presentation only (accessors + SQL CASE expressions); never add columns for them, two columns can disagree and one cannot. SQLite has no true decimal type.

AMEX CSVs write a charge as POSITIVE, the opposite of Monzo. ImportAmexCsv flips the sign so one convention holds everywhere.

`overrides` is a json map of column name => true for every field the user edited by hand. Imports and syncs must write through Transaction::withoutOverridden(), so a re-sync can never undo a manual correction. `Transaction::BANK_FIELDS` lists what an import may touch at all; anything outside it is local state or identity and is never an override. `processed` is one of those: an import gives every new row `processed = false` and only the user marks it off, so there is nothing for a sync to undo.

`categorised_by` is 'rule' | 'user', or null for a row nothing has filed yet. No bank's categorisation is read — an import writes no category at all, so `category` is not in `BANK_FIELDS` and a row starts uncategorised until a rule matches it or the user files it. Rules never touch a row marked 'user'.

## Only BANK_FIELDS are recorded as overrides
An override exists to stop an import writing over a hand correction, so only columns an import can touch belong in the map. `UpdateTransaction` intersects the edited keys with `Transaction::BANK_FIELDS` before calling `markOverridden()`, and skips the call entirely when nothing survives, so an edit to local state leaves `overrides` null rather than writing an empty map.

`accounting_date` is local state on those terms: it is editable, it is outside BANK_FIELDS, and `TransactionData::bankAttributes()` never mentions it, so a sync cannot touch it and it is never an override. `processed` is local state on the same terms.

## Every category is the user's own; DEFAULTS is the whole set
There is no built-in vs custom split any more. `Category::createCustom` builds a value from the label alone — the old `custom_` prefix was dropped by the 2026_08_20_112037_drop_custom_category_prefix migration and must not come back.

`Category::DEFAULTS` is now the entire category set, not a starting list to add to. Every distinct `set_category` in `CategoryRuleSeeder::CATEGORY_RULES` must be a key of DEFAULTS — DatabaseSeederTest asserts that direction, so adding a rule with a new category means adding that category to DEFAULTS in the same change. The reverse is not asserted: a category with no rule behind it is one the user files transactions under by hand (`eating_out`, `dating`, `holiday` and `social` are those today).

`DatabaseSeeder` no longer keeps its own category list; it just calls `Category::seedDefaults()`.

## A month key is char(7) 'YYYY-MM', never a date column
category_targets.month stores '2026-08' as char(7). Do not "improve" it to a date holding the first of the month. The date cast writes 'Y-m-d H:i:s' while a ->toDateString() binding leaves 'Y-m-d', and SQLite compares both as text, so the two never match — the same trap that forced the half-open bare-date range in TransactionQuery::countsTowardsMonthCondition(). 'YYYY-MM' is also the exact string ?month= and the month prop carry, so nothing converts at any edge, and it is fixed width and zero padded, so text order is chronological order (that is what latestMonthBefore() relies on). Do not cast month on the model.

A stored row is the whole of "there is a target here". amount_minor is NOT NULL and positive. No row means no target; a row holding 0 means a deliberate target of nothing. So SaveCategoryTargets deletes on a cleared field rather than writing 0 — otherwise a target could never be un-set. A category missing from the payload is left alone.

Carry-forward is a prefill computed in the controller (CategoryTarget::prefillFor), not a fallback at read time. Read-time fallback would make "no target this month" unrepresentable without tombstone rows. The fallback is per month, not per category: a month with any row of its own shows only those.
