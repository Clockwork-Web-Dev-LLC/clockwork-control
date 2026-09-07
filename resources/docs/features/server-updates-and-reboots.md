---
title: Server updates + reboots
section: Features
order: 110
updated: 2026-09-07
author: Aaron Reimann
tags: [updates, reboot, ssh, ops, fleet, tags]
tracks: [app/Http/Controllers/ServerUpdateController.php, app/Http/Controllers/OperationsUpdatesController.php, app/Http/Controllers/TagsController.php, app/Console/Commands/ProcessServerUpdates.php, app/Console/Commands/PollSystemUpdates.php, app/Console/Commands/ReapStaleServerUpdates.php, app/Services/Servers/ServerUpdater.php]
---

The server detail page → Updates tab handles `apt-get` upgrades and reboots one server at a time. **For multi-server work, use the fleet view at `/operations/server-updates`** — that page exposes bulk selection ("Select all with packages", "Security only") plus a single button that queues every selected server at once. Both surfaces share the same backend pipeline: `clockwork:process-server-updates` drains the queue once per minute, one server per tick.

Both go over SSH (we don't use the DO API for power actions). Both queue rather than run synchronously, so a click doesn't block the request for ten minutes.

## Updates tab

`/servers/{id}/updates` shows:

- **Pending packages** — count from `apt-get -s upgrade`. Updated when you click "Refresh package list."
- **Reboot required** flag — driven by `/var/run/reboot-required` on the server.
- **Update queue state** — `idle`, `queued`, `running`, `done`.
- **Scheduled reboot** — set time + cancel buttons.
- **Last update log** — captured `apt-get` output from the last run.

## Queue an update

Click **Queue apt-get upgrade**. The button POSTs to `/servers/{id}/update/queue`, sets `update_status='queued'`, and returns. The actual upgrade happens when `clockwork:process-server-updates` picks it up (every minute, one server per tick — long SSH sessions don't stack).

The runner SSH-connects, runs `sudo apt-get update && sudo apt-get -y upgrade`, captures the output to `last_update_log`, and sets `update_status='done'`. If the upgrade flips `/var/run/reboot-required`, `reboot_required` is set on the server row. After the upgrade, `ServerUpdater` also captures nginx's service state (`systemctl is-active nginx`) so the Updates tab can surface "nginx stopped after apt upgrade" without a separate SSH round-trip.

Cancel a queued update with the **Cancel** button before it picks up. Once it's running, you have to let it finish.

## Fleet page (`/operations/server-updates`)

A single dashboard for the whole fleet:

- **5 rollup tiles** — Servers (with polled/never/failed breakdown), Packages pending, Security, Reboot pending, In flight.
- **Per-server table** — server name, state badge (`up to date` / `patches` / `security` / `queued` / `running` / `failed` / `ssh failed`), package + security counts, reboot flag, last polled, an inline **Update & reboot** button (for instant single-box cycles on servers with pending packages or required reboots; omitted when the server is up to date), and a Manage link to the per-server Updates tab.
- **Sticky bulk-action toolbar** — pinned to the viewport bottom so the controls are visible the moment the page loads, not buried under 50 rows of table:
  - **Select all with packages** — checks every row with `total_updates > 0`.
  - **Security only** — checks rows with `security_updates > 0`.
  - **Clear** — unchecks everything.
  - **Reboot at** time field — optional. Blank = reboot immediately upon completion (with a 1-minute grace for SSH to exit cleanly).
  - **Install updates & reboot immediately (N selected)** — prompts with `"are you sure you want to run updates and reboot on all selected servers?"` before queueing. Disabled until at least one box is checked. Rows for up-to-date servers are disabled from selection since no updates are pending.

### Re-poll fleet now

The button next to the rollup tiles kicks off `clockwork:poll-system-updates --all` as a **detached background process** (it forks with `nohup ... &` via `Symfony\Component\Process\PhpExecutableFinder` so it survives the HTTP request). A poll-in-progress banner appears at the top showing "polled X of Y" and the page auto-refreshes every 10s until the marker clears. The poll is **idempotent** across clicks — a cache lock (`operations.system_updates.poll_in_progress_since`) means a double-click only starts one background process; the second click sees "still running" and bails.

The same poll runs automatically every day at 04:15 UTC, but the on-demand path is what you reach for after a manual upgrade so the dashboard reflects reality immediately.

### What gets skipped at queue time

The controller (`OperationsUpdatesController::queueBulk`) is defensive — even if a stale form re-submits a row that shouldn't move, the row stays put. Skip reasons surface in the response flash message:

- **`ignored`** — `server.is_ignored=true`.
- **`already running/queued`** — row is already in flight.
- **`missing SSH credentials`** — no jail provisioned and no stored password.

### Auto-refresh of snapshot after a successful upgrade

`ProcessServerUpdates` inline-re-polls each server right after `apt-get upgrade` succeeds — same probe `clockwork:poll-system-updates` uses, scoped to one row. Without this, a successful upgrade left the snapshot showing the **pre-upgrade** pending counts until the next scheduled poll at 04:15 UTC, making the dashboard look like nothing had changed for up to 24 hours. Failed upgrades skip the re-poll (the failure log on `last_update_log` is enough forensics; re-polling could mask the prior state).

### Stale-running reaper

`clockwork:reap-stale-server-updates` runs hourly and flips any row stuck in `update_status='running'` for **more than 2 hours** back to `failed` so it can be re-queued. Real apt-upgrades top out at ~30 minutes on the heaviest server; 2h is comfortably past the worst-case real path. Each reap writes an `action_logs` entry (`server_update_reaped`) so when you see a row in `failed` state after the fact, the trail's there.

The 2h threshold is the `STALE_THRESHOLD_MINUTES` constant on `ReapStaleServerUpdates` — bump it if you ever genuinely have an upgrade that legitimately takes longer.

## Reboot

Click **Reboot server**:

1. The button POSTs to `/servers/{id}/reboot`, sets `scheduled_reboot_at = now() + 1 minute`.
2. Backend SSH-issues `sudo shutdown -r +1`.
3. The server schedules the reboot in `/run/systemd/shutdown/scheduled` and the SSH command returns cleanly with `STATUS: reboot-scheduled`.
4. After ~2 minutes, the post-reboot probe runs every poll cycle until SSH responds — at which point we clear `reboot_required` and `scheduled_reboot_at`.

**Why `+1`, not `now`:** `shutdown -r now` would kill the SSH session mid-command, leaving us guessing whether the schedule was even accepted. The 1-minute delay lets the SSH command return cleanly so we get confirmation.

**Default behavior when no `reboot_at` is specified on a bulk upgrade**: if `/var/run/reboot-required` exists after the upgrade, `ServerUpdater` defaults to `shutdown -r +1` (same 1-minute grace as the manual reboot button). Previously a blank `reboot_at` silently skipped the reboot step and the kernel update sat un-applied until somebody noticed and clicked the manual button — defeating the point of bulk-patching for security. Set `reboot_at=HH:MM` explicitly if you want to schedule for a maintenance window instead.

**Why not the DO API:** DO's reboot endpoint is a hard hypervisor reboot — can corrupt InnoDB. We always use OS-level shutdown so filesystems flush cleanly.

Cancel a scheduled reboot with the **Cancel reboot** button (runs `sudo shutdown -c` over SSH).

## Sync from fresh probe

`clockwork:poll-system-updates` re-SSHs to each server and syncs `upgrade_required` + `reboot_required` from the live probe so the dashboard row reflects the latest state even when no manual update has been queued. This is how the Issues page "Patches Available" and "Reboot Required" counts stay current between manual operator actions.

It's scheduled twice, at different scopes ([Scheduled jobs](/docs/reference/scheduled-jobs) has the exact times): a **daily** run gated to servers SpinupWP's mirror already flagged `upgrade_required=true`, and a **weekly `--all` sweep** covering every monitored server regardless of that flag. The gate matters — `upgrade_required` is only set by the SpinupWP import, so a server provisioned directly (DigitalOcean/Azure/Hetzner, outside SpinupWP) never trips it; the weekly `--all` sweep ensures all monitored servers are checked at least weekly.

## Probe state

`/servers/{id}/reboot/probe` re-runs the SSH probe on demand. Useful when you suspect the server is back up but the post-reboot scan hasn't fired yet.

## Tags, and the staging exclusion

`TagsController` manages the fleet's server tags (Settings → Tags, `/settings/tags`) — free-form labels like **Shared**, **Dedicated**, or **Staging**, each with a name, hex color, optional description, and sort order. `TagsController::syncServer()` (`PATCH /servers/{server}/tags`, called from the server detail page) attaches/detaches tags on one server via `$server->tags()->sync(...)`.

One tag matters here: a server tagged **staging** is excluded from the probing/alerting side of this pipeline, but **not** from actually applying system updates. `Server::scopeMonitored()` (`is_ignored=false` AND NOT tagged `staging`) still gates `clockwork:poll-system-updates` and every other probe/scan/alert loop — a staging server's apt-update snapshot isn't refreshed on the daily/weekly schedule, and it won't fire alerts. But queuing and draining an update is a separate, narrower scope: `Server::scopeEligibleForSystemUpdates()` (`is_ignored=false` only) is what `ServerUpdateController::queue()` checks and what `clockwork:process-server-updates` queries through, so a staging server **can** be queued from `/servers/{id}` and **will** be drained by the scheduler like any other non-ignored server. A successful run also re-probes that one server inline (bypassing the daily/weekly `monitored()` gate), so its package-count card reflects the upgrade immediately rather than waiting for the next scheduled poll.

This split is deliberate: staging boxes are noisy and not client-facing, so paging on their patch state wastes attention — but operators still need to be able to patch the box itself on demand. A staging server's package-count card only refreshes right after you run an update (the inline re-probe above) or on a schedule if you remove the `staging` tag (putting it back in the full `monitored()` set) — there's no separate manual "poll now" for the apt snapshot; **Recheck state** on this page only re-probes reboot-required/uptime, not package counts.

## Symptoms of a stuck queue

The most common cause: the scheduler isn't running. `clockwork:process-server-updates` runs every minute via the schedule — without it, queued updates sit forever. See [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck).

## What this isn't

- **Not WordPress core / plugin updates.** That's [WordPress plugin inventory](/docs/features/wordpress-plugin-inventory).
- **Not provisioning.** Servers are provisioned by SpinupWP. We don't create or destroy them.
- **Not a security update tracker.** `clockwork:composer-audit` (weekly Mon 06:00 UTC) audits **this app's** Composer dependencies. The `clockwork:security-check` command at 06:30 runs system-side checks. Per-server CVE tracking is on the backlog.

## Gotchas

- **`shutdown` on Ubuntu 22.04 is a symlink to `systemctl`** (`/usr/sbin/shutdown -> /bin/systemctl`). `shutdown -r +1` is `systemctl reboot` with a delay. `shutdown -c` clears it. Both write `/run/systemd/shutdown/scheduled`.
- **`apt-get` output can be huge.** We cap captured logs at 1 MB; over that, the tail is preserved.
- **Concurrent SSH sessions to one server can step on each other.** The `every minute, one server per tick` cadence is deliberate — don't increase it unless you've thought about lock contention.
- **Don't run `clockwork:process-server-updates --limit=N` (N > 1) by hand while the scheduler is also draining the queue.** Both processes race for queued rows; the atomic-claim filter prevents double-running the same server, but you can end up with N concurrent SSH sessions across the fleet which spikes load on the Clockwork box and on the Apt mirrors. The single-server-per-minute scheduler cadence is the right pace 99% of the time. If you need a one-shot bulk drain, kill `schedule:work` first, run `--limit=N`, then restart the scheduler.
- **Form re-submit landmine (fixed).** A POST/302 response from the bulk-queue endpoint used to let the browser remember the URL as a POST target — a Cmd+R on the resulting page could then re-submit the form and quietly re-queue the servers that had just completed. We now return a 303 See Other to the index URL, so reloading the landing page is unambiguously a fresh GET. If you ever see the dashboard counts stop dropping while the queue keeps draining, check the URL bar — that's the symptom.
