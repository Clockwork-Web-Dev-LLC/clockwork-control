# Plan: Backend theme system (dark/light + named color schemes)

## Context

The admin backend is currently hardcoded light (`layouts/app.blade.php`: `<body class="min-h-screen
bg-white">`), with no dark mode, no theme toggle, and no persistence anywhere. The frontend stack
is Tailwind v4 (CSS-first — no `tailwind.config.js`; all theming lives in a `@theme` block inside
`resources/css/app.css`) plus Alpine.js only (no Livewire/Vue/React). The user wants at minimum a
dark/light toggle, but ideally something closer to a PHPStorm-style editor color-scheme picker — a
small curated set of named schemes, not just a binary switch.

Good news: `resources/css/app.css` already defines `--color-surface: #ffffff` alongside a
**currently unused** `--color-surface-dark: #181e25` — clearly staged for exactly this, never wired
up to anything.

## Key decision: `[data-theme="..."]` attribute + CSS custom-property blocks

Tailwind v4's built-in `dark:` variant is binary (toggles on a `.dark` class or the OS media query)
— it cannot express "pick one of four named schemes." Instead, use a `data-theme` attribute on
`<html>`, driven entirely by CSS custom-property overrides using the same variable names the
`@theme` block already defines:

- The existing unscoped `@theme { --color-surface: #ffffff; ... }` block becomes the **`light`
  scheme's values**, explicitly re-homed under `:root[data-theme="light"]` (with `light` also the
  fallback default when no attribute is set, so nothing regresses for anyone mid-rollout).
- Each additional scheme is a sibling override block, e.g.
  `:root[data-theme="dark"] { --color-surface: #181e25; --color-ink: #e6e8eb; ... }`, reusing the
  already-defined `--color-surface-dark` token as the dark scheme's surface value.
- Every existing component class (`.card`, `.btn-pill-nav`, `.status-pill`, `.cw-switch`) and any
  Tailwind utility that resolves through these variable names themes for free, with zero markup
  change, once a scheme's block exists. Adding a fifth scheme later is purely a new CSS block plus
  one registry entry — no JS or template changes required.

**Starter scheme set (keep modest):**
1. **Light** — current default, explicitly named.
2. **Dark** — uses the already-staged `--color-surface-dark`, inverted ink/border/status tones.
3. **Midnight** — a cooler, deep-navy variant (surface near `#10131c`), the "IntelliJ Darcula"-ish
   option, brand accents kept saturated.
4. **High Contrast** — boosted contrast light/dark pairing, for accessibility.

## FOUC prevention

An inline, non-deferred `<script>` in `resources/views/layouts/app.blade.php`'s `<head>`, placed
**before** the compiled CSS/Vite tag, so `data-theme` is set before first paint. Precedence order:

1. Read a `cw_theme` cookie synchronously via `document.cookie` — works pre-hydration and for
   guest/logged-out pages (login screen, etc.), without a DB round trip.
2. If the cookie value is `system` (the saved "follow OS" preference), resolve via
   `window.matchMedia('(prefers-color-scheme: dark)')` → `dark` or `light`.
3. If no cookie exists at all, fall back to `matchMedia` directly, then hard default `light`.

The script sets `document.documentElement.setAttribute('data-theme', resolved)` synchronously. The
`users.theme` DB column (below) is the durable source of truth for logged-in users, but is never
read pre-paint — the cookie is what keeps DB state and the FOUC script in sync.

## Persistence

No preference column exists on `users` today (checked all migrations — id/name/email/oauth
ids/avatar/password/timestamps only, nothing preference-shaped). New migration adds `theme`
(string, default `'system'`) to `users`. New
`app/Http/Controllers/AppearanceSettingsController.php`, `POST /settings/appearance`, matching this
app's existing one-controller-per-settings-page convention. On save:
`auth()->user()->update(['theme' => $value])`, and set the same long-lived (~1 year) `cw_theme`
cookie in the response so guest pages and the FOUC script stay in sync without a DB hit.

## UI: Settings gear dropdown

Extend the existing `<details data-settings-menu>` block in `layouts/app.blade.php` with an Alpine
component:
- A `.cw-switch` toggle (the existing iOS-style toggle class, already used for other boolean
  settings) for the quick light/dark case.
- Below it, a small row of swatch-preview buttons — one per scheme, each showing a 2–3 color
  thumbnail (surface + brand + ink), the active one ring-highlighted (`aria-pressed`) — like a
  miniature theme picker rather than a plain text dropdown. `@click` posts via `fetch` to
  `/settings/appearance` **and** immediately sets `data-theme` on `<html>` client-side, so the
  change is instant with no page reload.

## The hardcoded-light sweep (bounded, phased — not open-ended)

Only 7 files in the whole codebase currently have any `dark:` classes at all, and they're
incidental (respond only to OS preference, never toggled). Most of the UI uses raw Tailwind
utilities (`bg-white`, `text-gray-900`, etc.) instead of the CSS-variable-backed custom classes, so
those won't theme automatically. Fix: add semantic Tailwind utilities at the `@theme` layer —
Tailwind v4 auto-generates utilities from named color tokens, so once `--color-surface`/
`--color-ink*`/etc. are properly named there, `bg-surface`/`text-ink`/`text-ink-muted`/
`border-border` utilities exist for free, no extra config file needed. The bounded work is then a
tracked, batched find/replace sweep — `bg-white`→`bg-surface`, `text-gray-900`→`text-ink-strong`,
`text-gray-500`/`600`→`text-ink-muted`, `border-gray-200`→`border-border` — across the ~42 views
extending `layouts/app.blade.php` plus the one nested sub-layout
(`dashboard/bans/layout.blade.php`). Track as a checklist, ~8–10 views per batch/PR, so it's
reviewable incrementally rather than one giant diff.

## ECharts theming

ECharts (used for charts via `resources/js/app.js`) does not read CSS custom properties
automatically — chart color config is JS-side. New `resources/js/theme.js`: reads the current
`data-theme` attribute plus resolves the relevant `--color-*` values via
`getComputedStyle(document.documentElement)` at chart-init time, builds/selects an ECharts theme
object. Add a listener (custom event dispatched by the swatch picker, or an Alpine `$watch`) that
re-renders/`setOption`s live charts on a theme change — not just on next page load.

## Phased implementation

**Phase 1 — CSS variable restructuring, FOUC script, migration**
- `resources/css/app.css`: rename the unscoped `@theme` color block to explicit
  `:root[data-theme="light"]`, add `dark`/`midnight`/`high-contrast` sibling blocks.
- `resources/views/layouts/app.blade.php`: add the blocking inline FOUC script in `<head>`.
- New migration: add `theme` column to `users`, default `system`.
- `app/Models/User.php`: add `theme` to `$fillable`.

**Phase 2 — Settings endpoint + picker UI**
- New `app/Http/Controllers/AppearanceSettingsController.php`, `POST /settings/appearance` in
  `routes/web.php`.
- `layouts/app.blade.php`: Alpine-driven swatch picker + `.cw-switch` in the settings dropdown.
- Cookie-setting in the controller response.

**Phase 3 — Semantic utility sweep**
- Confirm `bg-surface`/`text-ink*`/`border-border*` utilities fall out of the renamed `@theme`
  tokens automatically.
- Batched sweep of the ~42 extending views (dashboard/*, settings/*, docs/*) plus
  `dashboard/bans/layout.blade.php`, tracked as a checklist.

**Phase 4 — ECharts theming**
- New `resources/js/theme.js`; wire into `resources/js/app.js`'s chart-init calls; add the
  live-theme-change listener.

**Phase 5 — Docs**
- New docs reference page documenting the scheme mechanism, the variable-naming convention, and
  how to add a new named scheme.

## Files

**New:** `database/migrations/*_add_theme_to_users_table.php`,
`app/Http/Controllers/AppearanceSettingsController.php`, `resources/js/theme.js`.

**Modified:** `resources/css/app.css`, `resources/views/layouts/app.blade.php`,
`resources/js/app.js`, `routes/web.php`, `app/Models/User.php`, the ~42 extending views (batched
across Phase 3 PRs).

## Verification

Pest feature tests: theme preference persists across requests for an authenticated user
(`PATCH /settings/appearance` then a subsequent `GET` reflects cookie/DB value); an unauthenticated
page respects only the cookie; no cookie/DB value defaults to `system` (server-rendered default
attribute, since client-side `matchMedia` resolution can't be asserted server-side).

Manual visual QA: run `composer screenshots` (the existing Dusk-based marketing screenshot tool,
`tests/Browser/ScreenshotAllPagesTest.php`) once per scheme — seed the cookie/user `theme` before
each run — for cheap before/after visual regression review across all four schemes.
