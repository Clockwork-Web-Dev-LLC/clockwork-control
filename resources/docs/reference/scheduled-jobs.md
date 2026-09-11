---
title: Scheduled jobs
section: Reference
order: 30
updated: 2026-09-10
author: Aaron Reimann
tags: [reference, scheduler, cron]
tracks: [routes/console.php, modules/SpinupWp/src/SpinupWpServiceProvider.php, modules/Pressable/src/PressableServiceProvider.php, modules/BackupRelay/src/BackupRelayServiceProvider.php, modules/CommentModeration/src/CommentModerationServiceProvider.php]
---

Every artisan command the scheduler runs, in chronological order through a UTC day. `php artisan schedule:list` is the actual source of truth — most entries are declared directly in `routes/console.php`, but as of Phase 7 of the modularization roadmap, a module can contribute its own via a `scheduledTasks()` override on its service provider (`clockwork:import-spinupwp` and the three `clockwork:pressable-*-report` commands below now live in `SpinupWpServiceProvider`/`PressableServiceProvider` this way; `routes/console.php` calls `ModuleRegistry::scheduleAll()` once, at the end, to pull all of them in). Their listed times and behavior are unchanged by the move. The scheduler not running is a load-bearing failure mode — if any of these stop firing, [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck) is your starting point.

Run the scheduler in foreground for development:

```bash
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"
php artisan schedule:work
```

The scheduler itself is watched by `clockwork:scheduler-heartbeat` (every minute, first): crontab cannot notice its own absence, so the web UI compares the last tick against a 5-minute threshold. The queue worker is monitored separately by `clockwork:ensure-queue-worker` (every 5 min), which kickstarts the worker if it has crashed or been throttled.

## Every minute

| Command | What it does |
|---|---|
| `clockwork:scheduler-heartbeat` | **Must stay first.** Writes `monitoring.scheduler_heartbeat_at`. If that timestamp is older than 5 minutes, the next web request shows a layout banner, counts +1 on Issues, and fires `scheduler_stale` chat once. A never-ticked install is a yellow warning only (no chat, not an Issue). See [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck). |
| `clockwork:process-server-updates` | Drain queued apt-update jobs, one server per tick. Long SSH sessions don't stack. A live apt-get failure logs `server_update_failed` to `action_logs` and pings Mattermost/Slack (same event the reaper below fires for a stuck-and-reaped row). Success is not logged. |
| `clockwork:process-pending-bans` | Drain `queued_for_ban` → `fail2ban-client banip` over SSH. Small batches. |
| `clockwork:auto-approve-repeats` | Promote any IP with 2+ pending lockouts to the ban queue. Gated by `auto_approve_repeats_enabled`. |

## Every 5 minutes

| Command | What it does |
|---|---|
| `clockwork:poll-servers` | Cloud-provider metrics (DigitalOcean + Hetzner) → `server_metrics`. Branches per row on `servers.provider`. This is what turns a server "red." |
| `clockwork:tail-nginx-logs` | Pull new nginx log lines, inode-tracked to survive logrotate. |
| `clockwork:check-site-uptime` | HTTP probe each monitored site, transition state, fire Mattermost on transitions. The `*/N` is configurable from `/monitoring/settings` (1/5/10/15). |
| `clockwork:ensure-queue-worker` | Watchdog for the `com.clockwork.queue` launchd service — checks for a live PID and kickstarts the worker if it has crashed. Recovery gap ≤ 5 min. If the kickstart itself fails, alerts Mattermost/Slack — every queued job is stuck at that point. |

## Every 9 minutes

| Command | What it does |
|---|---|
| `clockwork:warm-weird-stats` | Pre-compute the `/settings/weird-stats` cache. Cold compute is ~30s on a 2M-row `threat_logs` window — past PHP-FPM's max_execution_time. |

## Every 15 minutes

| Command | What it does |
|---|---|
| `clockwork:pull-llar-lockouts` | Direct-DB pull of LLAR lockouts. Gated by `IngestScheduleGate` (per-source enable + window + cadence). |
| `clockwork:pull-wordfence-blocks` | Same for Wordfence blocks. |
| `clockwork:pull-site-metrics` | Per-site CPU/memory rollups from Companion sites advertising `resource-sampler`. Feeds `/capacity`. Pausable via `monitoring.site_metrics_enabled`. |

## Hourly

| Command | What it does |
|---|---|
| `clockwork:rollup-traffic --backfill=2` | Refresh today's + yesterday's per-site traffic rollups. Day-boundary catch-up is the `--backfill=2`. |
| `clockwork:reap-stale-update-jobs` | Flip Updates-page jobs stuck `running` >10 min to `failed`. Safety net for worker crashes. Now also pings Mattermost/Slack for any reaped nightly-batch row — reaped jobs bypass the normal live-failure alert path entirely. |
| `clockwork:reap-stale-server-updates` | Same for fleet system-update runs stuck past 2h (web47 once sat "running" for 4 weeks). Now also pings Mattermost/Slack (`server_update_failed`) for every reaped row — no nightly/manual distinction to gate on here, unlike the plugin-update reaper, so it always alerts. |

## Daily — Bill.com window (01:00 UTC)

| Time | Command | What it does |
|---|---|---|
| 01:00 | `clockwork:sync-bill-customers` | Bill.com customer list + invoice pull → link sites by domain. Gated on `bill_com.enabled`. |
| 01:30 | `clockwork:sync-bill-care-plans` | Flip `care_plan_enabled` based on care-plan-Item invoicing. Respects `care_plan_override`. |

## Daily — nightly auto-updates (ET)

| Time (ET) | Command | What it does |
|---|---|---|
| 01:30 | `clockwork:refresh-companion-snapshot` | Pull `/snapshot` from each Companion-equipped site → cache to `sites.companion_snapshot`. (Was every-15-min, then 03:00 UTC — daily is enough; manual update batches trigger their own refresh.) Chained via `->then()`: `clockwork:run-nightly-plugin-updates` fires immediately after this command's process actually exits, not at a fixed clock offset — see the next row. |
| 01:45 | `clockwork:ensure-companion-trust-proxy` | Idempotently inject `CLOCKWORK_COMPANION_TRUST_PROXY` into wp-config.php on care-plan auto-update sites so Companion's rate limiter sees real IPs behind CF. Independently scheduled at a fixed time, not chained — unlike the update loop below, a stale snapshot here just means the constant gets injected a day late, not a silently dropped update. |
| 01:50 | `clockwork:detect-stuck-companion-state` | Fleet-wide sweep for a failed install never retried (>24h) or an installed Companion gone silent (>3 days) — alerts on the transition into and out of "stuck" so unmonitored or failed installs are caught automatically. |
| chained | `clockwork:run-nightly-plugin-updates` | Care-plan auto-update path — runs `wp plugin update` for each opted-in site. Per-site opt-out toggle on the site Updates tab. **Not its own `Schedule::command()` entry** — chained via `->then()` off `clockwork:refresh-companion-snapshot` above (`routes/console.php`), ensuring the full snapshot refresh completes across the entire fleet before update queries evaluate candidates. |

## Daily — security scan window (02:00 UTC)

| Time | Command | What it does |
|---|---|---|
| 02:00 | `clockwork:scan-sitecheck` | Sucuri SiteCheck remote scan. Care-plan only. (Daily now, not weekly Mon — all care-plan scans match the once-a-day expectation.) |
| 02:15 | `clockwork:check-blacklists` | URLhaus + Spamhaus DBL + optional Google Web Risk (legacy Safe Browsing v4 if the Web Risk key is empty). Hosting-tier (every site). |
| 02:30 | `clockwork:verify-wp-core-checksums` | `wp core verify-checksums` per site — SSH for SpinupWP, Pressable's async command API for Pressable. Care-plan only. |
| 02:45 | `clockwork:run-companion-malware-scans` | In-WP malware probe (Companion plugin endpoint, SSH wp-cli fallback). Bypasses Cloudflare. Care-plan only. |

## Daily — morning ingest + inventory (03:00 UTC)

| Time | Command | What it does |
|---|---|---|
| 03:00 | `clockwork:sync-allowed-bots` | Refresh the Arcjet bot allowlist into `allowed_bots`. |
| 03:15 | `clockwork:refresh-plugin-vulnerabilities` | Refresh the wpvulnerability.net CVE mirror for every installed plugin slug. Feeds the Issues page's vulnerable-plugin flags. |
| 03:30 | `clockwork:import-spinupwp` | Idempotent server + site import. Refreshes cert dates. |
| 03:35 | `clockwork:find-orphan-sites` | Reclassify sites whose SpinupWP linkage was lost. |
| 03:45 | `clockwork:reconcile-provider` | Link servers import-spinupwp couldn't IP-cross-reference (manual adds, unknown-to-SpinupWP boxes). |
| 03:50 | `clockwork:refresh-companion-snapshot --pending-updates-only` | Closes the SpinupWP/Companion snapshot gap: `import-spinupwp` (03:30) marks `wp_plugin_updates=true` for sites that have new updates, but the full snapshot refresh (01:30 ET) already ran using Companion's pre-update cached state. This targeted re-pull refreshes only sites flagged pending so the Updates page sees fresh data without requiring a full fleet refresh. |
| 04:00 | `clockwork:check-ssl-certs` | Per-site SSL state + Mattermost transitions. |
| 04:15 | `clockwork:poll-system-updates` | SSH `apt-check` + `reboot-required.pkgs` + `apt list --upgradable` → `server_update_snapshots`. Runs against (a) SpinupWP-managed servers SpinupWP flagged with `upgrade_required=true`, and (b) every non-SpinupWP-managed server unconditionally, since nothing else sets that flag for GridPane/Hetzner/custom-VPS boxes. See the weekly `--all` sweep below, now a safety net rather than the primary mechanism for non-SpinupWP servers. |
| 04:30 | `clockwork:prune-server-metrics` | Drop `server_metrics` rows older than 90 days. |
| 04:32 | `clockwork:prune-threat-logs` | Drop `threat_logs` older than the `/settings/ingest` window (default 30 days). `DROP PARTITION` on monthly MySQL partitions, else chunked DELETE. `withoutOverlapping(240)`, `runInBackground()`. |
| 04:45 | `clockwork:run-performance-scans --strategy=mobile --weekly-rotation` | Lighthouse run for tonight's 1/7th of the care-plan fleet — GTmetrix primary, PSI fallback. Each site gets one scan per week. Scheduled at 04:45 to land just after GTmetrix's daily credit refill (credits don't bank). `--strategy` is display-only on GTmetrix's tier; kept for row continuity. |
| 04:45 | `clockwork:capture-site-screenshots` | Refresh each site's homepage screenshot via Automattic's mShots service, feeding the visual fleet grid view. `withoutOverlapping(30)`, `runInBackground()`. |
| 04:45 | `clockwork:detect-wp-plugins` | SSH wp-cli probe for active LLAR/Wordfence per WP site. |
| 04:50 | `clockwork:detect-contact-forms` | Companion-aware contact-form detection. |
| 04:55 | `clockwork:sync-companion-form-subscriptions` | Reconcile client-picked form-test subscriptions (Companion wp-admin Forms tab) into `contact_form_tests`. Slots between detect (04:50) and test (06:00). |
| 04:58 | `clockwork:backup-relay-run` (or `clockwork:push-backup-relay-targets`) | Executes off-host backup archival to S3 Glacier Instant Retrieval across supported providers (`in_repo` mode). In `external_agent` mode, writes `targets.json` to S3 for the standalone droplet. See [Features → Backup relay](/docs/features/backup-relay). |
| 05:00 | `clockwork:check-cloudflare` | Per-site CF detection. (Promoted from weekly to daily — CF state changes too often to wait a week.) |

## Daily — Companion + form-tests (06:00 UTC)

| Time | Command | What it does |
|---|---|---|
| 06:00 | `clockwork:test-contact-forms` | Run scheduled contact form tests per site. |
| 06:05 | `clockwork:check-domain-expirations` | Poll ICANN RDAP registry APIs for domain expiration dates and registrar data. Weekly cadence for green sites, daily for yellow/red. |
| 06:15 (ET) | `clockwork:nightly-update-summary` | Email summary of the previous night's auto-update run. |
| 06:25 | `clockwork:refresh-companion-capabilities` | Re-pull `companion_capabilities` per site. Diff-aware. |
| 06:30 | `clockwork:push-companion-backups` | Fetch SpinupWP backup config + DO Spaces history → POST to each Companion site. |
| 06:32 | `clockwork:pressable-backups-report` | Pressable counterpart — pulls real backup run history straight from Pressable's `/backups` API (no separate inventory-import dependency, unlike the SpinupWP version). |
| 06:35 | `clockwork:push-companion-traffic` | Push yesterday's traffic rollup to each Companion-equipped site. |
| 06:37 | `clockwork:pressable-traffic-report` | Pressable counterpart — pulls page-view period totals straight from Pressable's stats API (no nginx access-log rollup dependency). |
| 06:39 | `clockwork:pressable-security-summary-report` | Pressable-only: known plugin/theme vulnerabilities + Defensive Mode status. No SpinupWP equivalent — pure additional capability, not a parity fix. |
| 06:40 | `clockwork:pull-backup-relay-report` | External-agent mode only: reads the backup-relay droplet's last run summary back from S3 and records it into `backup_relay_runs`. Also alerts if no run has completed within 6 days. See [Features → Backup relay](/docs/features/backup-relay). |
| 07:15 | `clockwork:check-robots-txt` | Check root `/robots.txt` across monitored sites to detect accidental crawler disallow directives. |

## Weekly

| Day / time | Command | What it does |
|---|---|---|
| Mon 04:30 | `clockwork:poll-system-updates --all` | Full-fleet apt-update sweep, bypassing the daily job's `upgrade_required` gate entirely. Now a safety net for SpinupWP servers whose mirrored flag is stale or wrong — the daily job above already polls non-SpinupWP-managed servers unconditionally, so this sweep is no longer their only path to being polled. |
| Sun 05:15 | `clockwork:cleanup-spam-comments` | Purge stale spam and trash comments across Companion-equipped sites, via `CommentModerationServiceProvider::scheduledTasks()`. |
| Sun 05:30 | `clockwork:refresh-fail2ban-ignoreip` | Refresh CF ranges + fleet IPs in every server's jail. |
| Sun 05:30 | `clockwork:scan-wp7-truncation --repair` | Sweep + auto-repair WP 7.0 upgrades that left `wp-includes/php-ai-client/` files with truncated names. |
| Sun 05:45 | `clockwork:refresh-cloudflare-real-ip` | Push the nginx CF-Connecting-IP snippet **and its sites-enabled bridge** — the conf.d file alone is inert on SpinupWP boxes. See [Integrations → Cloudflare](/docs/integrations/cloudflare). |
| Mon 06:00 | `clockwork:composer-audit` | Composer dependency vulnerability scan. |
| Mon 06:15 | `clockwork:send-telemetry` | Anonymous usage report to the project maintainer. On by default (`CLOCKWORK_TELEMETRY_ENABLED=true`); disable in Settings → Maintenance or via env. See [Reference → env vars](/docs/reference/env-vars). |
| Mon 06:30 | `clockwork:security-check --ssh --quiet-ok` | System security audit. |

## Monthly

(none currently scheduled)

## Deliberately not scheduled

`clockwork:import-pressable` and `clockwork:install-companion-pressable` are manual-only today — Pressable site inventory doesn't churn enough yet to need a daily re-import, and Companion rollout to new Pressable sites is still a deliberate per-site/batch step, not a fleet-wide cron. See [Integrations → Pressable](/docs/integrations/pressable).
