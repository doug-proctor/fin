---
paths:
  - 'app/Actions/Transactions/**'
---

# Actions Transactions

## A rule's rename is not recorded as an override
CategoryRule::$set_name replaces the matched transaction's `name`, and ApplyCategoryRules skips it when the user has already overridden `name`.

The rename is deliberately not written into `overrides`: that map means "the user owns this field". UpsertTransaction::refresh() fills the bank's attributes first and then re-runs the rules, so the rename is re-applied on every sync rather than frozen.

Consequence to expect: re-applying rules over stored rows is not idempotent for a rule that matches on `name`. Once renamed, the row no longer matches, so the rules page's match count for that rule falls to zero. Matching on `description` or `merchant_name` avoids it.

## Notes and tags are separate fields; only an import reads #tags out of a note
Tags used to be the "#word" words inside the note, re-derived on every hand edit. They now have their own field in the edit dialog, so the two no longer overlap: UpdateTransaction fills what was sent and nothing else, and editing the wording of a note can never rewrite the tags.

Lifting "#word" out of a note is the bank's convention applied to the bank's note, so it lives in TransactionData::parseTags and runs on import only.

TransactionData::normaliseTag is the single spelling of a tag — no leading hash, whitespace hyphenated, lower case. TransactionUpdateRequest::prepareForValidation runs incoming tags through normaliseTags, so the list is stored the one way whatever the client sends, and an emptied list is stored as null (the facets query looks for a null). components/transactions/tag-input.tsx mirrors it; change both together.

## A tags override permanently blocks a rule's set_tags
ApplyCategoryRules skips a rule's `set_tags` whenever `overrides['tags']` is true, and nothing on screen says why. A rule that looks correct on the rules page (it matches, its category lands) can still never tag that one row.

Tags used to be lifted out of the note on every hand edit, so the old UpdateTransaction marked `tags` overridden whenever `notes` was sent. Rows edited then carry the flag with no tags behind it. Migration 2026_08_20_105533_clear_empty_tag_overrides_on_transactions strips the flag where the tag list is empty — an override protects a value, and there is none there.

If you ever mark a field overridden again from something other than the user's own edit to that field, expect this shape of bug: the write is skipped silently and forever.
