---
title: Web routes
section: Reference
order: 20
updated: 2026-09-09
author: Aaron Reimann
tags: [reference, routes, http]
tracks: [routes/web.php]
---

Listing of every registered HTTP route in `routes/web.php`, grouped by feature area. Public vs. auth-gated boundaries are explicit. See [Architecture → Request lifecycle](/docs/architecture/request-lifecycle) for the middleware pipeline that processes each request.

**Note on module routes:** Not every URL below is defined directly in `routes/web.php`. Modular routes (such as `/settings/bill-com`, `/settings/mattermost`, and `/settings/slack`) reside in their owning module's `routes/web.php` (`modules/BillCom`, `modules/Mattermost`, `modules/Slack`), loaded via `loadRoutesFrom()` and each explicitly wrapped in `Route::middleware(['web', 'auth'])` — see [Architecture → Request lifecycle](/docs/architecture/request-lifecycle#the-auth-gate-core-routes-vs-module-routes). The URLs and behavior are identical.

For a live listing run:

```bash
php artisan route:list
```

## Public (unauthenticated)

| Method | Path | Purpose |
|---|---|---|
| GET | `/login` | Landing page with the Google sign-in button. |
| GET | `/auth/google/redirect` | Kicks off the Socialite flow. |
| GET | `/auth/google/callback` | Where Google sends the user back. Allowlist check happens here. |

If the Google-verified email isn't in the `users` table (or `revoked_at IS NOT NULL`), the callback bounces back to `/login` with a denial banner. No auto-provisioning.

## Auth-gated — everything else

### Dashboards

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Fleet dashboard (cards sorted by health). Server-centric — Pressable sites (no `server` row) don't appear here; see `/sites` below. |
| GET | `/sites` | Fleet-wide Sites list across both hosting providers, paginated + filterable by provider/domain. The only place to see the fleet in one list regardless of host. |
| GET | `/issues` | Consolidated "what needs human eyes." |
| POST | `/issues/poll-servers` | Re-poll all servers on demand from the Issues page (background `clockwork:poll-servers`). |
| POST | `/issues/fetch-all-db-creds` | Bulk-fetch missing WP DB credentials over SSH for all eligible sites (background `clockwork:extract-wp-configs`). |
| DELETE | `/issues/orphans/{siteId}` | Remove an orphaned site row (archives it via `archived_at`). Confirm dialog required. |
| GET | `/capacity` | Shared-server capacity / over-quota table. |
| GET/PATCH | `/capacity/settings` | Configure shared-server visit quota, lookback windows, and pressure limits. |
| GET | `/settings/capacity` | Redirects to `capacity.settings` — legacy-alias route, same shape as other `/settings/*` redirects. |
| POST | `/capacity/site-metrics/toggle` | Pause/resume fleet-wide Companion resource-sampler collection, then push the new flag in the background. See [Features → Dashboard](/docs/features/dashboard) ("Per-site CPU collection toggle"). |
| GET | `/maintenance-history` | Action-log review across the fleet. |
| GET | `/setup` | Fleet integrations setup and onboarding dashboard. |
| POST | `/setup` | Save active integrations and finish setup (redirecting to dashboard). |
| GET | `/setup/configure` | Optional standalone service configuration screen. |
| POST | `/setup/configure` | Save credentials entered on the standalone configuration screen. |
| POST | `/logout` | Sign out. |

### Servers

| Method | Path | Purpose |
|---|---|---|
| GET | `/servers/new` | New-server form. |
| POST | `/servers` | Create. Launches background `clockwork:import-spinupwp` + poll after save so SpinupWP-side moves and existing sites attach without blocking. |
| POST | `/servers/refresh-spinupwp` | Fleet-wide on-demand background re-run of `clockwork:import-spinupwp` + `clockwork:poll-servers`. Surfaces from the dashboard "Refresh from SpinupWP" button and every server header. Same idempotent command the 03:30 cron runs. |
| GET | `/servers/{server}/{tab?}` | Server detail. `tab` ∈ `sites|stats|updates|bans|settings`. |
| GET/PATCH | `/servers/{server}/edit` · `/credentials` | Edit + save SSH creds. |
| POST | `/servers/{server}/test` | Test SSH. |
| POST | `/servers/{server}/toggle-ignore` | Pause polling. |
| POST | `/servers/{server}/recheck-health` | On-demand metrics re-poll for one server. |
| DELETE | `/servers/{server}` | Remove. |
| POST | `/servers/{server}/provision/fail2ban` | One-time fail2ban setup. |
| POST | `/servers/{server}/ban-ip` | Manual ban. |
| POST | `/servers/{server}/auto-ban-{llar,wordfence}` | Per-source auto-ban toggle. |
| POST | `/servers/{server}/update/{queue,cancel}` | Queue / cancel apt-get upgrades. |
| POST | `/servers/{server}/reboot[/{cancel,probe}]` | SSH-driven reboot lifecycle. |
| PATCH | `/servers/{server}/tags` | Assign tier tags. |

### Server credentials (bulk)

| Method | Path | Purpose |
|---|---|---|
| GET/POST | `/servers/credentials` | Bulk view + save. |
| GET/POST | `/servers/credentials/feed[/apply]` | Paste a SpinupWP-feed XML, parse, then apply. |

### Sites

| Method | Path | Purpose |
|---|---|---|
| GET | `/sites/{site}/{tab?}` | Site detail. `tab` ∈ `overview|traffic|bans|security|performance|settings|forms|updates`. For a Pressable site, `traffic`/`bans` fall back to `overview` (no SSH/server access to source either from). |
| GET | `/sites/{site}/backups-history` | JSON backup history. Returns DigitalOcean Spaces backup runs, S3 Glacier Relay archives, and a `schedule` block (enabled / frequency / last / next) for the Backups widget. |
| PATCH | `/sites/{site}/backup-relay` | Per-site Glacier backup toggle + cadence (`daily` / `twice_weekly` / `weekly`). Custom/standalone sites only. |
| POST | `/sites/{site}/backup-relay/run-now` | Backup Now for one custom site (`clockwork:backup-relay-run --site={id} --force` in the background). |
| GET | `/search/sites` | JSON site search (focused with `/`). |
| PATCH | `/sites/{site}/cert` · POST `/cert/recheck` | SSL source + recheck. |
| POST | `/sites/{site}/uptime/recheck` | On-demand uptime probe for one site. |
| PATCH | `/sites/{site}/uptime-keyword` | Optional homepage keyword the 5-minute probe must find. |
| POST | `/sites/{site}/uptime-body-check` | Per-site skip of the white-screen body-length check (parked / SPA / gated homepages). |
| POST | `/sites/{site}/cache/purge` | Queue a best-effort cache flush (Companion, Pressable, Cloudflare). |
| POST | `/sites/{site}/work-logs` · PATCH/DELETE `/work-logs/{workLog}` | Client-report work log CRUD. |
| POST | `/sites/{site}/bans/{blockedIp}/unban` · `/bans/unban-all` | Unban one / all. |
| POST | `/sites/{site}/install-llar` · `/install-companion` | Install plugins. Companion install dispatches to the SSH or Pressable installer based on `Site::isPressable()`. |
| POST | `/sites/{site}/companion/{push-update,refresh-snapshot,sso,plugin-update}` | Companion ops. |
| POST | `/sites/{site}/care-plan[/clear-override]` | Toggle / un-pin care plan. |
| POST | `/sites/{site}/auto-updates/toggle` | Per-site nightly auto-update opt-in/out. |
| POST | `/sites/{site}/uptime-monitoring` · `/uptime-ignore` | Per-site uptime opt-out / mute-alerts-but-keep-probing toggle (two different things — see [Features → Uptime monitoring](/docs/features/uptime-monitoring)). |
| POST | `/sites/{site}/inactive` | Fleet-wide inactive toggle — site stays visible everywhere, excluded from Issues/nav badge/routine-maintenance alerts. See [Features → Inactive sites](/docs/features/inactive-sites). |
| POST | `/sites/{site}/fetch-db-creds` | Fetch WP DB credentials over SSH for a single site. |
| POST | `/sites/{site}/refresh-wp-plugins` | Re-probe via SSH. |
| POST | `/sites/{site}/email-vuln-report` | Email a plugin-vulnerability summary for one site. |
| POST | `/sites/{site}/archive` · POST `/sites/{siteId}/unarchive` | Soft-remove / restore. Archive on SpinupWP / Pressable also writes `site_ingest_exclusions` so host import will not resurrect the row; unarchive clears that exclusion. Unarchive uses `{siteId}` (not the `{site}` route-model binder) because the binder 404s on archived rows. |
| GET | `/forms` | Fleet-wide contact-form-test inventory. Provided by `Modules\ContactForms` (self-registered via its `ServiceProvider::boot()`), not core `routes/web.php`. The `forms` site-detail tab only appears when the module is enabled. |
| POST | `/sites/{site}/form-tests` · PATCH/DELETE `/form-tests/{cft}` · POST `/form-tests/{cft}/test-now` | Per-site form-test CRUD + on-demand run. Deliberately under `/form-tests/`, not `/forms/`, so it doesn't collide with the `?tab=forms` site-detail URL. Also module-provided now — see above. |
| POST | `/sites/{site}/contact-form/install-companion` · `/contact-form/rotate-secret` | Contact-form add-on's own Companion install/secret-rotate entry points (same controller methods as the generic ones above, reachable from the Forms page). |

### Updates

| Method | Path | Purpose |
|---|---|---|
| GET | `/updates` | Fleet-wide plugin/theme/core/translation updates page. |
| GET | `/updates/care-plan` | Curation page — per-site auto-update opt-in/out toggles. |
| POST | `/updates/bulk-update` | Queue a batch of `plugin_update_jobs` for the selected targets. |
| POST | `/updates/bulk-ignore` · `/bulk-unignore` | Add/remove `plugin_update_ignores` entries. |
| GET | `/updates/batches/{batchId}/status` | Batch progress JSON (UUID-constrained). Infrastructure exists; polling JS isn't wired up yet — see [Features → Updates](/docs/features/updates). |

### Bans

| Method | Path | Purpose |
|---|---|---|
| GET | `/bans[/{queue,active,history}]` | Unified bans page (3 tabs). |
| GET | `/review` · `/blocked-ips` | 301 redirects to `/bans/queue` and `/bans/active` — bookmarks keep working. |
| POST | `/review/auto-approve/toggle` | Toggle auto-approve-repeats. |
| POST | `/review/{bulk-approve,bulk-dismiss}` | Bulk actions on the queue. |
| POST | `/review/{entry}/{approve,dismiss}` | Per-entry decisions. |
| POST | `/blocked-ips/{blockedIp}/unban` | Global unban. |

### Monitoring + security

| Method | Path | Purpose |
|---|---|---|
| GET | `/monitoring` | Fleet uptime status board. |
| POST | `/monitoring/refresh` | On-demand fleet uptime re-check (background `clockwork:check-site-uptime`). |
| GET/PATCH | `/monitoring/settings` | Probe interval + failure threshold. |
| GET | `/security/admins` | Fleet WordPress administrator directory and allowlist. |
| PATCH | `/security/admins/allowlist` | Save approved admin email domains/emails. |
| POST | `/security/admins/{site}/ignore` · DELETE `/ignore/{ignoredWpAdmin}` | Acknowledge or restore a flagged WP admin. |
| GET | `/security/scans` | Per-site security scan inventory. |
| POST | `/security/scans/{site}/run` | Manual scan trigger. |
| GET | `/security/scans/{site}/file` | View a flagged core-checksum file's contents (SSH read, path-validated against the latest scan). |
| POST | `/security/scans/{site}/allowlist` | Add a `(path, bucket)` to the core-checksum allowlist. |
| DELETE | `/security/scans/{site}/allowlist/{entry}` | Remove an allowlist entry. |

### Operations — fleet-wide system updates

| Method | Path | Purpose |
|---|---|---|
| GET | `/operations/server-updates` | Fleet-wide apt-update dashboard. Lists every non-ignored server with its latest `server_update_snapshots` row. Legacy `/operations/system-updates` 301-redirects here. |
| POST | `/operations/server-updates/queue-bulk` | Bulk-queue apt-update jobs for selected servers. Reuses single-server transition guards (skip ignored / inflight / no-SSH). |
| POST | `/operations/server-updates/refresh` | On-demand fleet poll. Runs detached background poll. |

### Settings

| Method | Path | Purpose |
|---|---|---|
| GET | `/settings` | Settings & Operations Hub — 4-quadrant operations command center with real-time tool search. See [Features → Settings Hub](/docs/features/settings-hub). |
| GET/POST/PATCH | `/settings/users[/{user}/{revoke,restore}]` | Allowlist management. |
| GET/PATCH/POST | `/settings/ingest[/run-now]` | LLAR/Wordfence pull cadence + manual run. |
| PATCH | `/settings/ingest/retention` | Days/weeks window for raw `threat_logs` (default 30 days). |
| POST | `/settings/ingest/prune-now` | Start `clockwork:prune-threat-logs` in the background. |
| POST | `/settings/ingest/rebuild-partitions` | Start `clockwork:rebuild-threat-logs-partitions` in the background. |
| GET | `/settings/wordpress-plugins` | Fleet WP plugin inventory. |
| GET/PATCH/POST | `/settings/security-scans[/run-now]` | Scan toggles + manual run. |
| GET/PATCH/POST | `/settings/backup-relay[/run-now]` | Backup Relay (S3 Glacier IR) settings + on-demand execution. |
| GET | `/settings/backup-relay/sites/{site}/{archives,download}` | Per-site S3 Glacier archive listing (`archives`) and streamed download of a specific archive (`download`). |
| GET/POST | `/settings/bill-com[/run-{customer,care-plan}-sync]` | Sync status + manual runs. |
| GET/POST | `/settings/mattermost` | Per-event Mattermost notification toggles (ip_blocked, ssl_state_changed, site_went_down/up, etc.). |
| GET/POST | `/settings/slack` | Same per-event toggles, Slack channel. Independent settings key (`notifications.slack.events`) from Mattermost's. |
| GET/POST/PATCH/DELETE | `/settings/notifications[/recipients[/{recipient}][/test][/windows]]` | Twilio SMS recipient list + off-window schedule. |
| GET/PATCH | `/settings/integrations` | Overview of registered provider API credentials (.env stays primary source of truth). |
| POST | `/settings/integrations/{integration}/test` | Runs that integration's diagnostics check on demand and flashes the result. |
| GET | `/settings/integrations/{service}/limits` | Dedicated vendor rate limits, telemetry impact, connection tunables, and .env credentials. |
| PATCH | `/settings/integrations/{service}/limits` | Save operator connection tunables and write API credentials directly to .env. |
| POST | `/settings/integrations/{service}/limits/reset` | Reset service connection tunables back to recommended vendor defaults. |
| POST | `/settings/integrations/{service}/credentials/{field}/remove` | Remove an API key/credential from the root .env file. |
| GET | `/settings/modules` | Module Directory — browses the official + community module catalog (`Modules\Core\ModuleDirectoryClient`, cached feed from `clockworkcontrol.com/api/modules.json`). See [Features → Module Directory](/docs/features/module-directory). |
| POST | `/settings/modules/refresh` | Force-refresh the module feed, bypassing the cache. |
| GET | `/settings/updates` | Clockwork Control's own self-update hub — checks the GitHub Releases API for a newer Core version, shows the Companion fleet-rollout breakdown, and links to the module catalog. Not to be confused with the fleet-wide `/updates` page (client WordPress sites) — see [Features → System updates](/docs/features/system-updates). |
| POST | `/settings/updates/check` | Force a fresh check against the upstream release channel, bypassing the 12h cache. |
| POST | `/settings/updates/apply` | Operator-triggered self-update: `git pull` → `composer install --no-dev` → `migrate --force` → `optimize:clear`. Aborts before touching anything if the working copy has uncommitted changes. |
| GET | `/settings/diagnostics` | System diagnostics dashboard. |
| GET | `/settings/maintenance` | Operator-only page showing DB size + a download button. |
| GET | `/settings/maintenance/backup` | Streams a gzipped `mysqldump` of Clockwork's own database straight to the browser (no temp file on the server). Contains every encrypted column ciphertext — SSH keys, per-site DB creds, Companion secrets — decryptable only by pairing the dump with `APP_KEY`. Treat downloaded copies with the same care as `.env`. |
| GET | `/settings/weird-stats` | Pre-warmed threat-log stats. |
| GET/POST/PATCH/DELETE | `/settings/tags[/{tag}]` | Server tier tag CRUD. |

### Docs (this site)

| Method | Path | Purpose |
|---|---|---|
| GET | `/docs` | Docs index. |
| GET | `/docs/{path}` | A single doc page. Slug regex restricts to `[a-z0-9\-/]+`. |

