---
title: Scheduler stuck
section: Runbooks
order: 50
updated: 2026-09-03
author: Aaron Reimann
tags: [runbook, scheduler, ops, incident]
---

The scheduler not running is a load-bearing failure mode. Without it, almost every drainer drifts: traffic rollups go stale, the auto-approve sweep doesn't fire, queued updates and queued bans sit forever, post-reboot state never re-polls. Symptoms look like product bugs ("why didn't the reboot clear the badge?", "why is the queue 162 with auto-approve on?") but are usually just "scheduler isn't running." There is exactly one permanent mechanism: crontab — see below for why a long-running daemon should not be used.

## The permanent mechanism: crontab, not a long-running process

```bash
crontab -l
# should show:
# * * * * * cd /path/to/clockwork-control && /opt/homebrew/bin/php artisan schedule:run >> /dev/null 2>&1
```

This one-line cron entry is the *only* thing driving every scheduled command in `routes/console.php`. Every minute, cron spawns a brand-new `php artisan schedule:run` process, which checks what's due and exits — it never stays resident. Use `/opt/homebrew/bin/php` (a plain, version-agnostic symlink Homebrew keeps pointed at whatever PHP is currently installed), never a versioned path like `/opt/homebrew/Cellar/php@8.4/8.4.x/bin/php` — see below for why that distinction matters.

## Architectural note: why cron instead of a long-running daemon

In past testing, a separate launchd-managed `php artisan schedule:work` process (a long-running daemon, not cron) silently failed scheduled dispatches for days after a system package upgrade moved the binary path — while `launchctl list` still reported it as "running".

**Root cause:** `schedule:work` keeps one PHP process alive indefinitely and resolves the PHP binary path (`PHP_BINARY`, via Symfony's `PhpExecutableFinder`) once, at that process's own startup. A `brew upgrade` can move the actual binary to a new versioned path; a long-running process keeps using the stale path it already resolved and never re-checks, causing background dispatches to fail silently with `No such file or directory`.

**Why cron is resilient:** A crontab entry re-executes a fresh `php` process every minute via the stable `/opt/homebrew/bin/php` symlink, so it never accumulates a stale binary path the way a resident process can.

**Guidance:** Do not use long-running `schedule:work` daemons in production. Crontab already covers everything required with zero drift risk.

## Symptoms

- The bans queue isn't shrinking even with auto-approve on.
- Approved bans sit in `queued_for_ban` and never reach fail2ban.
- Server queued for `apt-get upgrade` 30 minutes ago, still says `queued`.
- Server rebooted, `reboot_required` badge still showing.
- The dashboard `last_polled_at` for every server is older than 5 minutes.
- LLAR / Wordfence pulls aren't happening (`/settings/ingest` shows stale `last_run_at`).
- Traffic rollup hasn't refreshed today (`/sites/{id}/traffic` 30-day chart cut off yesterday).

If two or more of these are true at once — and `crontab -l` still shows the entry above — check the crontab-driven `schedule:run` is actually succeeding (see Diagnose), since a "present but failing" cron entry looks identical to a healthy one until you check its actual behavior.

## Diagnose

```bash
# Is the crontab entry present at all?
crontab -l

# Run it manually right now and watch for real errors (a healthy run
# prints INFO "Skipping ... already ran on another server" lines, or
# actually dispatches due commands — either is fine; a stack trace isn't).
cd /path/to/clockwork-control && /opt/homebrew/bin/php artisan schedule:run

# Confirm /opt/homebrew/bin/php actually resolves to a real, current binary
/opt/homebrew/bin/php -v
```

If the crontab entry is missing, or `/opt/homebrew/bin/php` fails to resolve, that's the fix target — not a launchd plist (see above).

## Fix

Reinstall the crontab entry if it's missing:

```bash
(crontab -l 2>/dev/null; echo '* * * * * cd /path/to/clockwork-control && /opt/homebrew/bin/php artisan schedule:run >> /dev/null 2>&1') | crontab -
```

For a one-off manual catch-up (not a permanent fix), running `schedule:work` in the foreground is still fine — it just must never be left running unattended long-term:

```bash
cd ~/Development/clockwork-control
php artisan schedule:work
```

Within one minute, the every-minute commands fire:

- `clockwork:process-pending-bans` drains the ban queue.
- `clockwork:process-server-updates` drains queued apt updates.
- `clockwork:auto-approve-repeats` re-promotes any qualifying queue entries.

Within 5 minutes, the every-5-minute commands fire:

- `clockwork:poll-servers` refreshes the dashboard cards.
- `clockwork:tail-nginx-logs` resumes nginx ingest.
- `clockwork:check-site-uptime` resumes uptime probes.

The longer-cadence jobs catch up at their next scheduled tick.

## Catch-up gotchas

- **`clockwork:rollup-traffic --backfill=2`** runs hourly. If you missed multiple days, run a manual backfill: `php artisan clockwork:rollup-traffic --backfill=7`.
- **Daily security + performance scans** run in fixed windows. If you missed the 02:00–05:00 window, kick them off manually:

  ```bash
  php artisan clockwork:check-blacklists
  php artisan clockwork:scan-sitecheck
  php artisan clockwork:verify-wp-core-checksums
  php artisan clockwork:run-performance-scans --strategy=mobile
  ```

- **LLAR / Wordfence pulls** are gated by `IngestScheduleGate`. If the ingest window is configured to only run at night, manual `php artisan clockwork:pull-llar-lockouts` skips the gate.

## Mitigations baked in

- Optimistic UI updates show what *should* happen even if the drainer hasn't fired yet.
- Recheck-state buttons on most pages let you force a probe.
- Run-now buttons on `/settings/ingest`, `/settings/security-scans`, and `/settings/bill-com` let you bypass the schedule.
- The `last_polled_at` / `last_run_at` timestamps on cards display freshness directly, making stalled jobs immediately visible.

## When to escalate

- The crontab-driven `schedule:run` is confirmed running (per Diagnose above) but commands are still failing — read the actual command output for tracebacks; run the specific failing command directly for a clearer error.
- Database is unreachable — `mysql.server start` (Homebrew) or `brew services start mysql`.
- PHP errors on every command — `composer install` and `php artisan migrate` to make sure dependencies and schema are current.
