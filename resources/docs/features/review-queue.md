---
title: Review queue
section: Features
order: 20
updated: 2026-09-14
author: Aaron Reimann
tags: [bans, review-queue, security, fail2ban, pressable]
tracks: [app/Http/Controllers/{BansController,ReviewQueueController,BlockedIpsController,SitesController}.php, app/Console/Commands/{AutoApproveRepeats,ProcessPendingBans,PruneExpiredBans}.php, app/Services/Fail2ban/BanRetention.php, app/Support/BanHistoryRow.php, database/migrations/2026_09_14_210000_backfill_review_queue_and_blocked_ips_server_id.php, resources/views/dashboard/bans/**, resources/views/settings/index.blade.php]
---

Suspicious IPs from LLAR / Wordfence / nginx land in the review queue at `/bans/queue`. You scan, click Approve or Dismiss, and approved IPs go to fail2ban via SSH within ~60 seconds. Auto-approve-repeats can short-circuit the human step for IPs sighted 2+ times across the fleet.

**SpinupWP-only, permanently.** Every ingest source (LLAR/Wordfence pulls, nginx tailing) and the ban mechanism itself (`fail2ban-client` over SSH) require server access Pressable doesn't grant. Pressable has no inbound-blocking API at all — this isn't a "not built yet" gap like some other Pressable-only limitations, it's a structural one. See [Integrations → Pressable](/docs/integrations/pressable) for the research behind that conclusion.

## The bans page

`/bans` has three tabs:

- **Queue** (`/bans/queue`) — pending entries awaiting decision.
- **Active** (`/bans/active`) — Clockwork's still-open ban records (`unbanned_at` null). Linux fail2ban itself drops the iptables block after 24 hours (`bantime = 86400`); these rows stay for audit until retention archives them.
- **History** (`/bans/history`) — the 50 most recent approve / dismiss / unban / expire events.

The old URLs `/review` and `/blocked-ips` 301-redirect to the new tabs. Mutation endpoints (`/review/{entry}/approve`, `/blocked-ips/{ip}/unban`) keep their old paths because forms in dashboard partials still post to them.

## Per-row actions

On a queue row:

- **Approve** — send to fail2ban (the IP enters `queued_for_ban`).
- **Dismiss** — drop, don't ban. Audit row in `action_logs`.
- **Bulk Approve / Bulk Dismiss** at the top of the page when you want to clear a batch.

On an active row:

- **Unban** — `fail2ban-client unban` over SSH, mark `unbanned_at` locally.

The **Active** tab's search box filters instantly as you type, no need to hit Enter — same client-side pattern as [Features → Sites (fleet view)](/docs/features/sites-fleet-view#what-you-see); see that page for how it works and its "current page only" limitation.

## Auto-approve repeats

The toggle at the top of `/bans/queue` enables auto-approval for IPs seen 2+ times across the fleet. `clockwork:auto-approve-repeats` runs every minute and promotes any qualifying IP. Single-site-twice and cross-server-once both qualify.

When the toggle is off, the command becomes a no-op without removing the schedule entry. So you can flip it on for a noisy week and back off without restarting anything.

## Ban retention & bulk clearing

Linux fail2ban jails automatically lift kernel iptables blocks after 24 hours (`bantime = 86400`). Clockwork keeps the `blocked_ips` row with `unbanned_at` null until retention archives it. Repeat-offender auto-approve keys off pending `review_queue` entries, not these active rows.

- **Configurable retention policy** (`bans.retention_months`, default 12 months / 1 year). Adjust from `/bans` via the "Policy" card or `/settings` → Fleet Policies → Firewall & Ban Retention (that card links to `/bans/active`).
- **Nightly automated cleanup** (`clockwork:prune-expired-bans` scheduled at 04:33 daily) sets `unbanned_at` and `decided_by = retention` on rows older than the threshold. The original `llm_verdict` is left intact. Action log type is `ban_expired` with actor `auto`.
- **Bulk Clear tool** (`/bans/active` → "Bulk Clear"): prune on demand by cutoff (1, 3, 6, 12 months, or all active bans). Same archive shape; action log actor is `manual`. Cutoff values are allow-listed — a negative `months` value is rejected, not treated as "clear everything."

## How an entry gets here

Three sources feed the queue:

- **LLAR direct-DB pull** every 15 min (`clockwork:pull-llar-lockouts`). Gated by `IngestScheduleGate`.
- **Wordfence direct-DB pull** every 15 min (`clockwork:pull-wordfence-blocks`). Same gate.
- **nginx tailing** every 5 min (`clockwork:tail-nginx-logs`). Continuous.

Where Companion is installed, the LLAR + Wordfence pulls go through HMAC-signed REST routes instead of SSH+SQL — same data, faster, less intrusive.

## Pre-filter at ingest

Before anything reaches the queue, `App\Services\Fail2ban\IgnoreIpMatcher` filters CF edges, fleet IPs, and loopback. These get dropped on the floor and counted as `filtered_protected` in the run summary. This is what stops us from banning Cloudflare on a CF-proxied site whose nginx logs still record CF edges as `REMOTE_ADDR`.

The matcher uses the same protected list that `ignoreip` uses on the server-side fail2ban jail, so the in-app filter and on-server whitelist can never drift.

## What "approved" actually does

1. UI writes the row to `queued_for_ban` with `decided_by='manual'` (or `'auto-repeat'` for the auto path).
2. `clockwork:process-pending-bans` runs every minute, picks up the row, asks `IgnoreIpMatcher` again (defense in depth), and SSH-issues `sudo -n fail2ban-client set clockwork banip <ip>`.
3. Local `BlockedIp` row gets the ban metadata + a `banned_at` timestamp.

If the matcher refuses at execution time (e.g. the ignoreip list grew between approve and execute), no ban is issued and the queue entry returns to pending.

## Chat notification on ban

Every ban — manual (`BlockedIpsController::ban`), single review-queue approval (`ReviewQueueController::banSingle`), the bulk-approve processor (`clockwork:process-pending-bans`), and both auto-ban paths (`clockwork:pull-llar-lockouts` / `clockwork:pull-wordfence-blocks`) — fires `ChatNotifier::ipBlocked($blocked)` right after the `BlockedIp` row is created. The message shows the IP, server, source, LLM verdict (if any), site (if scoped), and `decided_by` (`auto` for the automated paths, the reviewer's name for a manual approval). Toggle it per-channel like any other event — `ip_blocked` in `ChatNotifier::EVENTS`, on by default and intentionally excluded from the `is_inactive` quiet-site gate (an active ban is treated as an incident, not a routine nag).

## Server-side pre-requisites

A server can't ban anything until "Provision fail2ban" has been clicked on its detail page once. Without provisioning, queued bans for that server's sites stay in the queue forever. See [Integrations → SSH + fail2ban](/docs/integrations/ssh-and-fail2ban).

## When the queue stops moving

Two common causes:

- **The scheduler isn't running.** Approved entries sit in `queued_for_ban` forever. See [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck).
- **Ingest is paused.** Check `/settings/ingest` — the per-source toggles control whether new entries arrive.

If you ever ban the wrong IP and need to undo it everywhere, see [Runbooks → Bad IP ban recovery](/docs/runbooks/bad-ip-ban-recovery).
