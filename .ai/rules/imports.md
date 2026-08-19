---
paths:
  - 'app/Actions/Imports/**'
---

# Imports

## AMEX is unreachable via the Monzo API — it arrives by CSV
The AMEX card is a *connected account* inside the Monzo app. Monzo's developer API does not expose connected accounts — confirmed by Monzo staff — so `GET /accounts` returns only Monzo's own accounts, and Monzo's categorisation of AMEX rows is unreachable. The app's CSV/QIF export omits them too. Do not try to sync AMEX over the Monzo API.

AMEX rows come from a CSV downloaded from americanexpress.com. Users can add, remove and reorder columns in that export, so CsvColumnMap matches normalised header aliases rather than fixed positions, and reports which required columns were missing.

Dedupe prefers the export's `Reference`. Without one, identity is date+amount+description plus an occurrence index within the file — the index is what stops two genuinely separate identical same-day purchases collapsing into one row.

Carbon 3 THROWS on an unparseable date rather than returning false, so the format-fallback loop must try/catch per format. UK dates are day-first.
