# Plan: Domain Expiration & Registrar Tracking

## Context

Every agency managing a client fleet eventually hits the "lapsed domain catastrophe": a registrar
auto-renew fails silently, the domain slips into its grace/redemption period, and the site (and the
client's email) goes dark with zero warning — the agency finds out when the client calls in a panic.
This was originally researched by Gemini (`plans/rdap-domain-expiration.md`) and verified in
`plans/gemini-research-assessment.md`. This plan supersedes that draft with the corrections found during
verification, and is written to exactly mirror this codebase's existing SSL-cert-tracking feature —
which solves the structurally identical problem (a per-site expiration date you must poll, threshold,
and alert on) and should be copied, not reinvented.

**The API is real and free**: `https://rdap.org/domain/{domain}` 302-redirects to the domain's
authoritative registry (Verisign for `.com`, PIR for `.org`, etc.) and returns standardized RFC 7483
JSON with an `events` array containing an `expiration` eventAction — confirmed live, no API key, no
auth header.

## Key architectural decisions

1. **Lives in `app/Services/Domains/`, not `modules/DomainExpiration`.** Confirmed by reading how SSL
   cert tracking, uptime probing, and security scanning are actually built: core detection/monitoring
   logic (state machines, threshold evaluation, transition detection, Issues-page integration) always
   lives directly under `app/Services/{Domain}/`, never in `modules/`. `modules/` is reserved for
   swappable third-party API clients (hosting providers, notification channels) — RDAP has no
   provider-swapping concern (one universal protocol, not a per-vendor API), so there's no reason for a
   module split here at all.
2. **Three states (green/yellow/red), not four.** The plan's original draft proposed
   green/yellow/orange/red. `Site::sslState()` already establishes this codebase's convention as
   exactly three severity states — match it rather than inventing a fourth. Collapse: green (>30 days),
   yellow (≤30 days), red (≤7 days, or RDAP status includes `redemptionPeriod`/`pendingDelete`).
3. **Use `jeremykendall/php-domain-parser`** (Packagist, PSL + IANA-TLD-list based) for root-domain
   extraction instead of hand-rolled regex — multi-part TLDs (`.co.uk`, `.com.au`) break naive regex, and
   this is exactly the class of bug a maintained PSL library exists to prevent.
4. **Respect two independent rate limits**: rdap.org itself is Cloudflare-capped at **10 requests per 10
   seconds** (separate from the plan's already-correct per-registry 429/503 backoff) — pace the nightly
   sweep accordingly, e.g. a queued job per domain with a fixed delay rather than a tight loop.
5. **Follow the exact SSL-cert-tracking file/class shape**, described below file-by-file, because the
   codebase already has a proven, working template for this exact kind of feature.

## Files to create

### Migrations (split into two, matching the SSL precedent's two-migration pattern)

`database/migrations/{date}_add_domain_expiration_fields_to_sites_table.php`:
```php
Schema::table('sites', function (Blueprint $table) {
    $table->timestamp('domain_expires_at')->nullable()->after('cert_state_changed_at');
    $table->string('domain_registrar')->nullable()->after('domain_expires_at');
    $table->string('domain_rdap_status')->nullable()->after('domain_registrar');
    $table->timestamp('domain_rdap_checked_at')->nullable()->after('domain_rdap_status');
    $table->string('domain_rdap_error')->nullable()->after('domain_rdap_checked_at');
});
```

`database/migrations/{date}_add_domain_expiration_state_to_sites_table.php`:
```php
Schema::table('sites', function (Blueprint $table) {
    $table->string('domain_expiration_state')->default('none')->after('domain_rdap_error');
    $table->timestamp('domain_expiration_state_changed_at')->nullable()->after('domain_expiration_state');
});
```

### `app/Models/Site.php` additions (mirror the exact `sslState()`/constants shape)

```php
public const DOMAIN_EXPIRATION_STATE_NONE = 'none';
public const DOMAIN_EXPIRATION_STATE_GREEN = 'green';
public const DOMAIN_EXPIRATION_STATE_YELLOW = 'yellow';
public const DOMAIN_EXPIRATION_STATE_RED = 'red';

/**
 * Compute the current domain-expiration state for this site.
 *
 * - none: no expiration data yet (never RDAP-checked, or lookup failed every time).
 * - green: > 30 days until expiration.
 * - yellow: <= 30 days until expiration.
 * - red: <= 7 days until expiration, OR the RDAP status includes redemptionPeriod/pendingDelete
 *   (the domain may already be functionally lost even if the raw date hasn't passed).
 */
public function domainExpirationState(): string
{
    if (! $this->domain_expires_at) {
        return self::DOMAIN_EXPIRATION_STATE_NONE;
    }

    $badStatuses = ['redemptionPeriod', 'pendingDelete'];
    if ($this->domain_rdap_status && in_array($this->domain_rdap_status, $badStatuses, true)) {
        return self::DOMAIN_EXPIRATION_STATE_RED;
    }

    $now = Carbon::now();
    if ($this->domain_expires_at->lessThanOrEqualTo($now)) {
        return self::DOMAIN_EXPIRATION_STATE_RED;
    }

    $daysLeft = $now->diffInDays($this->domain_expires_at);
    if ($daysLeft <= 7) {
        return self::DOMAIN_EXPIRATION_STATE_RED;
    }
    if ($daysLeft <= 30) {
        return self::DOMAIN_EXPIRATION_STATE_YELLOW;
    }

    return self::DOMAIN_EXPIRATION_STATE_GREEN;
}

public function domainExpirationStateLabel(): string
{
    return match ($this->domainExpirationState()) {
        self::DOMAIN_EXPIRATION_STATE_GREEN => 'Domain OK',
        self::DOMAIN_EXPIRATION_STATE_YELLOW => 'Domain renewal',
        self::DOMAIN_EXPIRATION_STATE_RED => 'Domain expiring',
        default => 'No domain data',
    };
}
```
Add `domain_expires_at`, `domain_rdap_checked_at`, `domain_expiration_state_changed_at` to `$casts`
(`datetime`); add all new columns to `$fillable`.

### `app/Services/Domains/RdapClient.php`

Thin HTTP client, mirroring `LiveCertProbe`'s "never throws, returns null on any failure" contract:
- `lookup(string $domain): ?RdapDomainResult` — GET `https://rdap.org/domain/{domain}`, following the
  302 to the authoritative registry, 10s timeout. Returns `null` (not an exception) on any transport
  error, 404, or malformed JSON — the caller decides what "no data" means, this class never throws.
- `parseExpirationDate(array $rdapJson): ?CarbonImmutable` — find the `events` array entry where
  `eventAction === 'expiration'`, parse `eventDate`.
- `parseRegistrar(array $rdapJson): ?string` — find the `entities` array entry where `roles` contains
  `registrar`, pull the `fn` (formatted name) field out of its `vcardArray`.
- `parseStatus(array $rdapJson): ?string` — first entry of the top-level `status` array that matches a
  known "bad" status (`redemptionPeriod`, `pendingDelete`), else `null`.
- A small `RdapDomainResult` DTO (`expiresAt`, `registrar`, `status`) as the return shape.
- Rate limiting: a simple `usleep()`-based pace between calls (configurable, default matching rdap.org's
  10-req/10-sec ceiling), plus a per-TLD in-memory/cache-backed backoff: on 429/503, mark that TLD's
  registry "cooling down" for 2 hours and skip it for the rest of the current run.

### `app/Services/Domains/DomainExpirationChecker.php`

The orchestrator, mirroring `SslChecker`'s shape exactly:
- `run(bool $silent = false): array` — iterate `Site::query()->hostMonitored()` (same base scope SSL
  checking uses) in chunks of 200 (`whereNotNull('domain')` implicitly true for all sites), extract each
  site's root domain via `jeremykendall/php-domain-parser`, call `RdapClient::lookup()`, persist
  `domain_expires_at`/`domain_registrar`/`domain_rdap_status`/`domain_rdap_checked_at`, clear
  `domain_rdap_error` on success or set it on failure, re-derive `domain_expiration_state` via
  `$site->fresh()->domainExpirationState()`, detect a state transition vs. the previously stored
  `domain_expiration_state`, persist the new state + `domain_expiration_state_changed_at` only on
  transition, and fire a `ChatNotifier` alert only on a transition to yellow/red (never on `$silent`,
  matching `SslChecker`'s exact "don't alert on first-ever assignment or silent backfill" behavior).
- Polling cadence policy (implemented as: skip sites whose `domain_rdap_checked_at` is too recent): sites
  with `domain_expiration_state` green get checked weekly; yellow/red get checked daily. This lives as a
  `shouldCheck(Site $site): bool` guard inside the orchestrator, not as separate commands.

### `app/Console/Commands/CheckDomainExpirations.php`

Thin wrapper matching `CheckSslCerts.php`'s shape — `clockwork:check-domain-expirations`, a `--silent`
flag for first-time backfill, resolves `DomainExpirationChecker` via DI, prints a one-line summary.
Register in `routes/console.php` scheduled **daily at `06:00` UTC** (the orchestrator's own
`shouldCheck()` guard handles the weekly-vs-daily distinction per site, so the command itself just runs
daily and lets the service decide who actually needs a fresh lookup) — pick a time that doesn't collide
with the existing `clockwork:check-ssl-certs`/nightly security-scan slots; check `routes/console.php`
for what's already scheduled in the 04:00–07:00 UTC window before finalizing the exact minute.

### `app/Http/Controllers/Sites/DomainExpirationController.php` (or add to existing `SitesController`)

One action: `POST /sites/{site}/domain/recheck`, route name `sites.domain.recheck` (mirrors
`sites.cert.recheck`'s naming), registered in `routes/web.php` in the same route group as the existing
`sites.cert.recheck` route — force an immediate RDAP lookup for one site (bypassing the cadence guard),
matching the SSL "Recheck" button's JSON response contract (`{ok, state, expires_at}`).

### `app/Services/Chat/ChatNotifier.php` and its implementations (event wiring)

Follow the exact existing `ssl_state_changed` precedent — do not invent a different pattern:
1. Add a new key to the `ChatNotifier::EVENTS` constant (`app/Services/Chat/ChatNotifier.php`), e.g.
   `'domain_expiration_state_changed' => ['label' => 'Domain expiration state changed', 'description' =>
   'Domain expiration tracking moved between green / yellow / red.', 'default' => true]`.
2. Add `public function domainExpirationStateChanged(Site $site, string $from, string $to): bool;` to the
   `ChatNotifier` interface, directly below the existing `sslStateChanged()` signature.
3. Implement it in exactly **three** places (confirmed by reading the real implementation graph — do
   not implement it separately in Mattermost/Slack, they share a base class):
   - `app/Services/Chat/ChatNotifierDispatcher.php` — one-line delegator:
     `return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->domainExpirationStateChanged($site, $from, $to));`
   - `modules/Core/src/Support/WebhookChatNotifier.php` — the real message-building logic (this single
     class is the shared base for both `MattermostNotifier` and `SlackNotifier`, so implementing it here
     covers both channels at once). Copy `sslStateChanged()`'s exact shape in this file (gate on
     `isEventEnabled('domain_expiration_state_changed')`, same color/emoji-by-severity pattern, same
     message-building style) — do not add separate implementations to
     `modules/Mattermost/src/MattermostNotifier.php` or `modules/Slack/src/SlackNotifier.php` directly.
   - `modules/ClientSlack/src/ClientSlackNotifier.php` — this one does NOT extend `WebhookChatNotifier`
     and needs its own implementation; check how it implements `sslStateChanged()` and mirror that.
   `DomainExpirationChecker` should call `ChatNotifierDispatcher::domainExpirationStateChanged()` (the
   dispatcher, not a concrete notifier directly) — same as every other alert path in this codebase.

### `resources/views/dashboard/issues.blade.php` additions

1. Add to the `$chips` array: `['key' => 'domain-expiration', 'label' => 'Domain', 'class' =>
   'status-yellow']`.
2. Add a new `<section id="section-domain-expiration" class="card overflow-hidden mb-6">` block,
   copying the SSL section's exact structure (header with icon/title/one-line legend/count pill, a
   `sortableTable`-driven table with `domain`/`server`/`state`/`expires`/`registrar` columns, a
   `.recheck-btn` per row posting to the new recheck route and swapping `.cell-state`/`.cell-expires` in
   place, scoped inline `<script>` IIFE keyed to `#section-domain-expiration` so it doesn't collide with
   the SSL section's identical script pattern).

### `app/Support/IssueCounter.php` and `app/Http/Controllers/IssuesController.php`

Add a domain-expiration counting block to **both**, following the codebase's newer, stricter convention
(an explicit `// KEEP IN SYNC with App\Support\IssueCounter::total().` comment directly above the block
in the controller, and the equivalent pointing back at the controller in `IssueCounter`) — the original
SSL block predates this per-block comment convention and relies only on a class-level docblock; new
categories should do better. Add `$totals['domain_expiration']` and pass the filtered collection to the
view, exactly matching `$sslIssues`'s pattern.

### `resources/docs/features/domain-expiration.md`

Match `ssl-cert-tracking.md`'s exact frontmatter shape (`title`, `section: Features`, `order` — pick a
number near the SSL doc's `order: 60`, e.g. `65`, `updated`, `author`, `tags`, `tracks`) and its section
structure (`## State machine`, `## Where to look`, `## Cadence`, `## Manual run`, `## What this isn't`).
**Make sure the doc's prose state names match the actual code constants exactly** — the SSL doc drifted
(names `expiring_soon`/`renewal_overdue` in prose vs. `yellow`/`red` in code); don't repeat that mistake
here.

## Composer dependency

```
composer require jeremykendall/php-domain-parser
```

## Config

Add an `rdap` block to `config/clockwork.php`, matching this codebase's existing convention of making
integration timeouts/rate-limits env-tunable rather than hardcoded (see the other per-service blocks
already in that file):
```php
'rdap' => [
    'timeout' => env('CLOCKWORK_RDAP_TIMEOUT', 10),
    'rate_limit_per_10s' => env('CLOCKWORK_RDAP_RATE_LIMIT', 10),
    'tld_backoff_hours' => env('CLOCKWORK_RDAP_TLD_BACKOFF_HOURS', 2),
],
```
Document these in `resources/docs/reference/env-vars.md`. Adding an entry to
`app/Support/ServiceRateLimitRegistry.php` (so the tunables are editable from the Settings UI like the
other ~25 registered services) is a nice-to-have, not required for the first version.

## Before considering this done

Run the full suite and confirm green: `./vendor/bin/pest`, `./vendor/bin/phpstan analyse`,
`./vendor/bin/pint --test`. Check `tests/Feature/ArchitectureTest.php` for any existing rule that might
need a new entry (e.g. if it asserts specific service/model shapes elsewhere in `app/Services/`).

## Verification & test plan

**Unit tests** (`tests/Unit/Services/Domains/`):
- `RdapClientTest` — mock HTTP responses for a healthy domain, a 404 (never-registered/typo domain), a
  429 (rate-limited), and a malformed-JSON response; assert `lookup()` returns `null` gracefully in every
  failure case and a correctly-parsed `RdapDomainResult` in the success case.
- Root-domain extraction test using `jeremykendall/php-domain-parser` against `sub.example.co.uk` →
  `example.co.uk`, proving the multi-part-TLD case the hand-rolled-regex approach would have broken on.
- `Site::domainExpirationState()` test covering all four branches (none/green/yellow/red) plus the
  redemption/pendingDelete override.

**Feature tests** (`tests/Feature/`):
- `CheckDomainExpirationsTest` — command run against a mixed fixture of sites (never checked, due for
  weekly recheck, due for daily recheck, not due yet) asserts only the due ones get an HTTP call (fake
  the RDAP HTTP calls, assert call count).
- Assert a transition from green→yellow fires exactly one `ChatNotifier` call, and repeated runs while
  still yellow don't re-fire.
- Assert `$silent = true` never fires a notification even on a real transition (backfill safety).
- Assert `IssueCounter::total()` and `IssuesController::index()`'s domain-expiration counts stay
  identical against the same fixture data (the sync contract, tested directly rather than just commented).
- `DomainExpirationController` recheck endpoint test — asserts it bypasses the cadence guard and returns
  the documented JSON shape.

**Manual verification**: run `php artisan clockwork:check-domain-expirations --silent` against a handful
of real fleet domains once merged, confirm `/issues` renders the new section correctly, confirm the
recheck button round-trips, confirm a deliberately-soon-to-expire test domain (or a manually-set past
`domain_expires_at` on a test fixture) renders red and fires an alert on the next non-silent run.
