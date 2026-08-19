---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## External redirects need Inertia::location and a plain anchor
Never send a user to a third-party URL with `redirect()->away()` from a route reached by an Inertia `<Link>`. The Link visits over XHR, the browser follows the 302 cross-origin, and CORS blocks it ("No 'Access-Control-Allow-Origin' header"). This bit the Monzo OAuth handoff.

Both halves are needed:
- Controller returns `Inertia::location($url)` — a 409 plus X-Inertia-Location for an XHR visit, an ordinary 302 for a direct request. Return type is SymfonyResponse.
- The trigger is a plain `<a href>`, not `<Link>`. The same applies to any file download.

`Button asChild` wrapping an `<a>` ignores `disabled`; use `aria-disabled` plus `pointer-events-none` styling instead.

Testing the 409 path needs the real asset version header, otherwise Inertia returns its own 409 reload pointing at the current URL and the assertion is misleading: `app(HandleInertiaRequests::class)->version(Request::create($url))`.

## Cast empty associative arrays to objects before sending to Inertia
An empty PHP array encodes as a JSON array, not an object. A prop built with array_filter or a keyed collection therefore arrives in the browser as `[]` the moment it happens to be empty, and reading a key off it finds an Array.prototype method instead of undefined.

This silently broke sort toggling: `filters.sort ?? 'date'` returned `Array.prototype.sort` (a truthy function), so `key === sort` was never true and every header click re-applied a fixed direction instead of flipping it. No error anywhere — just a control that quietly did the wrong thing.

Cast at the point of serialisation: `'filters' => (object) $filters->toQuery()`, `'subtotals' => (object) $query->groupSubtotals()`. Lists (tags, overriddenFields, accounts) are genuinely arrays and must stay as-is.

Guard it by asserting on the raw Inertia JSON, not via assertInertia — that decodes to a PHP array and cannot tell `{}` from `[]`. See "filters and subtotals reach the browser as objects even when empty" in TransactionIndexTest.

## Call toBase() before merging mapped Eloquent collections
`->get()->map(fn ($model) => [...])` on an Eloquent collection only demotes itself to a base collection when it has rows to inspect. An empty result stays an Eloquent collection, so `merge()` with plain arrays from the other side calls `getKey()` on an array and fatals — and only when that one source happens to be empty, so it passes any test that seeds both.

SyncReportController merges Monzo and AMEX reports; both halves call `->toBase()` explicitly. Do the same anywhere two mapped query results are merged.
