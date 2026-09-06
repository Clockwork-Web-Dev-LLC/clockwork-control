---
title: Pressable
section: Integrations
order: 21
updated: 2026-09-06
author: Aaron Reimann
tags: [integrations, pressable, hosting, wordpress]
tracks: [modules/Pressable/src/**, app/Console/Commands/{ImportPressable,PressableTest,InstallCompanionPressable,PressableBackupsReport,PressableTrafficReport,PressableSecuritySummaryReport}.php]
---

Pressable is our second WordPress hosting provider, alongside SpinupWP. Structurally different from SpinupWP in one big way: **Pressable has no server concept**. Every one of its API operations is addressed by `site_id` alone — no SSH, no root/sudo, no droplet-style resource metrics. See [Concepts → Server, Site, Care plan, Hosting tier](/docs/concepts/server-site-care-plan) for how that reshapes the Server/Site relationship.

## Why we use it

~93 sites were already on Pressable before Clockwork could see them. Bringing them in gets uptime, SSL, security scans, and backups/traffic visibility onto the same dashboard as the SpinupWP fleet — one Issues page, one Sites list, instead of a second tool.

What Pressable gives us that SpinupWP doesn't:

- **Real backup run history** — `GET /sites/{id}/backups` returns actual completed runs. SpinupWP's API only ever exposed backup *configuration*, never history (we list DigitalOcean Spaces objects directly for that fleet).
- **Multi-provider Backup Relay** — `PressableBackupRelayAdapter` (`CAP_BACKUP_RELAY`) integrates with our [Backup Relay](/docs/features/backup-relay) pipeline to stream site backups into S3 Glacier Instant Retrieval.
- **An async command-execution API** (`run_site_wpcli_commands` / `run_site_bash_commands`) — the substitute for "SSH into the box" everywhere SSH would otherwise be load-bearing: Companion install, `wp core verify-checksums`, ad hoc file inspection.
- **Its own CVE feed** per installed plugin/theme (`security-alerts/plugins`, `security-alerts/themes`) and an edge-cache "Defensive Mode" for scraper/DDoS spikes — neither has a SpinupWP equivalent.

What Pressable does **not** give us, unlike SpinupWP+SSH:

- No file-content malware scanning (only known-CVE matching against installed versions).
- No inbound IP-blocking/fail2ban equivalent. Pressable's "firewall" tools (`list/create_site_firewall_rule`) are **egress-only** — an allowlist for outbound connections, not a WAF or ban system. Checked live via `discover_tools`: zero results for "waf" or "ban". The Bans tab has no Pressable-native replacement and stays hidden for Pressable sites (see [Features → Security scans](/docs/features/security-scans)).

## Setup

1. Generate an OAuth2 client (client_credentials grant) in Pressable's account settings.
2. Set in `.env`:

   ```env
   CLOCKWORK_PRESSABLE_CLIENT_ID=...
   CLOCKWORK_PRESSABLE_CLIENT_SECRET=...
   # Optional: set to true to block remote mutations and Companion installation
   CLOCKWORK_PRESSABLE_VIEW_ONLY=false
   ```

3. Test:

```bash
php artisan clockwork:pressable-test
# Or using the standardized alias:
php artisan clockwork:test-pressable
```

Displays operating mode (`Full Access` or `View Only`) and reports total sites visible.

## View-Only (Read-Only) Mode

When connecting to a client-owned or audit Pressable account:

- **Configuration**: Set `CLOCKWORK_PRESSABLE_VIEW_ONLY=true` in `.env` or toggle View-Only Mode under **Settings → Integrations**. Default is `false`.
- **Zero API Mutations**: Any call attempting to run remote bash commands, remote WP-CLI commands, or purge edge/object cache throws a `PressableReadOnlyException` before hitting the API. Telemetry queries (`POST /sites/{id}/metrics`, `POST /sites/{id}/logs/activity`) remain operational.
- **Zero Remote Writes**: `commandRunner()` and `companionInstaller()` return `null`, preventing Companion deployment and async remote shell commands.
- **Gated Capabilities**: `CAP_COMPANION` reports `false`. Read-only site metrics, security alerts, and backup history continue working normally.
- **Dry-Run Import**: Test site discovery and inspection without database changes: `php artisan clockwork:import-pressable --dry-run`.
- **Diagnostic Reporting**: Diagnostics at `/settings/diagnostics` append `· Mode: View Only`.

## Auth

OAuth2 `client_credentials` against `https://my.pressable.com/auth/token`. `Modules\Pressable\PressableClient` caches the Bearer token for 55 of its 60-minute life and re-authenticates once on a 401 — same pattern as `BillComClient`.

## Endpoints we call

Base URL `https://my.pressable.com/v1`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/sites` | Paginated site listing — the inventory import source. |
| GET | `/sites/{id}/statistics` | Site status, PHP version, primary domain, datacenter. |
| GET | `/sites/{id}/backups` | Real backup run history. No pagination — returns whatever window Pressable exposes (observed ~36-38h across pilot sites, not a 30/90-day policy). |
| GET | `/sites/{id}/stats` | Traffic period totals: today, yesterday, current month, last month, last 12 months. No daily breakdown. |
| POST | `run_site_wpcli_commands` / `run_site_bash_commands` | Async command dispatch on the site's own host. Returns no job id despite MCP-layer docs implying one — results are only observable via the activity log. |
| GET | activity log | Polled by `PressableCommandRunner` for a completion marker (see below). The server-side `filters` param exists in the OpenAPI schema but doesn't actually filter — filtering is client-side. |
| DELETE | `/sites/{id}/edge-cache` | Purge edge cache. Called unconditionally before every Companion health probe — a stale cached 404 from an earlier manual check otherwise masks a real pass. |
| GET | `/sites/{id}/edge-cache` | Edge-cache status, including Defensive Mode's `active` / `active_until`. |
| GET | `/sites/{id}/security-alerts/plugins` \| `/themes` | Pressable's own CVE feed for installed plugins/themes. `data.active` list; `data: null` on a clean site, normalized to `[]`. |

## Files

- `modules/Pressable/src/PressableClient.php` — the HTTP client (OAuth2 token caching, site listing, backups, stats, edge-cache, security alerts, async commands).
- `modules/Pressable/src/PressableCommandRunner.php` — turns the fire-and-forget command API into a synchronous run-and-get-result primitive.
- `modules/Pressable/src/PressableCompanionInstaller.php` — installs the Companion mu-plugin over that transport. See [Architecture → Companion plugin](/docs/architecture/companion-plugin).
- `modules/Pressable/src/PressableApiCommandRunner.php` — `Modules\Core\Contracts\SiteCommandRunner` adapter over `PressableCommandRunner`, for hosting-provider-agnostic command dispatch.
- `modules/Pressable/src/PressableHostingProvider.php` — the `HostingProvider` adapter (`Modules\Core\Contracts\HostingProvider`): capabilities, credential fields, diagnostics check.
- `modules/Pressable/src/PressableServiceProvider.php` — registers the client credential binding, `HostingProvider`, and diagnostics check with the module registry.
- `app/Console/Commands/ImportPressable.php` — idempotent site import (`clockwork:import-pressable`).
- `app/Console/Commands/PressableTest.php` — connectivity check.
- `app/Console/Commands/InstallCompanionPressable.php` — Companion install/update (`clockwork:install-companion-pressable`). Every result (installed, updated, failed) routes through `ActionLogger::recordCompanionInstall()`. `clockwork:detect-stuck-companion-state` (daily) alerts once on a failed install never retried within 24h, or an installed Companion gone silent for 3+ days, and once on recovery. See [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck).
- `app/Console/Commands/PressableBackupsReport.php`, `PressableTrafficReport.php`, `PressableSecuritySummaryReport.php` — push real Pressable data into Companion's wp-admin pages. `PressableBackupsReport` also reads the offsite-archive manifest that `clockwork:pull-backup-relay-report` writes and attaches an `offsite_archive` block (download URLs + `last_archived_at`) per site alongside the native Pressable backup history, for care-plan sites enrolled in the S3 Glacier relay. See [Features → Backup relay](/docs/features/backup-relay).
- Config: `config/clockwork.php` → `pressable` key.

## PressableCommandRunner — how "SSH" works without SSH

Pressable's command-dispatch API is fire-and-forget: submit a command, get nothing useful back, and the only way to observe completion is polling the site's activity log. `PressableCommandRunner` wraps that into something callers can treat like a synchronous shell command:

1. Snapshot the newest activity-log entry id before submitting.
2. Submit the command wrapped in a **capture-first** shell prefix: the exit code and a per-run nonce sentinel are emitted as the *first line* of output, because Pressable's activity log truncates output around ~1KB from the tail — a long-running command's real output could otherwise push the sentinel out of the retained window.
3. Poll for a newer `ssh.command` log entry carrying that nonce; parse the sentinel + exit code, return `{output, exit}`.

Handles Pressable's 30-second no-output kill. Live-tested both the success path (exit 0, ~33s round trip) and the failure path (non-zero exit extracted correctly).

**Caveat that shows up in more than one caller** (`WpCoreChecksumVerifier`, malware-scan fallbacks): the *tail* of long output survives, not necessarily all of it — a severely compromised site with many findings may only report its last few. A clean or lightly-flagged site is unaffected.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| daily 06:32 | `clockwork:pressable-backups-report` — pushes real backup run history to Companion's Backups page. |
| daily 06:37 | `clockwork:pressable-traffic-report` — pushes period-total traffic stats to Companion's Traffic page. |
| daily 06:39 | `clockwork:pressable-security-summary-report` — pushes plugin/theme vulnerability alerts + Defensive Mode status to Companion's Security page. Pressable-only capability, no SpinupWP parity intent. |

`clockwork:import-pressable` and `clockwork:install-companion-pressable` are **not** scheduled — the site inventory doesn't churn the way it needs a daily re-import yet, and Companion rollout to new Pressable sites is still a deliberate per-site/per-batch step. Run them manually as needed. See [Reference → Scheduled jobs](/docs/reference/scheduled-jobs) and [Reference → Artisan commands](/docs/reference/artisan-commands).

## Idempotency and the cross-platform collision guard

`sites.domain` is the unique natural key, same as the SpinupWP import. But unlike SpinupWP, Pressable's import has to guard against a real collision: **the same domain can legitimately appear in both platforms' site listings at once** — mid-migration, a staging clone, or client experimentation on the side.

`ImportPressable::upsertSite()` will not repurpose an existing row if it's a non-archived site with `hosting_provider=spinupwp` and a live `spinupwp_id` — it skips and logs a warning (`skipped_active_spinupwp` in the run's stats) instead of overwriting. This guard protects active SpinupWP sites from accidental cross-platform overrides during multi-host transitions.

If you see a site skipped this way and it's genuinely mid-migration to Pressable, that's a manual call today — the guard is a safe default, not a migration-aware state machine.

**New sites start with `auto_updates_paused = false`** (creation-only — never overrides an existing manual pause), matching the SpinupWP importer behavior. See [Integrations → SpinupWP](/docs/integrations/spinupwp) and [Features → Updates](/docs/features/updates).

## Provider-agnostic querying with hostMonitored()

Provider-agnostic paths use `Site::hostMonitored()` because Pressable sites have no server row (see the "no server concept" note at the top of this page). This scope correctly includes both server-hosted sites and serverless managed hosts across:

- `UpdateGrouping` (the source of truth behind `/updates`) — ensures Pressable sites' pending plugin/theme/core updates appear on the Updates page.
- `clockwork:companion-fleet-deploy` and `clockwork:companion-canary-deploy` — fleet-wide Companion rollouts.
- `SecurityScansController` (behind `/security/scans`) — fleet scan inventory table.

`CompanionFleetDeploy`/`CompanionCanaryDeploy` uses `$site->isPressable()` to branch appropriately, calling `PressableCompanionInstaller::installOrUpdate()` for Pressable sites and `CompanionInstaller::installOrUpdate()` (SSH) for SpinupWP sites in the same run.


## What's host-agnostic vs. what needed new code

| Capability | Status for Pressable |
|---|---|
| Uptime, SSL cert expiry, GTmetrix/PSI performance scans | **Zero changes needed** — all three just hit the public URL. SSL specifically now uses [`LiveCertProbe`](/docs/features/ssl-cert-tracking) since Pressable has no per-site cert API to call. |
| Companion install, backups, traffic, security summary, `wp core verify-checksums` | **New code** — see the relevant feature/architecture pages linked above. |
| WP core/plugin/theme update *execution* via Pressable's native update API | **Not built.** `/updates` already shows correct pending-update data for Pressable sites because it reads Companion's snapshot, same as SpinupWP sites — no native-API path was needed for visibility. Executing updates through Pressable's own API instead of Companion/wp-cli remains a deferred idea, not a gap in what operators can see today. |
| nginx-log-derived Traffic tab, Bans tab, LLAR install, cert recheck-now button | **Structurally impossible** without SSH/server access — hidden in the UI rather than shown broken. See [Features → Security scans](/docs/features/security-scans) and [Features → Traffic + capacity](/docs/features/traffic-and-capacity). |

## Gotchas

- **Commands over ~50,000 bytes serialized get hard-rejected (HTTP 400)** — that includes the shell wrapper, not just the raw payload. `PressableCompanionInstaller`'s chunk size is 45,000 bytes to leave headroom.
- **wp-cli's `--path=` flag doesn't work on Pressable** — confirmed live: `wp --path=/srv/htdocs core verify-checksums` returns "This does not seem to be a WordPress install," while `cd /srv/htdocs && wp core verify-checksums` succeeds. Every wp-cli call over this transport uses `cd` into the docroot, not `--path`.
- **Docroot is fixed at `/srv/htdocs`** — confirmed live, not configurable per site the way SpinupWP's path can vary.
- **Every command is logged verbatim in Pressable's own control panel (~30 days retention)** — never pass a long-lived secret through this transport. Companion install bootstraps with a throwaway secret, verifies `/health`, then rotates to the real secret over authenticated HTTPS.
