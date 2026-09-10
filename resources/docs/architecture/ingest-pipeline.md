---
title: Ingest pipeline
section: Architecture
order: 30
updated: 2026-09-09
author: Aaron Reimann
tags: [architecture, ingest, fail2ban, security]
tracks: [app/Services/Llar/**, app/Services/Wordfence/**, app/Services/Logs/**, app/Services/Fail2ban/**, app/Console/Commands/Pull*.php, app/Console/Commands/AutoApproveRepeats.php, app/Console/Commands/ProcessPendingBans.php, app/Http/Controllers/IngestSettingsController.php]
---

How a malicious IP gets from "hit a site once" to "blocked at the firewall on every server in the fleet." Three sources feed one queue feed one ban executor — and the scheduler is the load-bearing thing that makes it all flow.

## The pipeline at a glance

```
       SOURCES                      QUEUE                  EXECUTOR
   ┌──────────────┐
   │ nginx logs   │──┐
   │ (SSH tail)   │  │
   └──────────────┘  │   ┌──────────────┐    ┌────────────────────┐
                     ├──►│ review_queue │───►│ queued_for_ban     │
   ┌──────────────┐  │   └──────────────┘    └────────────────────┘
   │ LLAR DB pull │──┤      ▲                         │
   └──────────────┘  │      │                         ▼
                     │      │ auto-approve     ┌──────────────┐
   ┌──────────────┐  │      │ (≥2 sightings)   │ fail2ban-    │
   │ Wordfence    │──┘      │                  │ client banip │
   │ DB pull      │         │                  │  via SSH     │
   └──────────────┘         │                  └──────────────┘
                            │                         │
                       (operator click)               ▼
                                              ┌──────────────┐
                                              │ blocked_ips  │
                                              └──────────────┘
```

## Sources (every 5 / 15 min)

### nginx tailing

`clockwork:tail-nginx-logs` runs every 5 minutes via `App\Services\Logs\NginxLogTailer`.

- Inode + offset state lives in `nginx_log_cursors`. This is what survives `logrotate`. Lose the cursor and you re-ingest from byte 0.
- Caps each pass at 2 MB and trims to the last newline. Without that ceiling, a long quiet period followed by a 50 MB log file would OOM the PHP process.
- Parsed lines land in `threat_logs` (append-only, ~30-day retention).
- Per-site try/catch: one unreachable server does not fail the whole run. Errors are isolated per site so a temporary connectivity issue on one host does not block log processing for the rest of the fleet. Per-site failures log `tail_nginx_logs.site_failed` at warning level, and the command only reports overall failure when every site in the fleet fails.

### LLAR + Wordfence direct-DB pulls

`clockwork:pull-llar-lockouts` and `clockwork:pull-wordfence-blocks` run every 15 minutes — when the `IngestScheduleGate` says they should.

- Each source has its own enable toggle and `last_run_at`, all configurable from `/settings/ingest` (see below).
- The pull goes via `App\Services\Sites\SiteMySqlClient` — wraps the `mysql` CLI over SSH using a temp `defaults` file (base64 transport, 600 perms). Where Companion is installed, we use the HMAC-signed `/lockouts` and `/wordfence-blocks` routes instead — same data, no SSH session, faster.
- Both source queries return every *currently active* lockout/block on each pull, not just new ones — that's the DB shape, not a bug. To prevent log noise, only genuinely new events log at `info` (an action outside `skipped_active_ban` / `incremented_existing` / `skipped_queued_for_ban`); the "still active, nothing changed" and `filtered_protected` cases log at `debug`.

## Settings page (`/settings/ingest`)

`App\Http\Controllers\IngestSettingsController` drives this page and owns everything the sources above read through `IngestScheduleGate`:

- **`index`** renders the current config (night window, timezone, frequency, per-source enable) from `IngestScheduleGate::currentConfig()`.
- **`update`** (`PATCH /settings/ingest`) writes the shared night window (`start_time`/`end_time`, `HH:MM`), an `always_on` override (bypasses the window entirely while still storing the start/end so toggling back off restores them), `timezone`, a shared `frequency_minutes` (5–1440, default 60 — this is the per-source *cadence* inside the window, separate from the 15-minute scheduler tick that just checks whether it's time yet), and each source's `sources.{llar,wordfence}.enabled` checkbox.
- **`runNow`** (`POST /settings/ingest/run-now`) launches `clockwork:pull-llar-lockouts` or `clockwork:pull-wordfence-blocks` immediately via `BackgroundArtisan` (detached, because `Artisan::queue()` still runs in-process under `QUEUE_CONNECTION=sync`), ignoring both the window and the cadence gate — the escape hatch for "I need this data now, not at the next scheduled tick."

The window/cadence/enable state all lives in `app_settings` under `ingest.schedule.*` keys (see [Architecture → Data model](/docs/architecture/data-model)).

### Filter at ingest

`App\Services\Fail2ban\IgnoreIpMatcher` runs first on every candidate. It refuses anything that matches Cloudflare's published v4 + v6 ranges, every fleet server's own public IPv4, or loopback. These get dropped on the floor and counted as `filtered_protected` in the run summary — they never enter `review_queue`.

This matters because nginx records REMOTE_ADDR, which is the CF edge IP for CF-proxied sites unless the `set_real_ip_from` snippet has been pushed. Without the matcher, we'd be banning Cloudflare and the fleet would 521-flap.

## Queue (the human-in-the-loop step)

Approved sightings land in `review_queue`. Operator goes to `/bans/queue`, scans the list, and clicks Approve or Dismiss. Approve writes the row to `queued_for_ban`.

The auto-approve toggle on `/bans/queue` short-circuits the operator step for IPs that have been sighted 2+ times across the fleet. `clockwork:auto-approve-repeats` runs every minute and promotes any qualifying IP. Single-site-twice and cross-server-once both qualify. `Settings('auto_approve_repeats_enabled')` gates the command — disabling from the UI makes it a no-op without touching the schedule.

## Executor

`clockwork:process-pending-bans` runs every minute, drains `queued_for_ban` in small batches, and pushes each one via `sudo -n fail2ban-client set clockwork banip <ip>` over SSH. `IgnoreIpMatcher` is checked again — same protected list — so a poisoned queue can't ban CF or a fleet IP.

The actual ban lives in fail2ban's iptables backend. Persistence + TTL + reload-survival are fail2ban's job, not ours. Our `blocked_ips` row mirrors the state for the UI and the audit trail.

Every real `BlockedIp::create()` — including this batch executor's — also fires `ChatNotifier::ipBlocked()` (Mattermost/Slack, rendering "Decided by: auto" vs. a reviewer name from `decided_by`). It's excluded from the site's `is_inactive` notification gate since a firewall-level block is treated as an active-incident signal, not a routine nag.

## Three things that bite

### The scheduler not running

Without the scheduler running via cron, the entire pipeline drifts. nginx logs accumulate (still ingestable later), LLAR/Wordfence don't pull, the queue stops promoting, and approved bans sit in `queued_for_ban` forever. UI symptoms look like product bugs; the cause is the scheduler. See [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck).

### CF-edge bans on CF-proxied sites

Three layers defend this:

1. **Sweep** — `clockwork:sweep-cf-bans` is a one-shot mass-unban for any historical misattributed CF IPs.
2. **`ignoreip`** baked into every server's fail2ban jail (CF v4 + v6 + fleet IPs + loopback). Refreshed weekly by `clockwork:refresh-fail2ban-ignoreip`.
3. **nginx `set_real_ip_from`** so PHP / LLAR / Wordfence stop seeing CF edges as REMOTE_ADDR. Pushed weekly by `clockwork:refresh-cloudflare-real-ip` — which also bridges the snippet into `sites-enabled/`, because SpinupWP's nginx never loads `conf.d/` and the file alone is inert (see [Integrations → Cloudflare](/docs/integrations/cloudflare)).

All three layers are active across the provisioned fleet. See [Integrations → Cloudflare](/docs/integrations/cloudflare).

### Provisioning is one-time per server

A server can't ban anything until the "Provision fail2ban" button has been clicked once. The provisioner installs fail2ban, writes a filter (with `<HOST>` capture group — required by fail2ban ≥1.0), writes the jail with the live ignoreip list, and grants NOPASSWD on `fail2ban-client` via `/etc/sudoers.d/clockwork`.

If provisioning fails halfway and breaks sudo on the box, `clockwork:clean-failed-provision <host>` prints a recovery script you paste into SpinupWP's "Run a Custom Script" feature. See [Integrations → SSH + fail2ban](/docs/integrations/ssh-and-fail2ban).
