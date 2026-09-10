---
title: Companion plugin
section: Architecture
order: 60
updated: 2026-09-09
author: Aaron Reimann
tags: [architecture, companion, wordpress, plugin, pressable]
tracks: [app/Services/Companion/**, modules/Pressable/src/**, ~/Projects/clockwork-companion/**]
---

Clockwork Companion is a WordPress mu-plugin that lives inside every monitored WP site. It does two jobs: it gives Clockwork signed REST endpoints to call (faster and cleaner than SSH+SQL), and it gives the *client* a wp-admin window into what Clockwork sees on their behalf.

The plugin source is its own repo at `~/Projects/clockwork-companion`. This page covers what it is, why it exists, and how it talks to Clockwork.

## Why it exists

Pre-Companion, Clockwork pulled WP data the only way available: SSH into the server, `mysql` to the DB, parse the result. That works, but it's intrusive and slow, and it's invisible to the client. Companion inverts the relationship:

- **For Clockwork** — signed REST calls instead of SSH+SQL. Faster, cleaner attribution, less intrusive.
- **For clients** — a Tools → Clockwork section in wp-admin showing what we monitor on their behalf, what scans we run, and what we found. Hosting-tier clients see what's running and what care plan would add. Care-plan clients see real data.

The two paths coexist. Where Companion is installed, we prefer signed REST. Where it isn't, the SSH+SQL fallbacks still run. Capability advertisement (`sites.companion_capabilities`) gates which call path is taken per feature.

## Where it lives

Mu-plugins are loaded automatically by WordPress on every request — there's no Activate/Deactivate UI for them. They appear under the **Must-Use** filter on `/wp-admin/plugins.php`, NOT the default Installed Plugins view. Don't expect to find Companion under "All / Active / Inactive."

Files on the WP server:

```
wp-content/mu-plugins/
  clockwork-companion.php       ← loader
  clockwork-companion/           ← source tree
    src/
    assets/
```

## Install path

Two installers share one tarball-acquisition step (`App\Services\Companion\CompanionTarballBuilder`) and converge on the same end state (`companion_installed`, `companion_version`, `companion_last_seen_at`), but the transport differs by hosting provider.

### SpinupWP — SSH

`App\Services\Companion\CompanionInstaller::installOrUpdate($site)`:

1. Acquire a tarball — either from `CLOCKWORK_COMPANION_DIST_URL` (production, sha256-pinned) or from the local source repo at `~/Projects/clockwork-companion` (dev, default).
2. Push base64-encoded over SSH to the site's `mu-plugins` dir.
3. Atomic swap: extract into `.clockwork-stage`, then `mv` the loader and source dir into place. Failure mid-extract leaves the previous version intact.
4. Push the per-site HMAC secret via `wp option update` (with a Redis-aware fallback that does a direct `INSERT ... ON DUPLICATE KEY UPDATE` against `{prefix}options` then `wp cache flush` — the alloptions cache can stall normal `wp option update` calls on SpinupWP boxes).
5. Probe `/health` to confirm the plugin loaded; record `companion_installed`, `companion_version`, `companion_last_seen_at`.

### Pressable — async command API

Pressable has no SSH. `Modules\Pressable\PressableCompanionInstaller` reaches the same end state over `PressableCommandRunner` (see [Integrations → Pressable](/docs/integrations/pressable) for how that turns Pressable's fire-and-forget command API into something synchronous):

1. Acquire the tarball via the same `CompanionTarballBuilder`.
2. **Order-independent chunked upload** — Pressable gives no ordering guarantee between queued commands, so the tarball is split into 45,000-byte chunks (below Pressable's ~50,000-byte serialized-command limit), written as zero-padded part files, then reassembled with a glob `cat`. Reassembly is gated on a sha256 comparison against the locally-computed hash, retried while the command queue drains.
3. **Bootstrap-then-rotate secret** — every Pressable command is logged verbatim in their control panel for ~30 days, so the long-lived HMAC secret never transits that channel. A throwaway bootstrap secret goes through the logged command, `/health` verifies the plugin loaded, then `rotateSecret()` replaces it with the real secret over authenticated HTTPS. Rotation failure downgrades to a loud warning, not a failed install.
4. **Edge-cache purge before the health probe** — `PressableClient::purgeEdgeCache()` runs unconditionally right before every `/health` call during install. Without it, a stale cached 404 from an earlier check (e.g. a manual pre-install probe) can mask a real pass even after the backend has the route.

The same problem shows up **after** install too, not just during it: Pressable's edge cache serves a stale cached GET response indefinitely once a URL has been hit once, and every subsequent read through that URL — `/health`, `/detect`, `/snapshot` — can keep returning what it returned the first time. Without purging, a site can show a plugin's pre-update version for an already-completed upgrade until the edge cache is cleared. `clockwork:refresh-companion-capabilities`, `clockwork:refresh-companion-snapshot`, and `clockwork:companion-canary-deploy`'s post-install verification step all purge edge cache (best-effort — a purge failure doesn't block the refresh) before reading, for exactly this reason. SpinupWP has no equivalent layer, so none of this applies there.
5. Docroot is fixed at `/srv/htdocs`. Staging happens under `mu-plugins/.cw-stage-<nonce>/` — docroot persistence across queued commands is proven; `/tmp`'s is not.

Driven by `clockwork:install-companion-pressable --site=...` (repeatable, honors the same policy denylist as the SSH path; `--force` overrides). No fleet-wide flag yet — deliberately, until the transport has more real-world runs behind it.

### Deployment policy denylist

Some domains must **never** receive the Companion mu-plugin regardless of care-plan or enrollment state — government / compliance-restricted sites where dropping a custom mu-plugin isn't permitted. The canonical example is `stateschools.example` (a state department of education (illustrative — genericized)).

`App\Support\CompanionExclusion` enforces this, backed by the `companion.excluded_domain_suffixes` setting so the list is editable without a code change. A suffix matches the exact domain **or any subdomain**: `stateschools.example` excludes `stateschools.example`, `community.stateschools.example`, and `www.careerpipeline.stateschools.example` alike. Every deploy path runs candidates through it:

- `clockwork:install-companion` — all paths, **including a single `--site`**, so a stray command can't hit an excluded domain. `--force` overrides, deliberately.
- `clockwork:companion-fleet-deploy` — excluded domains are always skipped; there's no override flag here. For a deliberate one-off, use `install-companion --site=X --force`.

Before this existed the exclusion lived only in team memory — nothing stopped someone running `install-companion --all-enabled`. If a new domain needs excluding, add its suffix to the setting; don't rely on remembering.

### Source modes

The two source modes shift the trust boundary:

- **`local_path`** (default; `CLOCKWORK_COMPANION_DIST_URL` empty) — tars `~/Projects/clockwork-companion` from the agency laptop on every install. Convenient but a compromised laptop ships malicious code to all 150 sites on the next install.
- **`dist_url`** — installer fetches a hosted tarball, verifies sha256 against `CLOCKWORK_COMPANION_DIST_SHA256` (REQUIRED when DIST_URL is set), then unrolls. Only signed, sha256-pinned tarballs reach a site. Build with `clockwork:build-companion-tarball`.

## Auth — HMAC-SHA256 with a 5-minute replay window

Per-site shared secret, 32 random bytes. Generated Laravel-side at install, pushed to WP's `wp_options`, mirrored encrypted into `sites.companion_secret`. Full details on [Security model](/docs/architecture/security-model) — including the `wp-config.php` constant alternative, the failure rate limit, and the audit log.

Every Companion request includes:

- `X-Clockwork-Signature` — hex SHA-256 HMAC of `METHOD\nPATH\nTIMESTAMP\nBODY`
- `X-Clockwork-Timestamp` — unix seconds

POST bodies use Laravel's `withBody($jsonBody, 'application/json')` to send the byte-exact JSON we signed. Re-encoding via `->post($url, $array)` would risk drift (key order, unicode escaping).

### Response decoding & prefix salvaging

All structured responses from Companion are decoded via `ClockworkCompanionClient::getJson()` and `postJson()`. When a site injects noise before its response (such as PHP notices, plugin output, or timezone-redirect stubs), the client attempts to salvage the payload by scanning for the first `{` or `[` character. If parsing fails entirely, it throws a clear `RuntimeException` with an excerpt of the raw body.

During installation, `CompanionInstaller` pushes the secret via `wp db query` and executes `wp cache flush` as a decoupled, best-effort command so transient cache-flush failures never block an otherwise successful install.

## Capabilities — per-site feature gates

`Plugin::CAPABILITIES` advertises what the plugin version supports. The current list (v1.34.0, the version bundled via `config('clockwork.companion.version')`):

```
contact-form-test, lockouts, wordfence-blocks, plugins, admins, wp-cron,
comments-summary, snapshot, backups-report, admin-ui, sso, updates,
action-log, security-scans, malware-scan, secret-rotate, auth-audit,
traffic-report, resource-sampler, resource-sampler-toggle,
form-subscriptions, lockouts-unlock, post-update-verify, two-factor,
white-label
```

Refreshed per-site daily by `clockwork:refresh-companion-capabilities` into `sites.companion_capabilities`. Clockwork-side commands cap-gate their work — a feature requiring `'sso'` skips sites where it isn't advertised, instead of getting a 404 from a too-old plugin.

Three of the newer app-side modules gate on capability strings the companion plugin repo has added on top of `code-snippets`, `comments-moderation`, and `maintenance-mode` — but that work hasn't been version-bumped/released past v1.34.0 yet, so it isn't in the list above. `code-snippets` (Code Snippets execution) and `comments-moderation` (Comment Moderation, checked by the weekly cleanup command) are checked app-side; `maintenance-mode` exists plugin-side but Site Maintenance doesn't check it — it gates only on `companion_installed`. Don't fleet-deploy Companion expecting these until a release picks them up.

## Routes

Read-only GETs (HMAC-signed):

- `/health`, `/detect` — liveness + capability detection
- `/snapshot` — composes `/plugins` + `/admins` + `/wp-cron` + `/comments-summary` in-process; cached 15 min into `sites.companion_snapshot` JSON column
- `/plugins`, `/admins`, `/wp-cron`, `/comments-summary` — granular reads
- `/lockouts`, `/wordfence-blocks` — what the SSH+SQL fallback used to read
- `/comments` — paginated, filterable comment listing for the Comment Moderation module (`ClockworkCompanionClient::comments()`)
- `/maintenance-mode` — current maintenance-mode status and config for the Site Maintenance module (`maintenanceMode()`)

Mutating POSTs (HMAC-signed):

- `/test-contact-form` — fire a marker-injected submission for the form-test add-on
- `/backups-report` — Clockwork pushes SpinupWP config + DO Spaces history, plus (as of the S3 Glacier archive enumerator) an `offsite_archive` field: presigned S3 download links for the site's off-host Glacier snapshots, resolved by `Modules\BackupRelay\Services\BackupArchiveEnumerator` and gated on `supportsPresignedUrls()` so a disk driver that can't mint a real presigned URL never hands the client-facing wp-admin page a dead-end link back to Clockwork's own (LAN-only) login screen
- `/action-log/append` — Clockwork mirrors every meaningful action so wp-admin can show it
- `/sso/magic-link` — mint a one-time URL the operator clicks to land in wp-admin as the named admin
- `/plugins/update` — single-slug WP plugin upgrade via `Plugin_Upgrader`
- `/secret/rotate` — rotate the per-site HMAC secret
- `/malware-scan` — run the in-WP malware probe (PHP-in-uploads, obfuscated-eval signatures, recently-touched wp-config). Returns findings as `{findings: [{kind, path, evidence}], scanned_at, scanned_files_count}`. Called nightly by `clockwork:run-companion-malware-scans`. Bypasses Cloudflare entirely — replaces SiteCheck's role on CF-fronted sites where Sucuri's external scanner gets 403'd at the edge. SSH wp-cli fallback exists for sites without the Companion installed.
- `/post-update-verify` — post-update state verification and repair (v1.21.3+). Clockwork POSTs the expected active-plugin list and active theme after every successful update. Companion compares against current WordPress state, re-activates any plugin that went inactive, restores the theme if it changed, and returns a `{ok, repairs: [{type, slug, detail}]}` payload. Gated on the `post-update-verify` capability; sites with older Companion versions skip this call silently.
- `/security-summary-report` — Pressable-only vulnerability alerts + Defensive Mode status (v1.31.6+). Clockwork pushes known plugin/theme CVEs (Pressable's own feed) and edge-cache Defensive Mode's on/off state; Companion stores it in `wp_options['clockwork_companion_pressable_security_summary']` and renders it on the Security admin page. Defensive Mode is status-only by design — no client-facing toggle. Called daily by `clockwork:pressable-security-summary-report`.
- `/comments/moderate` — bulk approve/hold/spam/trash/delete on one or more comment IDs (`moderateComments()`), driving the Comment Moderation dashboard tab
- `/comments/cleanup` — purge spam and trash comments older than N days (`cleanupComments()`); called weekly by `clockwork:cleanup-spam-comments`
- `/maintenance-mode` — enable/disable maintenance mode with an optional custom title, message, and bypass secret key (`setMaintenanceMode()`)
- `/code-snippet` — execute a snippet of PHP in a sandboxed, output-buffered context and return its output, return value, and timing (`executeCodeSnippet()`); powers the Code Snippets workbench

## Snapshot cache

The `sites.companion_snapshot` JSON column holds the last `/snapshot` payload. Refreshed nightly at 03:00 by `clockwork:refresh-companion-snapshot`, and on-demand per-site after any update batch from the Updates page (via `RefreshSnapshotsAfterBatch`). UI reads from the cached column, never live — so an offline site shows yesterday's data with a timestamp instead of a spinner.

The Issues "WordPress plugins out of date" section reads `companion_snapshot.plugins.counts.updates_available > 0` via `JSON_EXTRACT()`.

## Push pattern (Round 1.5+)

Several Companion routes accept Clockwork-pushed data so the plugin can render it in wp-admin without making outbound calls of its own. The `/backups-report` and `/action-log/append` routes are the workhorses. The pattern:

- Clockwork is the source of truth (it computed the data).
- Companion stores the latest payload in `wp_options` (autoload off).
- The plugin's admin pages read the option and render. No outbound calls.

Result: Companion can show backup history, scan history, performance scans, and uptime data without ever needing to reach Clockwork's home LAN.

## SSO

`POST /sso/magic-link` mints a 192-bit nonce, stores it in `wp_options[clockwork_sso_<nonce>]` with a 60-second TTL, and returns a `?clockwork_sso=<nonce>` URL. Clockwork redirects the operator's tab to it. Companion's `Sso\Interceptor` runs on `init` priority 1, validates and **deletes** the option (one-time semantics), then `wp_set_auth_cookie($userId, false)` + `wp_safe_redirect`.

Skips MFA — same as `wp-cli user create-session`. None of our agency clients have 2FA configured today; revisit when one does.

## White Labeling & Feature Gating

- **White Labeling hub (v1.33.0+, consolidated in v1.4.0, upgraded in v1.6.5)**: Configured under `/settings/companion` (the Agency Branding pillar). Features a Master Agency Brand Palette cascading primary and accent colors across Companion (wp-admin), Client Reports, and Notification Emails. Includes dedicated `<x-color-picker>` Blade components, two-tone header contrast (`CompanionBrandingManager::deriveMediumTone` and `deriveSoftColor`), separated `brand_text` (admin header bar) vs `menu_title` (sidebar menu), and strict logo validation (`png, jpg, jpeg, webp` — rejecting SVG) with automatic disk cleanup. `/settings/wordpress-plugins` is a **separate, unrelated page** under Fleet Policies. See [Features → Companion Branding](/docs/features/companion-branding).
- **Traffic Tab Visibility**: Managed via `Site::canViewCompanionTraffic()`, conditionally hiding the Traffic tab in client wp-admin when SSH access or traffic rollups are unavailable on the host.

## Where to find each piece

- Plugin: `~/Projects/clockwork-companion/`
- Plugin entry: `clockwork-companion.php` (loader) + `src/Plugin.php`
- Installers: `app/Services/Companion/CompanionInstaller.php` (SSH) + `modules/Pressable/src/PressableCompanionInstaller.php` (Pressable async commands)
- Shared tarball acquisition: `app/Services/Companion/CompanionTarballBuilder.php`
- HTTP client: `app/Services/Companion/ClockworkCompanionClient.php`
- Config: `config/clockwork.php` → `companion` key (+ `pressable` key for the transport)
- Site columns: `companion_installed`, `companion_version`, `companion_capabilities`, `companion_secret` (encrypted), `companion_last_seen_at`, `companion_snapshot` (JSON)
- UI: `resources/views/dashboard/site/tab-settings.blade.php`, `resources/views/dashboard/issues.blade.php`
