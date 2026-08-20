---
paths:
  - app/Models/Transaction.php
---

# Models

## Money is signed minor units; hand edits are protected by the overrides map
`amount_minor` is a single signed integer in pence — negative is money out. Money In / Money Out are presentation only (accessors + SQL CASE expressions); never add columns for them, two columns can disagree and one cannot. SQLite has no true decimal type.

AMEX CSVs write a charge as POSITIVE, the opposite of Monzo. ImportAmexCsv flips the sign so one convention holds everywhere.

`overrides` is a json map of column name => true for every field the user edited by hand. Imports and syncs must write through Transaction::withoutOverridden(), so a re-sync can never undo a manual correction. `Transaction::BANK_FIELDS` lists what an import may touch at all; anything outside it is local state and is never an override.

`categorised_by` is 'source' | 'rule' | 'user'. Rules never touch a row marked 'user'.

## Only BANK_FIELDS are recorded as overrides
An override exists to stop an import writing over a hand correction, so only columns an import can touch belong in the map. `UpdateTransaction` intersects the edited keys with `Transaction::BANK_FIELDS` before calling `markOverridden()`, and skips the call entirely when nothing survives, so an edit to local state leaves `overrides` null rather than writing an empty map.

`accounting_date` is local state on those terms: it is editable, it is outside BANK_FIELDS, and `TransactionData::bankAttributes()` never mentions it, so a sync cannot touch it and it is never an override.
