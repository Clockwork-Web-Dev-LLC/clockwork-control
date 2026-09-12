---
title: Scheduled jobs dashboard
section: Features
order: 150
updated: 2026-09-12
author: Aaron Reimann
tags: [scheduler, cron, operations, diagnostics]
tracks: [app/Http/Controllers/ScheduledJobsController.php, app/Models/ScheduledJobRun.php, app/Listeners/Scheduling/**, app/Support/ScheduledCommandName.php, app/Console/Commands/PruneScheduledJobRuns.php, resources/views/settings/scheduled-jobs.blade.php]
---

Lives at **`/settings/scheduled-jobs`** (Settings → System & Workspace → Scheduled Jobs). Lists every entry in the live cron schedule — `routes/console.php` plus every module's `scheduledTasks()` contribution — with its last outcome, duration, and a manual **Run now** button, so a job silently failing every tick (missing a migration, a revoked API key, a dead credential) doesn't go unnoticed the way `SchedulerHeartbeat` alone would miss it: the heartbeat only proves cron itself is ticking, not that any *particular* job is succeeding.

## How it captures run history

`RecordScheduledTaskResult` (`app/Listeners/Scheduling`) subscribes to Laravel's built-in `ScheduledTaskFinished`, `ScheduledTaskFailed`, and `ScheduledTaskSkipped` events, registered in `AppServiceProvider::boot()`. These fire for **every** entry in the schedule regardless of where it was registered — no per-command wiring needed, so a new module's scheduled task is covered automatically the moment it's added.

Each run writes a row to `scheduled_job_runs` (command, status, duration, exit code, truncated output on failure). `ScheduledCommandName::normalize()` strips the compiled PHP-binary-and-`artisan` prefix off `Event::$command` down to the bare signature (e.g. `clockwork:refresh-cisa-kev`) — the same normalizer runs on both the write side (the listener) and the read side (the controller enumerating `Schedule::events()`), so the two independently-computed keys always agree without either side hardcoding a command list.

`clockwork:prune-scheduled-job-runs` (daily, 04:31) keeps the table bounded — default retention 30 days, `CLOCKWORK_SCHEDULED_JOBS_RETENTION_DAYS`.

## Known limitation: backgrounded jobs

A job registered with `->runInBackground()` (e.g. `clockwork:check-site-uptime`) launches asynchronously — Laravel dispatches `ScheduledTaskFinished` immediately after the *launch*, not after the backgrounded process actually finishes, so its exit code at that point reflects the launcher, not the real outcome. The dashboard flags these rows with a "Runs in background" note; treat their status as "did it start," not "did it succeed."

## Currently-skipped detection

Some jobs no-op via a `->when()`/`->skip()` gate tied to a platform setting — e.g. `clockwork:sync-bill-customers` and `clockwork:sync-bill-care-plans` gate on `clockwork.bill_com.enabled`, `clockwork:send-telemetry` on the telemetry opt-out. Rather than hardcoding which setting gates which job, the controller calls `Event::filtersPass(app())` live for every row — a fully generic check that would flag any future `->when()` gate the same way. `ScheduledJobsController::KNOWN_GATES` additionally links a handful of recognized commands straight to the settings page that would turn them back on; unrecognized gated jobs still show the generic "currently skipped" badge without a link.

## Run now

Dispatches the exact command string via the same `BackgroundArtisan` service the Security Scans and Backup Relay "run now" buttons use — a detached `nohup` process, not a queued job (queue workers would just run it in-process before the request returns, defeating the point). The submitted command is validated against the *live* set of `Schedule::events()` signatures before dispatch — an unrecognized string is rejected outright, so this can never become an arbitrary-command-execution surface. Manual triggers are logged to `action_logs` (`ActionLog::TYPE_SCHEDULED_JOB_RUN_NOW`) with the operator's email as actor, distinct from the schedule's own `actor: scheduled` entries.
