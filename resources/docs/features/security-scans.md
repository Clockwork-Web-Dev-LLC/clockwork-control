---
title: Security scans
section: Features
order: 40
updated: 2026-09-08
author: Aaron Reimann
tags: [security, scans, sucuri, blacklist, checksums, allowlist, care-plan, wordpress-7, pressable, modules]
tracks: [app/Services/Security/**, modules/Sucuri/src/**, app/Console/Commands/{ScanSiteCheck,CheckBlacklists,VerifyWpCoreChecksums,PressableSecuritySummaryReport}.php, app/Models/SiteCoreChecksumAllowlist.php, modules/Pressable/src/**, app/Http/Controllers/SecurityScansController.php, app/Http/Controllers/SecurityScansSettingsController.php]
---

Four scan types, one table. `site_security_scans` is polymorphic on `scan_type` ∈ `sitecheck | core_checksums | blacklist | companion_malware`. A coarse `status` (`clean | warning | issues_found | failed`) drives every dashboard regardless of which scan ran.

## What we run

| Scan | Cadence | Tier | What it catches |
|---|---|---|---|
| **Sucuri SiteCheck** | daily 02:00 | care-plan | Remote malware + blacklist hits via Sucuri's public API (the same engine ManageWP resold). Decoupled as `clockwork/sucuri` (`modules/Sucuri`). |
| **Domain blacklists** | daily 02:15 | hosting | Spamhaus DBL + URLhaus + optional Google Safe Browsing. Recovers the blacklist signal Sucuri loses when CF 403's its scanner. |
| **WP core checksums** | daily 02:30 | care-plan | `wp core verify-checksums` — catches base64 / shell backdoors dropped into wp-includes / wp-admin that Sucuri can't see (because they're not in the public HTML). |
| **Companion malware** | daily 02:45 | care-plan | In-WP probe (Companion endpoint preferred, SSH fallback). PHP files >30 bytes under `wp-content/uploads/` (skipping the standard 0-byte and "Silence is golden" stubs that legit plugins drop), obfuscation signatures (`eval(base64_decode(`, `eval(gzinflate(`, `c99shell`, `r57shell`, `WSOsetcookie`, `FilesMan`), and recently-modified `wp-config.php`. Bypasses Cloudflare so CF-fronted sites get real signal. |

Sucuri + checksums + companion-malware are care-plan-only. Blacklist runs against every site (it's the highest-leverage scan we have, and the API costs are zero).

## Where to look

- **`/security/scans`** — fleet inventory of latest scan per site.
- **`/sites/{id}/security`** — per-site security tab. Latest scan per type plus a **Recent scans** history table at the bottom. History rows are expandable — clicking the chevron shows the structured findings (malware: kind/path/evidence; checksums: Modified / Missing / Unexpected file lists).
- **Issues page** — sites with `status=issues_found` surface here. The **Core file tampering** card links directly to `/sites/{id}/security#core-integrity` so clicking a site name lands exactly at the Core file integrity card. There is also a **Companion malware findings** section (latest scan per site, care-plan gated, counted in the nav badge) ensuring operators are alerted proactively on the dashboard whenever malware findings are detected.
- **Companion → Tools → Clockwork → Security** — what the client sees in their wp-admin. Same data, friendlier copy.

## `/security/scans` — fleet inventory (`SecurityScansController`)

The index inventory queries all monitored sites via `Site::query()->hostMonitored()`. This encompasses both server-hosted sites (SpinupWP, Cloudways) and serverless managed hosts (Pressable, WP Engine, Kinsta). For serverless sites where `server_id` is null, the Server column displays the hosting provider badge (e.g. `<i class="fa-solid fa-cloud"></i> Pressable`) and sorts by provider name.

The summary tiles are computed across this fleet, split by `care_plan_enabled` since that's the population actually scanned on a recurring basis:

| Tile | What counts |
|---|---|
| Malware / blacklist hit | Latest Sucuri scan has `has_malware_hit` or `blacklist_hit`, care-plan sites only |
| Checksum tampering | Latest checksum scan is `issues_found` **and** not fully covered by that site's allowlist — `CoreChecksumAllowlist::suppressedSiteIds()` filters out sites where every flagged path has been allowlisted |
| Failed | Latest Sucuri **or** checksum scan is `status=failed`, care-plan sites only |
| Never scanned | Care-plan site with no Sucuri scan and no checksum scan on record at all |

Each red tile is backed by an "affected sites" list (names + links) so an operator can jump straight to the offending site instead of scanning the whole table. The tampering-suppression logic here has a `KEEP IN SYNC` comment pairing it with `App\Support\IssueCounter::countOpenIssues()` — the fleet tile count and the `/issues` badge use the same allowlist-aware rule and are expected to always agree.

**Run-now** (`POST /security/scans/{site}/run`) accepts an optional `type` (`sitecheck` or `core_checksums`; omit for both) and runs synchronously in-request so the new scan row is visible immediately — both scanners are fast enough per-site that this doesn't need queuing. The redirect honors the request's `referer`, so triggering a re-scan from the per-site Security tab lands you back on that tab instead of bouncing to the fleet table. Checksum verification is skipped automatically for non-WordPress sites.

The **file viewer** (`GET /security/scans/{site}/file?path=...`) and **allowlist actions** (`POST`/`DELETE /security/scans/{site}/allowlist`) that power the View / Allow buttons described below are also served by this controller — see "Core-checksum allowlist (clearing benign findings)" further down this page.

## `/settings/security-scans` — schedule + run-now (`SecurityScansSettingsController`)

Toggle panel for the three commands that respect a `security_scans.{key}_enabled` gate — Sucuri, checksums, and blacklist. Companion malware isn't listed here (it has its own `run-companion-malware-scans` schedule entry and Settings key but no page-level toggle UI yet). For each source the page shows: enabled/disabled, a plain-English cadence label, and a **Run now** button that dispatches the same artisan command via `Artisan::queue()` (async — the page redirects immediately with a "queued" flash rather than blocking).

**Known display bug**: the Sucuri row's cadence label reads "Weekly Mondays 02:00 UTC" — that's stale copy from before the schedule was switched to daily. The actual schedule (`routes/console.php`) runs `clockwork:scan-sitecheck` **daily** at 02:00 UTC, matching the table above and matching the other two sources' real cadence. The toggle and run-now button both work correctly; only the label text is wrong. Fix is a one-line string change in `SecurityScansSettingsController::SOURCES` whenever someone's in that file next.

## Alerting on new findings

`SecurityScanRecorder` (the single chokepoint every scan type's result flows through — see `app/Services/Security/SecurityScanRecorder.php`) fires `ChatNotifier::malwareFindingDetected()` on the **clean → malware-hit transition only**: it checks the immediately-preceding scan of the same type for the same site and only alerts if that one was clean and this one isn't, so a site that remains in an issue state doesn't repeatedly spam alerts on every run. Same transition-based suppression shape as SSL-state and uptime-transition alerts. See [Integrations → Mattermost](/docs/integrations/mattermost) / [Slack](/docs/integrations/slack) for the toggle.

## Manual run

```bash
# One site, all configured scans for that site:
php artisan clockwork:scan-sitecheck --site=42
php artisan clockwork:check-blacklists --site=42
php artisan clockwork:verify-wp-core-checksums --site=42

# Or via the UI:
# /settings/security-scans → Run now
# /security/scans/{site}/run → per-site button
```

## Toggles

`/settings/security-scans` exposes a toggle per scan type. Disabling makes the scheduled command a no-op (it's gated via `Settings('security_scans.{type}_enabled')`). Useful for muting one scan without removing it from the schedule. See "`/settings/security-scans` — schedule + run-now" above for what the page actually shows.

## Status meanings

- **`clean`** (green) — nothing flagged.
- **`warning`** (yellow, "Review") — only informational findings present. Doesn't count toward the red **Issues** badge in the nav. The set of warning-only kinds lives in `CompanionMalwareScanner::WARNING_KINDS`:
  - `wp_config_recently_modified` — wp-config.php mtime within 30 days; operator should confirm the change was authorized.
  - `php_in_uploads` — PHP file found under `wp-content/uploads/`. Legitimately placed by plugins like Smush (log files), so yellow rather than red. Still worth a glance to confirm the path is from a known plugin.
  - `preg_replace_e_modifier` — the `/e` modifier was a historic backdoor technique, but was **removed in PHP 7.0**, so on modern PHP fleets a match is dead version-guarded code. In practice it only flags legacy vendor code. A genuine backdoor also trips the `eval_*`/`shell_*` signatures, which stay `issues_found`.
- **`issues_found`** (red) — at least one real-malware finding (PHP backdoor in uploads, obfuscation signature, etc.). The `details` JSON has the structured verdict. Drives the Issues page.
- **`failed`** (yellow, "Failed") — couldn't run. Common cause for Sucuri: CF WAF 403'd the scanner. Companion's Security page recognises this shape and renders "Blocked by site firewall (likely Cloudflare)" instead of a red alarm. The blacklist scan covers the gap.

## Non-SSH transports for core checksums

`WpCoreChecksumVerifier::verify()` branches on `! $site->host()->supports(HostingProvider::CAP_SSH)` before touching root SSH — a capability check rather than the older direct `$site->isPressable()` call. `CAP_SSH` means "root/sudo SSH against a tracked `Server` row" — that's SpinupWP, GridPane, and Cloudways today (each backed by a real `servers` row with `hostname`/`ssh_user`/`ssh_password`), not just SpinupWP; everything else — Pressable, WP Engine, Kinsta — is `CAP_SSH=false` and splits three ways:


- **SpinupWP / GridPane / Cloudways** (`CAP_SSH=true`) — root SSH + sudo-to-site_user, the original path. The on-disk WordPress root comes from `Site::resolveWpPath()`: the recorded `wp_path` if one's set, otherwise a per-provider convention (`/sites/{domain}/files` for SpinupWP, `/var/www/{domain}/htdocs` for GridPane). A site with no `wp_path` on a provider with no known convention (Cloudways today) fails cleanly with `status=failed` / `error=unknown_wp_path` rather than guessing at a path and silently scanning the wrong directory.
- **Pressable** (`$site->isPressable()`, checked first since its quirks are genuinely Pressable-specific, not capability-based) — `wp core verify-checksums` via `PressableCommandRunner`, `cd /srv/htdocs && wp core verify-checksums` (Pressable's wp-cli shim rejects `--path=`, confirmed live).
- **Everything else without `CAP_SSH`** (WP Engine, Kinsta) — `verifyViaCommandRunner()`, the generic path for a hosting provider with real per-site SSH but no `Server` row and no root/sudo layer. Uses `$site->host()->commandRunner()` (the `SiteCommandRunner` abstraction) directly, no `cd` needed since a genuinely per-site-scoped SSH gateway already lands you in the site's WordPress root — unlike Pressable's fixed-docroot async command layer. **Unverified against a live WP Engine/Kinsta account** — the working-directory assumption is a reasonable default, not a confirmed fact; see [Integrations → WP Engine](/docs/integrations/wp-engine) / [Kinsta](/docs/integrations/kinsta).

Output parsing (the modified / missing / should-not-exist regex logic + allowlist filtering) is shared across all three transports via a `parseAndRecord()` helper, so a scan looks identical on any provider from the Security tab.

**One real caveat**: Pressable's command-runner output is `tail -c 700`'d (the activity log truncates around ~1KB total), so a severely compromised Pressable site with a long findings list may only show its last few. A clean or lightly-flagged site is unaffected — this only matters for exact enumeration on a badly-compromised site, and the `issues_found` verdict itself still fires correctly either way.

`clockwork:verify-wp-core-checksums`'s site-selection query uses `Site::hostMonitored()` (not the old SpinupWP-only server scope), so the nightly fleet-wide run reaches care-plan Pressable sites too.

## Pressable-only: vulnerability alerts + Defensive Mode

Two Pressable-native signals with no SpinupWP equivalent, pushed daily (06:39) by `clockwork:pressable-security-summary-report` to each Companion-equipped Pressable site's `/security-summary-report` endpoint, and rendered on Companion's Security admin page (v1.31.6+):

- **Known plugin/theme vulnerabilities** — Pressable's own CVE feed (`sitePluginSecurityAlerts()` / `siteThemeSecurityAlerts()` on `PressableClient`), independent of the wpvulnerability.net mirror used elsewhere.
- **Defensive Mode status** — a read-only on/off + expiry line for Pressable's self-expiring aggressive edge-cache mode (built for scraper/DDoS spikes). Status-only by design: Clockwork doesn't expose a client-facing toggle for it, and ops-side triggering is a deferred idea, not built yet.

Gated on the site advertising the `security-scans` Companion capability — no new capability was added for this, it reuses the existing gate.

**Correction worth knowing if you're looking at Pressable's firewall tools**: `list_site_firewall_rules` / `create_site_firewall_rule` etc. are **egress-only** — an allowlist for what the site can connect *out* to, not an inbound WAF or ban system. Pressable's API has no inbound-threat-blocking equivalent at all (checked via `discover_tools`: zero results for "waf" or "ban"). That's why the Bans tab is hidden rather than replaced for Pressable sites — see the next section.

## Hidden, not broken: SSH-only surfaces on Pressable sites

A handful of things on the per-site pages are pure SSH/nginx-log artifacts with no Pressable equivalent, and are hidden outright rather than rendered empty or broken:

- Overview's "Recent threat log" / "Top IPs (24h)" cards and the active-bans card — nginx-tailer-sourced.
- The Traffic tab (this app's own tab, not Companion's — see [Features → Traffic + capacity](/docs/features/traffic-and-capacity)) and the Bans tab.
- Settings tab's "Install LLAR" button and cert "Recheck now" button — both would hard-fail without SSH.
- Overview's SpinupWP-inventory WP-update pills (`wp_core_update` etc.) — always false for Pressable regardless of real update state; the real numbers live on `/updates`, sourced from Companion's snapshot instead.

`$site->isPressable()` gates all of these in the relevant Blade templates (`tab-nav.blade.php`, `tab-overview.blade.php`, `tab-settings.blade.php`) and `SitesController::show()` falls back a direct/bookmarked `?tab=traffic` or `?tab=bans` hit to Overview for Pressable sites.

## Core-checksum allowlist (clearing benign findings)

`wp core verify-checksums` flags every file that doesn't match the official WordPress core manifest. A lot of those flags are benign — security plugins drop hardening files into `wp-admin/`, hosts (CloudLinux/Imunify, SpinupWP) add their own `.htaccess` shims, etc. Without a way to clear these, the Issues page nags forever about files we've already eyeballed and approved.

### How it works

Each finding has three parts: **path**, **bucket** (`modified | missing | unexpected`), and **scan ID**. The allowlist key is `(site_id, path, bucket)` — same path in a different bucket still flags, since "missing core file" and "unexpected file at the same path" are different stories.

Allowlist entries live in `site_core_checksum_allowlist` and are applied at **display time** by `App\Services\Security\CoreChecksumAllowlist`. The raw `site_security_scans.details` JSON is never mutated — if you remove an entry from the allowlist, the original finding reappears on the next page render. That's deliberate: the audit trail of what wp-cli actually said stays intact.

The same service powers both the per-site security tab AND the fleet `/issues` filter, so the count in the nav badge matches the rows on the page. (Drift between the two is a recurring footgun — `IssueCounter::total()` and `IssuesController::index()` carry a `KEEP IN SYNC` comment for this reason.)

### From the UI

On `/sites/{id}/security`, every flagged file row now has:

- **View** — opens `/security/scans/{site}/file?path=...` which SSHs to the server, reads the file (capped at 64 KB), and shows you `ls -la`, mtime, size, and contents. Path is validated against the latest scan's bucket lists, so the viewer can't be coerced into reading `/etc/passwd`. Missing-bucket files don't get a View button (nothing to read).
- **Allow** — adds `(site, path, bucket)` to the allowlist. The inline button has no reason field; use the View page to allowlist with a reason (highly recommended — future-you wants to know why).

Allowlisted findings move into a collapsed *Allowlisted findings* section on the security tab. The card's status pill flips to **Clean (allowlisted)** once every finding is suppressed, and the site disappears from `/issues`.

### From CLI

```php
\App\Models\SiteCoreChecksumAllowlist::create([
    'site_id' => $site->id,
    'path' => 'wp-admin/.htaccess',
    'bucket' => \App\Models\SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED,
    'reason' => 'Imunify360 hardening file, confirmed benign',
]);
```

`bucket = '*'` matches any bucket for that path — useful for "this file is always fine no matter what wp-cli decides to call it today," but you lose the ability to catch when the same path legitimately starts showing up in a different category. Prefer bucket-specific entries.

### When NOT to allowlist

Allowlist only after you've **looked at the file** (use the View button) and convinced yourself it's benign. Things that look like malware:

- PHP files in `wp-includes/` or `wp-admin/` you don't recognize (especially `*.php` files dropped at unusual paths)
- `.htaccess` rules that **redirect** outbound (anything with `RewriteRule .*$ http://`)
- Files containing base64-encoded strings, `eval(`, `gzinflate(`, `str_rot13(`, or `\x` escape sequences
- Recently modified (`stat` mtime within the last week) when nobody on the team has touched the site

Things that are usually fine:

- `wp-admin/.htaccess` with `<FilesMatch>` rules and `Options -Indexes` (security plugin or host hardening)
- `wp-content/object-cache.php` (Redis/Memcached drop-in)
- `index.php` redirects added by SpinupWP or other PaaS providers

When in doubt, ask the host: SpinupWP support will tell you in two minutes whether they put it there.

## Why the table is polymorphic

Adding plugin / theme checksum scans, DB-malware indicators, or any other Phase-2 scan type is a new constant on `SiteSecurityScan` plus a new artisan command. No new migration, no new table, no new Issues-page section. The polymorphism is a deliberate bet that "we'll keep adding scan types but the surface UI shape stays the same."

## What's NOT a security scan

- **SSL state** is its own thing — see [Features → SSL cert tracking](/docs/features/ssl-cert-tracking).
- **WordPress plugin updates** are inventory, not a scan — see [Features → WordPress plugin inventory](/docs/features/wordpress-plugin-inventory).
- **Composer dependency CVEs** for the Clockwork app itself run weekly via `clockwork:composer-audit`. Surfaces in the operator log, not in `site_security_scans`.

## Allowlist stamps clean at save time

`SecurityScanRecorder` applies the `core_checksum_allowlist` when writing a `core_checksums` scan row. If every finding in the scan is covered by the site's allowlist, the row is saved with `status=clean` and a transparent summary noting that every finding was allowlisted. The raw findings still ship into `details` for audit — only the verdict reflects post-allowlist truth.

Before this change, the recorder saved the raw `issues_found` verdict and the Security tab's card showed "Clean (allowlisted)" green pill while the history table below it showed "Issues found" red — two different stories from the same data. Now they match.

## Core-checksum hardening-file tolerance

`WpCoreChecksumVerifier` treats absence of `license.txt`, `readme.html`, and `wp-config-sample.php` as **clean** by default. These disclosure files leak the WordPress version and are routinely removed by Wordfence, iThemes, Sucuri, and host hardening guides. Their absence isn't tampering signal — the checksum scan is meant to catch malware-injected code in `wp-includes/` and `wp-admin/`.

If the **only** findings from `wp core verify-checksums` are those three files, the scan records `status=clean`. Any other finding (modified core file, unexpected PHP in `wp-admin/`) still triggers `issues_found` as normal.

## WordPress version-aware "unexpected file" suppression

`WpCoreChecksumVerifier::KNOWN_SAFE_UNEXPECTED_PREFIXES` lists path prefixes for files that ship as part of WordPress core but that WP-CLI's checksum manifest hasn't been updated to include yet. Files matching any prefix are silently filtered from the `should_not_exist` bucket before the scan verdict is recorded — no per-site allowlist entry needed.

**Current entries:**

| Prefix | Added in | Why suppressed |
|---|---|---|
| `wp-includes/php-ai-client/` | WP 7.0 | PHP AI Client SDK bundled into core ([make.wordpress.org announcement](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)). WP-CLI's `api.wordpress.org/core/checksums/1.0/` manifest doesn't yet include these files for the 7.0 release, causing false-positive "should not exist" warnings for every WP 7.0 site. |

When WP-CLI ships an updated checksum manifest that covers the suppressed files, remove the corresponding entry from `KNOWN_SAFE_UNEXPECTED_PREFIXES` — the filter is meant to be temporary, not a permanent bypass.

**To add a new entry** (e.g., when WP 7.1 bundles something else that WP-CLI lags on):
```php
// WpCoreChecksumVerifier.php
private const KNOWN_SAFE_UNEXPECTED_PREFIXES = [
    'wp-includes/php-ai-client/',
    'wp-includes/new-bundled-thing/', // WP 7.1 — remove when WP-CLI manifest catches up
];
```

The suppressed count is still recorded in the scan's `details.ignored_unexpected` array and referenced in the scan summary ("known-safe additions: N wp-includes/php-ai-client file(s)"), so there's a full audit trail even though the verdict is `clean`.

## Gotchas

- **The malware scanner must exclude itself.** The signature needles (`eval(base64_decode(`, `c99shell`, …) exist as literal strings inside Companion's own `MalwareScanner.php`, so both scan paths exclude the `clockwork-companion` directory — the Companion-side scanner natively, and the SSH-transport grep via `--exclude-dir=clockwork-companion`. Without it every Companion-equipped docroot would self-flag findings. PHP-in-uploads (check 1) keeps no exclusion.
- **`echo` not `printf %s`** when feeding sudo's stdin password during checksum runs. `printf %s` (no trailing newline) makes sudo wait on EOF and `wp` exits silently with empty output. The plugin detector accidentally hides this via a trailing `| grep ... || true`; the security verifier doesn't, so it must use `echo`.
- **Sucuri rate-limited around 30 req/min.** The artisan loop sleeps 250 ms between sites. A 60-site care-plan run takes about half a minute on top of the per-site scan time.
- **Care-plan toggle drives Sucuri + checksums automatically.** When Bill.com flips a site off care plan, those scans stop running for it on the next cycle. Blacklist keeps going (hosting tier).
- **wp-cli phrasing changed.** Older wp-cli said `File is missing:`; newer says `File doesn't exist:`. The verifier matches both forms — don't be surprised if you see either in raw `wp core verify-checksums` output on the server.
