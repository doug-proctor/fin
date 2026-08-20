---
paths:
  - 'routes/**'
---

# Routes

## Regenerate Wayfinder with --with-form
vite.config.ts configures the Wayfinder plugin with `formVariants: true`, so every generated route carries a `.form()` helper and pages use it (`{...syncMonzo.form()}`).

Running `php artisan wayfinder:generate` by hand without `--with-form` regenerates the whole of resources/js/routes and resources/js/actions without those helpers. Nothing fails at generation time; `npm run types:check` then reports "Property 'form' does not exist" in ten unrelated files, which reads like a broken dependency rather than a stale generate.

Always run `php artisan wayfinder:generate --with-form` after adding or renaming a route, or just let `npm run dev` / `npm run build` do it. resources/js/routes and resources/js/actions are gitignored, so git status will not show what you overwrote.
