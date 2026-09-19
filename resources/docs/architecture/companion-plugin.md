---
title: Companion plugin
section: Architecture
order: 60
updated: 2026-09-18
author: Aaron Reimann
tags: [architecture, companion, wordpress, plugin, pressable, standalone, backups]
tracks: [app/Services/Companion/**, modules/Pressable/src/**, ~/Projects/clockwork-companion/**, app/Http/Controllers/CompanionDownloadController.php]
---

Clockwork Companion is a WordPress plugin (supporting both mu-plugin and standard plugin modes) that lives inside every monitored WP site. It does two jobs: it gives Clockwork Control signed REST endpoints to call (faster and cleaner than SSH+SQL), and it gives the *client* a wp-admin window into what Clockwork Control sees on their behalf.

The plugin source is its own repo at `~/Projects/clockwork-companion`. This page covers what it is, why it exists, and how it talks to Clockwork Control.

## Why it exists

Pre-Companion, Clockwork Control pulled WP data the only way available: SSH into the server, `mysql` to the DB, parse the result. That works, but it's intrusive and slow, and it's invisible to the client. Companion inverts the relationship:

- **For Clockwork Control** — signed REST calls instead of SSH+SQL. Faster, cleaner attribution, less intrusive.
- **For clients & site administrators** — a top-level sidebar menu (or white-labeled custom menu) visible to all users with `manage_options`. Shows what we monitor on their behalf (Connection status, Capabilities, Notifications, and Two-Factor Authentication). Hosting-tier clients see what's running and what care plan would add. Care-plan clients see real data. Sensitive agency tools (such as the LLAR Unlock Hub) remain strictly isolated and hidden.

The two paths coexist. Where Companion is installed, we prefer signed REST. Where it isn't, the SSH+SQL fallbacks still run. Capability advertisement (`sites.companion_capabilities`) gates which call path is taken per feature.

## Where it lives

When pushed via SSH (SpinupWP) or command runner (Pressable), Companion lives as a **Must-Use plugin** under `wp-content/mu-plugins/` and is loaded automatically. When uploaded manually to standalone sites (WP Engine, Kinsta, custom hosting), it operates as a standard active plugin under `wp-content/plugins/clockwork-companion/`.

Files on the WP server:

```
wp-content/mu-plugins/ (or wp-content/plugins/)
  clockwork-companion.php       ← dual loader
  clockwork-companion/           ← source tree
    src/
    assets/
```

### Variants: Companion vs Renegade

Clockwork Control supports two distinct plugin variants (tracked per-site in `sites.companion_variant`):
- **`companion` (Private Mu-Plugin / Active Plugin)**: Private distribution (`~/Projects/clockwork-companion`), REST route `/wp-json/clockwork/v1/`, supports internal operations including arbitrary code execution for recovery (`CodeSnippetRoute`).
- **`renegade` (WordPress.org Plugin Directory)**: Public open-source distribution (`~/Projects/clockwork-renegade`), GPL-2.0-or-later, REST route `/wp-json/clockwork-renegade/v1/`. Strictly compliant with WordPress.org guidelines: no arbitrary code execution / remote `eval()`, updates via WordPress.org SVN, full affirmative consent pairing screen, and complete cleanup on uninstall. See [Clockwork Renegade](/documentation/features/clockwork-renegade) for full details.

## Install path

Four installation paths share common secret management:

### 1. Standalone / Unmanaged Hosts (WP Engine, Kinsta, Custom) — Direct ZIP Upload

For sites hosted on platforms where Clockwork Control does not have server-level API keys or SSH access:
1. **Download Compiled ZIP Package**: Operators download the pre-packaged plugin directly from Clockwork Control via `/companion/download` (`CompanionDownloadController::downloadZip()`).
2. **Standard WordPress Install**: Upload and activate `clockwork-companion.zip` via standard WP Admin (`Plugins -> Add New -> Upload Plugin`).
3. **One-Click 256-Bit Connection Key Pairing**: Navigate to **Clockwork → Connection** (`admin.php?page=clockwork-connection`) for Renegade, or **Tools → Clockwork Control** for Companion in WP Admin, and click **Copy Connection Key**. This key encodes the site URL and a 256-bit cryptographically secure secret (`random_bytes(32)`).
4. **Enroll in Clockwork Control**: On Clockwork Control's **Sites** page, click **+ Add Site**, paste the base64 Connection Key, and confirm. Clockwork Control decodes the URL, variant, and 256-bit HMAC secret, verifies `/health` connectivity, and enrolls the site under the `custom` provider. Validation errors never flash the secret or Connection Key back into the session or the form. Zip generation failures on `/companion/download` return a generic 500 — the builder exception stays in logs.

### 2. SpinupWP — SSH

`App\Services\Companion\CompanionInstaller::installOrUpdate($site)`:

1. Acquire a tarball — either from `CLOCKWORK_COMPANION_DIST_URL` (production, sha256-pinned) or from the local source repo at `~/Projects/clockwork-companion` (dev, default).
2. Push base64-encoded over SSH to the site's `mu-plugins` dir.
3. Atomic swap: extract into `.clockwork-stage`, then `mv` the loader and source dir into place. Failure mid-extract leaves the previous version intact.
4. Push the per-site HMAC secret via `wp option update` (with a Redis-aware fallback that does a direct `INSERT ... ON DUPLICATE KEY UPDATE` against `{prefix}options` then `wp cache flush` — the alloptions cache can stall normal `wp option update` calls on SpinupWP boxes).
5. Probe `/health` to confirm the plugin loaded; record `companion_installed`, `companion_version`, `companion_last_seen_at`.

### 3. Pressable — async command API

Pressable has no SSH. `Modules\Pressable\PressableCompanionInstaller` reaches the same end state over `PressableCommandRunner` (see [Integrations → Pressable](/documentation/integrations/pressable) for how that turns Pressable's fire-and-forget command API into something synchronous):

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

Per-site shared secret, 32 random bytes. Generated Laravel-side at install, pushed to WP's `wp_options`, mirrored encrypted into `sites.companion_secret`. Full details on [Security model](/documentation/architecture/security-model) — including the `wp-config.php` constant alternative, the failure rate limit, and the audit log.

Every Companion request includes:

- `X-Clockwork Control-Signature` — hex SHA-256 HMAC of `METHOD\nPATH\nTIMESTAMP\nBODY`
- `X-Clockwork Control-Timestamp` — unix seconds

POST bodies use Laravel's `withBody($jsonBody, 'application/json')` to send the byte-exact JSON we signed. Re-encoding via `->post($url, $array)` would risk drift (key order, unicode escaping).

### Response decoding & prefix salvaging

All structured responses from Companion are decoded via `ClockworkCompanionClient::getJson()` and `postJson()`. When a site injects noise before its response (such as PHP notices, plugin output, or timezone-redirect stubs), the client attempts to salvage the payload by scanning for the first `{` or `[` character. If parsing fails entirely, it throws a clear `RuntimeException` with an excerpt of the raw body.

During installation, `CompanionInstaller` pushes the secret via `wp db query` and executes `wp cache flush` as a decoupled, best-effort command so transient cache-flush failures never block an otherwise successful install.

## Capabilities — per-site feature gates

`Plugin::CAPABILITIES` advertises what the plugin version supports. The current list (v1.38.1+, the version bundled via `config('clockwork.companion.version')`):

```
contact-form-test, lockouts, wordfence-blocks, plugins, admins, wp-cron,
comments-summary, snapshot, backups-report, admin-ui, sso, updates,
action-log, security-scans, malware-scan, secret-rotate, auth-audit,
traffic-report, resource-sampler, resource-sampler-toggle,
form-subscriptions, lockouts-unlock, post-update-verify, two-factor,
white-label, comments-moderation, maintenance-mode, code-snippets, cache-flush,
backup-create, backup-restore, update-exceptions
```

Refreshed per-site daily by `clockwork:refresh-companion-capabilities` into `sites.companion_capabilities`. Clockwork Control-side commands cap-gate their work — a feature requiring `'sso'` skips sites where it isn't advertised, instead of getting a 404 from a too-old plugin. Site Maintenance still gates only on `companion_installed`, not the `maintenance-mode` capability.

## Routes

Read-only GETs (HMAC-signed):

- `/health`, `/detect` — liveness + capability detection
- `/snapshot` — composes `/plugins` + `/admins` + `/wp-cron` + `/comments-summary` in-process; cached 15 min into `sites.companion_snapshot` JSON column
- `/plugins`, `/admins`, `/wp-cron`, `/comments-summary` — granular reads
- `/lockouts`, `/wordfence-blocks` — what the SSH+SQL fallback used to read
- `/comments` — paginated, filterable comment listing for the Comment Moderation module (`ClockworkCompanionClient::comments()`)
- `/maintenance-mode` — current maintenance-mode status and config for the Site Maintenance module (`maintenanceMode()`)

Mutating Requests (HMAC-signed POST / DELETE):

- `DELETE /lockouts` — flush Limit Login Attempts Reloaded (LLAR) lockouts for an IP or username directly from the designated Agency Primary Hub console (`lockouts-unlock` capability)
- `/test-contact-form` — fire a marker-injected submission for the form-test add-on
- `/backups-report` — Clockwork Control pushes SpinupWP config + DO Spaces history, plus (as of the S3 Glacier archive enumerator) an `offsite_archive` field: presigned S3 download links for the site's off-host Glacier snapshots, resolved by `Modules\BackupRelay\Services\BackupArchiveEnumerator` and gated on `supportsPresignedUrls()` so a disk driver that can't mint a real presigned URL never hands the client-facing wp-admin page a dead-end link back to Clockwork Control's own (LAN-only) login screen
- `/update-exceptions` — Clockwork Control pushes the active list of auto-paused plugin/theme update exceptions when failures cross the streak threshold, on operator resume, and during the daily 06:45 catch-up. Stored in `wp_options['clockwork_update_exceptions']`. Companion renders the "Update coverage" submenu page and a dismissible warning notice on `plugins.php` (`update-exceptions` capability).
- `/action-log/append` — Clockwork Control mirrors every meaningful action so wp-admin can show it
- `/sso/magic-link` — mint a one-time URL the operator clicks to land in wp-admin as the named admin
- `/cache/flush` — object/page cache flush (`cache-flush` capability). Companion 1.35.0+. Clockwork Control also applies Pressable and Cloudflare layers from Control.
- `/plugins/update` — single-slug WP plugin upgrade via `Plugin_Upgrader`
- `/secret/rotate` — rotate the per-site HMAC secret
- `/malware-scan` — run the in-WP malware probe (PHP-in-uploads, obfuscated-eval signatures, recently-touched wp-config). Returns findings as `{findings: [{kind, path, evidence}], scanned_at, scanned_files_count}`. Called nightly by `clockwork:run-companion-malware-scans`. Bypasses Cloudflare entirely — replaces SiteCheck's role on CF-fronted sites where Sucuri's external scanner gets 403'd at the edge. SSH wp-cli fallback exists for sites without the Companion installed.
- `/post-update-verify` — post-update state verification and repair (v1.21.3+). Clockwork Control POSTs the expected active-plugin list and active theme after every successful update. Companion compares against current WordPress state, re-activates any plugin that went inactive, restores the theme if it changed, and returns a `{ok, repairs: [{type, slug, detail}]}` payload. Gated on the `post-update-verify` capability; sites with older Companion versions skip this call silently.
- `/security-summary-report` — Pressable-only vulnerability alerts + Defensive Mode status (v1.31.6+). Clockwork Control pushes known plugin/theme CVEs (Pressable's own feed) and edge-cache Defensive Mode's on/off state; Companion stores it in `wp_options['clockwork_companion_pressable_security_summary']` and renders it on the Security admin page. Defensive Mode is status-only by design — no client-facing toggle. Called daily by `clockwork:pressable-security-summary-report`.
- `/comments/moderate` — bulk approve/hold/spam/trash/delete on one or more comment IDs (`moderateComments()`), driving the Comment Moderation dashboard tab
- `/comments/cleanup` — purge spam and trash comments older than N days (`cleanupComments()`); called weekly by `clockwork:cleanup-spam-comments`
- `/maintenance-mode` — enable/disable maintenance mode with an optional custom title, message, and bypass secret key (`setMaintenanceMode()`)
- `/code-snippet` — execute a snippet of PHP in a sandboxed, output-buffered context and return its output, return value, and timing (`executeCodeSnippet()`); powers the Code Snippets workbench
- `/backup/create` — create full off-site backup streaming directly to AWS S3 Glacier Instant Retrieval. Receives presigned S3 PUT URL + headers. Companion validates the URL is public HTTPS (no private/reserved IPs, no redirects) **before** dumping, then dumps DB via `$wpdb` to gzipped SQL, compresses `wp-content/`, streams to S3 via curl (TLS host verified, CR/LF headers refused), cleans up temporary files, and returns `{ok, size_bytes, sha256, duration_ms}`. Powers standalone and direct off-site site backups.
- `/backup/restore/stage` — download a presigned archive GET, verify SHA-256, unpack. Same public-HTTPS URL rule as create; zip-slip entries abort.
- `/backup/restore/status` — GET poll of staged restore (does not consume the HMAC replay guard).
- `/backup/restore/apply` — maintenance on, prefix-scoped SQL import, file copy-over, caches flushed. Confirm domain + matching `archive_key`; fail-closed if anything breaks after maintenance is on.

## Snapshot cache

The `sites.companion_snapshot` JSON column holds the last `/snapshot` payload. Refreshed nightly at 03:00 by `clockwork:refresh-companion-snapshot`, and on-demand per-site after any update batch from the Updates page (via `RefreshSnapshotsAfterBatch`). UI reads from the cached column, never live — so an offline site shows yesterday's data with a timestamp instead of a spinner.

The Issues "WordPress plugins out of date" section reads `companion_snapshot.plugins.counts.updates_available > 0` via `JSON_EXTRACT()`.

## Push pattern (Round 1.5+)

Several Companion routes accept Clockwork Control-pushed data so the plugin can render it in wp-admin without making outbound calls of its own. The `/backups-report` and `/action-log/append` routes are the workhorses. The pattern:

- Clockwork Control is the source of truth (it computed the data).
- Companion stores the latest payload in `wp_options` (autoload off).
- The plugin's admin pages read the option and render. No outbound calls.

Result: Companion can show backup history, scan history, performance scans, and uptime data without ever needing to reach Clockwork Control's home LAN.

## SSO

`POST /sso/magic-link` mints a 192-bit nonce, stores it in `wp_options[clockwork_sso_<nonce>]` with a 60-second TTL, and returns a `?clockwork_sso=<nonce>` URL. Clockwork Control redirects the operator's tab to it. Companion's `Sso\Interceptor` runs on `init` priority 1, validates and **deletes** the option (one-time semantics), then `wp_set_auth_cookie($userId, false)` + `wp_safe_redirect`.

Skips MFA — same as `wp-cli user create-session`. None of our agency clients have 2FA configured today; revisit when one does.

## White Labeling & Feature Gating

- **White Labeling hub (v1.33.0+, consolidated in v1.4.0, upgraded in v1.6.5, locked down in v1.7.3)**: Configured under `/settings/companion` (the Agency Branding pillar). Features a Master Agency Brand Palette cascading primary and accent colors across Companion (wp-admin), Client Reports, and Notification Emails. Includes dedicated `<x-color-picker>` Blade components, two-tone header contrast (`CompanionBrandingManager::deriveMediumTone` and `deriveSoftColor`), separated `brand_text` (admin header bar) vs `menu_title` (sidebar menu), and strict logo validation (`png, jpg, jpeg, webp` — rejecting SVG) with automatic disk cleanup. `/settings/wordpress-plugins` is a **separate, unrelated page** under Fleet Policies. See [Features → Companion Branding](/documentation/features/companion-branding).
- **Public Admin Menu Visibility**: The parent Companion and Renegade menus are publicly visible to all site administrators (`manage_options`). The legacy email-domain restriction was removed so site admins can see their Connection status, Capabilities, Notifications, and Login Security / 2FA.
- **Dynamic Hub Detection (`WhiteLabel::getUnlockHubDomain()`)**: Emergency remote lockout recovery (`Clockwork → Unlock`) only appears on the site matching the configured Agency Primary Hub domain (`unlock_hub_domain` in `/settings/companion`, default: `clockworkwd.com`), or when overridden by the `CLOCKWORK_UNLOCK_HUB` constant in `wp-config.php`. Subdomain hubs (e.g. `support.customagency.com`) allow authorized staff email addresses from both the parent domain (`*@customagency.com`) and subdomain. Client sites never show this tool; unauthorized attempts return HTTP 403 `wp_die` and AJAX requests return 403 JSON errors.
- **Permanent White-Label Lockdown**: Local in-WP branding editing (`admin.php?page=clockwork-branding`) is permanently removed and returns HTTP 403 Forbidden. All branding is managed centrally in Clockwork Control and pushed over HMAC REST.
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
