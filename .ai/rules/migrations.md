---
paths:
  - 'database/migrations/**'
---

# Migrations

## Renaming a column an old data migration names breaks that migration's test
A data migration is frozen: it goes on naming the column it was written against. The tests for those migrations replay them by hand against the current schema, so renaming or dropping the column makes the replay fail with "no such column".

2026_08_20_115144 replaced `category_rules.match_value` with the `match_values` list. RetireAvoltaRuleAndShoppingCategoryMigrationTest's helper now adds `match_value` back, fills it from the first entry of each rule's list, replays the migration and drops the column again.

Do the same for any future rename: grep the migration folder for the old column name, and repair the helper in every test that replays a migration still using it. Do not edit the old migration — it must keep working in a fresh chain, where it runs before the rename.
