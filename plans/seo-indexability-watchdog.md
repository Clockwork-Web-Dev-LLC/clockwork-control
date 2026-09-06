# Plan: Accidental noindex / SEO Indexability Watchdog

## Context

The single most expensive mistake in agency web work: a site launches (or a staging environment gets
promoted) with search-engine indexing still blocked — a leftover `noindex` meta tag, an `X-Robots-Tag`
header from a caching layer, or a blanket `Disallow: /` copied from staging's `robots.txt`. Google drops
every page from search within days; the agency finds out weeks later when the client's organic traffic
has already cratered. This was originally researched by Gemini
(`plans/accidental-noindex-watchdog.md`) and verified in `plans/gemini-research-assessment.md`. This
plan supersedes that draft with the corrections found during verification — most importantly, it
**piggybacks on the uptime prober's existing HTTP fetch instead of adding a new one**, and correctly
scopes the WordPress-side (`blog_public`) signal as new work in a separate repository rather than
something already available.

## Key architectural decisions

1. **Lives in `app/Services/Seo/`, not `modules/LaunchWatchdog`.** Same reasoning as the domain-expiration
   plan: this is core detection/monitoring logic (a state machine + Issues-page integration), not a
   swappable third-party integration — it belongs directly under `app/Services/`, matching
   `app/Services/Ssl/`, `app/Services/Uptime/`, `app/Services/Security/`.
2. **Do not add a second HTTP probe cycle.** `App\Services\Uptime\UptimeProber` already fetches every
   monitored site's homepage HTML every 5 minutes (288×/day per site) via `CheckSiteUptime`, then
   discards the response body and headers, keeping only status/timing
   (`UptimeProbeResult` only retains `succeeded`/`statusCode`/`responseTimeMs`/`error`). Extend
   `UptimeProbeResult` to optionally carry the response body and the `X-Robots-Tag` header value, and
   have the indexability check run as a side-effect of the *existing* probe cycle instead of its own
   6-hour job — this eliminates ~600 redundant full-page fetches/day against client sites for data
   that's already being fetched 57× more often than the original plan even proposed. **Only `robots.txt`
   genuinely needs its own fetch** (it's a different URL entirely) — schedule that separately and
   infrequently (daily is plenty; a site's `robots.txt` rarely changes).
3. **The WordPress-side `blog_public` signal (Vector 4) is real, new work in the separate
   `clockwork-companion` plugin repository — it is NOT already available.** Verified: zero references to
   `blog_public` anywhere in this codebase or its docs today. Scope this as an explicit sub-task with its
   own Companion capability + version bump, clearly separated from Vectors 1–3 (which are entirely
   Clockwork-Control-side and shippable independently). **Recommendation: ship Vectors 1–3 first; treat
   Vector 4 as a fast-follow once a Companion release cycle is available**, since it's the only vector
   with an external dependency.
4. **Use a real DOM parser for the meta-tag check, not regex.** PHP's built-in `DOMDocument` +
   `DOMXPath` (no new Composer dependency — always available) is sufficient: load the HTML with libxml
   error suppression (malformed HTML is common and shouldn't throw), then
   `//meta[@name="robots" or @name="googlebot"]` via XPath, case-insensitively check the `content`
   attribute for `noindex`.
5. **Use `bopoda/robots-txt-parser` (Packagist) for `robots.txt`, not hand-rolled string matching.**
   Confirmed via reading its source: it correctly implements Robots Exclusion Protocol longest-match
   precedence (a `Disallow: /` combined with a more specific `Allow: /some-path` correctly does NOT block
   `/some-path`) — a hand-rolled "does the string contain `Disallow: /`" check would incorrectly flag
   sites that have deliberately carved out exceptions.
6. **Reuse the `IssueCounter`/`issues.blade.php` integration pattern as-is** — verified it already matches
   this codebase's conventions well; no changes needed to that part of the original plan.

## Files to create

### Migration

`database/migrations/{date}_add_seo_indexability_fields_to_sites_table.php`:
```php
Schema::table('sites', function (Blueprint $table) {
    $table->boolean('seo_indexable')->default(true)->after('care_plan_enabled');
    $table->string('seo_blocked_reason')->nullable()->after('seo_indexable'); // meta_noindex, header_noindex, robots_disallow_all, wp_blog_public_zero
    $table->timestamp('seo_checked_at')->nullable()->after('seo_blocked_reason');
    $table->text('seo_blocked_snippet')->nullable()->after('seo_checked_at'); // the raw tag/header/line that triggered the block
    $table->boolean('seo_monitoring_enabled')->default(true)->after('seo_blocked_snippet');
    $table->timestamp('seo_state_changed_at')->nullable()->after('seo_monitoring_enabled');
});
```
(Keeping Gemini's original column design — it's sound and doesn't need correction.)

### `app/Services/Seo/IndexabilityInspector.php`

Pure inspection logic, no HTTP/SSH of its own — takes data the caller already has:
- `inspectMeta(string $html): ?string` — DOMDocument+XPath check for `<meta name="robots"|"googlebot">`
  containing `noindex`; returns the matched tag's outerHTML snippet or `null`.
- `inspectHeader(?string $xRobotsTagHeaderValue): ?string` — checks for `noindex` in the header value
  (case-insensitive), returns the raw header value or `null`.
- `inspectRobotsTxt(string $robotsTxtContent, string $testPath = '/'): ?string` — uses
  `bopoda/robots-txt-parser`'s `RobotsTxtValidator::isUrlAllow($testPath, '*')`; if disallowed, returns a
  descriptive snippet (the matched `Disallow` line) rather than just a boolean.
- `classify(?string $metaResult, ?string $headerResult, ?string $robotsResult): array` — returns
  `['blocked' => bool, 'reason' => ?string, 'snippet' => ?string]`, checking in a fixed priority order
  (meta tag first, since it's the most site-specific/deliberate signal; then header; then robots.txt)
  since only one `seo_blocked_reason` is stored per site.

### `app/Services/Seo/IndexabilityChecker.php`

The orchestrator — but note this one has a different shape than `SslChecker`/`DomainExpirationChecker`
because it doesn't own its own HTTP fetch for Vectors 1–2:
- `checkFromProbeResult(Site $site, UptimeProbeResult $probeResult): void` — called from the existing
  uptime-probe pipeline (see below) after each scheduled probe; reads the (newly-retained) body/header
  off `$probeResult`, runs `IndexabilityInspector::inspectMeta()`/`inspectHeader()`, always updates
  `seo_checked_at`, persists `seo_indexable`/`seo_blocked_reason`/`seo_blocked_snippet` and
  `seo_state_changed_at` only when the derived state actually changes since the last check, fires
  `ChatNotifier` only on clean→blocked (a P0-worthy
  event) and on blocked→clean (recovery), and **skips staging-tagged sites entirely** for the
  critical-alert path — check via `$site->server?->isStaging()` (the exact existing method on the
  `Server` model, checking `$this->tags->contains(fn (Tag $t) => $t->slug === 'staging')`; do not
  reimplement this check, call the existing method) — a `noindex` on a deliberately-staging site is
  expected, not a bug; render it as a neutral "Protected from Search" state, not an alarm.
- `checkRobotsTxt(Site $site): void` — separate method, does its own single HTTP fetch of
  `https://{domain}/robots.txt`, called from its own daily-scheduled command (see below), same
  transition/notification logic as above.

### `app/Services/Uptime/UptimeProbeResult.php` (modify)

Add `?string $body` and `?string $xRobotsTagHeader` fields (nullable). **Always populate both on every
probe, don't make this opt-in** — the HTTP response is already fully in memory before the existing code
discards it; retaining a reference to what's already there costs nothing extra (no re-fetch), and an
opt-in flag would just add API-surface complexity for no real savings. **Neither field is ever persisted
to the database** — they exist only for the duration of the single probe-then-check cycle; only the
derived `seo_*` columns on `Site` get written.

### `app/Services/Uptime/UptimeProber.php` (modify)

The existing `probe()` method should populate the new `body`/`xRobotsTagHeader` fields on the
`UptimeProbeResult` it already builds — no new entry point needed, no behavior change to its return
type's *existing* fields, purely additive.

### `app/Console/Commands/CheckSiteUptime.php` (or wherever the scheduled uptime job dispatches per-site
probes — modify)

After every probe result comes back (every site, every 5-minute cycle — this is intentional, not a
mistake to "fix" by throttling it further), constructor-inject `IndexabilityChecker` and call
`IndexabilityChecker::checkFromProbeResult($site, $probeResult)` inline. No new scheduled job, no new
cron entry — this piggybacks on the existing cadence entirely. `checkFromProbeResult()` itself is cheap
per call even at this frequency: a DOM parse plus a conditional write (only writes if the derived state
actually changed since last time), never an extra HTTP request.

### `app/Console/Commands/CheckRobotsTxt.php` (new, small, scheduled daily)

`clockwork:check-robots-txt` — iterates monitored sites, fetches `/robots.txt` once per site (a single,
cheap, infrequent extra request per site per day — nothing like the original plan's proposed 6-hourly
full-page re-fetch), calls `IndexabilityChecker::checkRobotsTxt()`. Register in `routes/console.php`
scheduled **daily at `07:15` UTC** — check what's already scheduled in that window first and adjust to
avoid collision.

### `app/Http/Controllers/SitesController.php` addition (or a small dedicated controller)

`POST /sites/{site}/seo/pre-flight-check`, route name `sites.seo.preflight`, registered in
`routes/web.php` alongside the other per-site action routes — the "on-demand pre-flight launch check"
button from the original plan: runs all three Clockwork-side vectors synchronously (a fresh homepage
fetch + robots.txt fetch, bypassing any cadence guard) and returns a JSON summary, matching the existing
recheck-button response contract shape used elsewhere in this codebase.

### `app/Services/Chat/ChatNotifier.php` and its implementations (event wiring)

Follow the exact existing `ssl_state_changed` precedent — do not invent a different pattern (see the
domain-expiration plan's identical section for the full reasoning; summarized here):
1. Add two keys to `ChatNotifier::EVENTS`: `'seo_indexability_blocked'` (critical, `'default' => true`)
   and `'seo_indexability_recovered'` (`'default' => true`).
2. Add `public function seoIndexabilityBlocked(Site $site, string $reason, string $snippet): bool;` and
   `public function seoIndexabilityRecovered(Site $site): bool;` to the `ChatNotifier` interface.
3. Implement in exactly **three** places: `app/Services/Chat/ChatNotifierDispatcher.php` (one-line
   delegator, same shape as `sslStateChanged`'s), `modules/Core/src/Support/WebhookChatNotifier.php`
   (the real message-building logic — shared base for both Mattermost and Slack, so this single
   implementation covers both channels), and `modules/ClientSlack/src/ClientSlackNotifier.php`
   (implements independently, doesn't extend `WebhookChatNotifier`). Do not add separate
   implementations directly to `MattermostNotifier`/`SlackNotifier`. `IndexabilityChecker` should call
   `ChatNotifierDispatcher`, not a concrete notifier.

### `resources/views/dashboard/issues.blade.php` additions

Same pattern as the domain-expiration plan: a `$chips` entry, a new
`<section id="section-seo-indexability">` block copying the SSL section's structure (status pill count,
sortable table, recheck button), with a distinct visual treatment for "Protected from Search" (staging,
neutral/gray) vs. "Blocking Search Engines" (production, critical red) states in the same table.

### `app/Support/IssueCounter.php` and `app/Http/Controllers/IssuesController.php`

Add a counting block to both, **with the explicit per-block `// KEEP IN SYNC` comment** (the newer,
stricter convention this codebase has been trending toward) — count only production sites (excluding
staging-tagged, per decision #3 above) with `seo_indexable = false`.

### `resources/docs/features/seo-indexability-watchdog.md`

Match `ssl-cert-tracking.md`'s frontmatter/section format exactly (same caution as the domain-expiration
plan: keep prose state names identical to the actual code, don't let the doc drift from what's actually
implemented).

## Composer dependency

```
composer require bopoda/robots-txt-parser
```
(No dependency needed for the meta/header checks — PHP's built-in `DOMDocument`/`DOMXPath` suffice.)

## Companion plugin work (separate repo, fast-follow, not blocking Vectors 1–3)

In `~/Projects/clockwork-companion` (per this project's reference memory of where that repo lives): add
a new capability reporting `get_option('blog_public')` in the existing snapshot/telemetry payload, bump
the plugin version, and wire `IndexabilityChecker` to prefer this signal (an instant, zero-HTTP-fetch
confirmation) when available, falling back to the HTTP-based vectors for sites without Companion
installed or on an older version. This is a real, separate mini-project — don't estimate it as "free"
alongside Vectors 1–3.

## Before considering this done

Run the full suite and confirm green: `./vendor/bin/pest`, `./vendor/bin/phpstan analyse`,
`./vendor/bin/pint --test`. Check `tests/Feature/ArchitectureTest.php` for any existing rule that might
need a new entry.

## Verification & test plan

**Unit tests** (`tests/Unit/Services/Seo/`):
- `IndexabilityInspectorTest::inspectMeta()` — covers `noindex`, `NOINDEX` (case), whitespace variance,
  `<meta name="googlebot">` variant, an HTML comment containing the literal string `noindex` (must NOT
  match — proves the DOM-parser approach beats regex), and a clean page (no match).
- `inspectHeader()` — covers a present `X-Robots-Tag: noindex, nofollow` and absence.
- `inspectRobotsTxt()` — the critical case: `User-agent: *` with `Disallow: /` AND `Allow: /public-page`
  must correctly report `/public-page` as allowed (proving `bopoda/robots-txt-parser`'s precedence
  handling is actually wired correctly) alongside a genuine blanket-disallow case that correctly blocks.
- `classify()` priority-order test (meta > header > robots.txt when multiple vectors fire at once).

**Feature tests** (`tests/Feature/`):
- `IndexabilityCheckerTest` — feed a fake `UptimeProbeResult` with a `noindex` body, assert
  `seo_indexable` flips to `false`, `seo_blocked_reason`/`seo_blocked_snippet` populate, and exactly one
  `ChatNotifier` critical alert fires on the clean→blocked transition (not on repeated blocked→blocked
  runs).
- Assert a staging-tagged site with the identical `noindex` body does NOT fire a critical alert, and
  instead reflects the neutral "Protected from Search" state.
- Assert a blocked→clean transition fires a recovery notification, not a re-alert.
- `CheckRobotsTxtCommandTest` — asserts the daily command only touches `robots.txt`, not the homepage.
- `IssueCounter`/`IssuesController` sync test, same shape as the domain-expiration plan's.
- Pre-flight-check endpoint test — asserts it runs synchronously and returns the documented JSON shape,
  bypassing normal cadence.

**Manual verification**: point the pre-flight-check button at a real staging site with `noindex`
enabled (expect "Protected from Search," no alert) and a real production site (temporarily) with
`noindex` added (expect an immediate critical alert and a red Issues-page entry), then remove it and
confirm the recovery notification fires.
