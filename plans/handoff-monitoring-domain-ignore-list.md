# Handoff: Monitoring domain ignore list (built 2026-09-14, uncommitted)

## What was built

A fleet-wide **domain ignore list for uptime monitoring**, managed from
**Monitoring → Settings** (`/monitoring/settings`). Operators enter wildcard
patterns (one per line, e.g. `*.mystagingwebsite.com`,
`*.builtlikeclockwork.com`, or exact hostnames like `staging.example.com`).
Matching sites are removed from uptime monitoring: the probe runner skips them
(no probes, no alerts, no state churn) and they disappear from the
`/monitoring` board, its KPI counters, and the down-sites section of `/issues`
(including the nav badge count).

Motivation: Pressable staging clones (`*.mystagingwebsite.com`) kept showing
up as DOWN / NOT OUR FAULT on the monitoring board. The per-site
`uptime_monitoring_enabled` toggle doesn't scale because new clones keep
appearing; one wildcard pattern covers them permanently.

## Design decisions

- **Storage**: `app_settings` key `monitoring.ignored_domain_patterns`, a JSON
  array of normalized (lowercase, trimmed, deduped) patterns. No migration —
  `AppSetting.value` is already JSON-cast.
- **Enforcement is query-level**, not collection-level: patterns become
  `WHERE domain NOT LIKE` clauses (`*` → `%`), so consumers stay cheap and
  consistent.
- **Charset guard doubles as SQL safety**: patterns must match
  `/^[a-z0-9*][a-z0-9.*-]*\.[a-z0-9*-]+$/` both at parse time and again when
  read back from Settings. Literal `%`/`_` can therefore never reach the LIKE
  clause. This matters because **SQLite LIKE has no default escape character**
  — escaping was not an option, exclusion was.
- **A bare `*` is invalid** (the regex requires a dot) — it would silently
  ignore the entire fleet.
- **`*.example.com` matches subdomains only, NOT the apex** `example.com`
  (both in `Str::is()` matching and in the LIKE translation — they agree).
  Documented; add a second line for the apex if wanted.
- **Scope is uptime monitoring only.** Ignored sites stay fully managed for
  updates, security scans, backups, capacity. This is deliberate and
  documented — do not extend the scope to other subsystems without asking.
- Invalid textarea lines bounce the whole save with a validation error on
  `ignored_domains` (nothing is persisted); the settings page shows which
  sites the current list matches ("blast radius" panel).

## Files touched

New:
- `app/Support/Monitoring/DomainIgnoreList.php` — the whole feature's logic:
  `SETTING_KEY`, `patterns()` (defensive re-validation on read),
  `matches(?string)` (Str::is, case-insensitive), `applyExclusion(Builder)`
  (NOT LIKE clauses), static `parseInput(?string)` → `{patterns, invalid}`.
- `tests/Feature/Support/DomainIgnoreListTest.php`
- `plans/handoff-monitoring-domain-ignore-list.md` (this file)

Modified:
- `app/Models/Site.php` — new `scopeNotDomainIgnored()` next to
  `scopeHostMonitored()`; try/catch mirrors `areCarePlansEnabled()` so a
  missing settings table on a half-installed box degrades to a no-op. Added
  `use App\Support\Monitoring\DomainIgnoreList;`.
- `app/Console/Commands/CheckSiteUptime.php` — probe query adds
  `->notDomainIgnored()`; docblock updated.
- `app/Http/Controllers/MonitoringController.php` —
  - `index()`: site query adds `->notDomainIgnored()` (KPIs derive from the
    filtered collection, so counters follow automatically).
  - `settings()`: injects `DomainIgnoreList`, passes `$ignoredPatterns` +
    `$ignoredMatchedSites` (collection filter via `matches()`, only computed
    when patterns exist).
  - `updateSettings()`: validates `ignored_domains` (nullable string,
    max 10000), `parseInput()`, bounces on any invalid line (withErrors +
    withInput, shows up to 5 offending lines), otherwise persists the parsed
    array alongside interval/threshold.
- `app/Support/IssueCounter.php` — `$downSites` count query adds
  `->notDomainIgnored()` (keeps nav badge in sync with /issues page).
- `app/Http/Controllers/IssuesController.php` — `$downSites` list query adds
  `->notDomainIgnored()` (the KEEP IN SYNC pair of the above).
- `resources/views/monitoring/settings.blade.php` — "Ignored domains"
  textarea inside the existing settings form (old()-aware, @error block,
  placeholder shows example patterns) + matched-sites panel linking each site.
  Uses existing tokens (`--color-surface-alt`, `--color-status-red`).
- `tests/Feature/Controllers/MonitoringControllerTest.php` — 5 new cases:
  save, clear-on-empty, reject-invalid-without-saving, index hides matched
  sites + counters, settings page lists matched sites.
- `tests/Feature/Console/CheckSiteUptimeTest.php` — 1 new case: runner skips
  matched sites (Http::assertNotSent + `uptime_last_checked_at` stays null).
- `resources/docs/features/uptime-monitoring.md` — new "Domain ignore list"
  section, settings-knobs list updated to three knobs, frontmatter
  `updated: 2026-09-14`, `tracks:` now includes `app/Support/Monitoring/**`.

Deliberately NOT touched:
- `Site::scopeStuckInMaintenance()` — left as-is; extend if staging sites
  stuck in maintenance start nagging.
- Import-time staging auto-disable (`staging.*`, `dev.*` patterns) — separate
  existing mechanism, unchanged.

## Verification status

- Full suite green: `./vendor/bin/pest --parallel` → **2237 passed**
  (9807 assertions).
- PHPStan clean on all touched PHP files (project `phpstan.neon`).
- NOT verified in a live browser session; the blade changes are covered by
  the feature tests' assertSee checks only.

## Gotchas for future work

- The two screenshot sites (`michelli.mystagingwebsite.com`,
  `headlesstesting.mystagingwebsite.com`) will keep their stale `down` state
  rows in the DB after the patterns are added — harmless, everything that
  displays them is filtered, but don't be surprised seeing `uptime_state =
  'down'` in raw queries.
- `Settings` is a per-request singleton with an in-memory cache;
  `DomainIgnoreList` is resolved fresh but wraps that singleton, so writes in
  the same request/process are immediately visible (tests rely on this).
- If you add another monitoring surface (new dashboard, alert digest, etc.),
  remember `->notDomainIgnored()` — grep for existing call sites.
- The working tree already contained unrelated uncommitted changes (capacity,
  issues, companion client, www-consolidation migration, etc.) before this
  feature; nothing here has been committed. Keep this feature's files in
  their own commit, separate from that other work.
