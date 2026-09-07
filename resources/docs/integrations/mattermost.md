---
title: Mattermost
section: Integrations
order: 60
updated: 2026-09-07
author: Aaron Reimann
tags: [integrations, mattermost, notifications, alerts, slack]
tracks: [modules/Mattermost/src/**, app/Console/Commands/MattermostTest.php, app/Services/Chat/**]
---

Mattermost is where our real-time alerts land — uptime transitions, IP blocks, SSL state changes, Companion install/upgrade events. One channel, all signals. Posted via incoming webhook.

Mattermost is one of (currently) three channels behind a shared `App\Services\Chat\ChatNotifier` interface — [Slack](/docs/integrations/slack) is the other ops-facing option, plus a per-site client-facing Slack channel. All three can be active simultaneously; this page covers the Mattermost-specific half.

## Why we use it

The agency already lives in Mattermost. Adding another notification channel just creates another inbox to ignore. A single agency-wide channel for monitoring events keeps the signal-to-noise honest: if it pings, someone should look.

## The ChatNotifier abstraction

Event-driven code (uptime transitions, SSL checks, ban approvals, etc.) never calls `MattermostNotifier` directly — it type-hints `App\Services\Chat\ChatNotifier`, and the container resolves a `ChatNotifierDispatcher` that fans every call out to every channel tagged `clockwork.notifiers`. Mattermost is a real, independently installable module (`modules/Mattermost/`) — its own `MattermostServiceProvider::register()` tags itself in, the same mechanism `ClientSlackNotifier` already used before Mattermost had company:

```php
// modules/Mattermost/src/MattermostServiceProvider.php
public function register(): void
{
    parent::register();

    $this->app->tag([MattermostNotifier::class], 'clockwork.notifiers');
}
```

```php
// App\Providers\AppServiceProvider — remaining core-app-bound channels only
$this->app->tag([
    SlackNotifier::class,
], 'clockwork.notifiers');

$this->app->singleton(ChatNotifier::class, fn ($app) => new ChatNotifierDispatcher(
    iterator_to_array($app->tagged('clockwork.notifiers'))
));
```

(The tag array was a single hardcoded literal in `AppServiceProvider` before Phase 7 of the modularization roadmap — now each module's own service provider contributes its channel by tagging itself the same way it contributes a `CloudProvider` or `DiagnosticCheck`. An agency that doesn't want Mattermost at all can simply not require `clockwork/mattermost` in `composer.json` — no code to comment out, no dead settings page.)

`ChatNotifier::EVENTS` is the canonical registry of notification types (site down/up, SSL state changed, plugin update failed, IP blocked, LLAR installed, contact form failed/recovered, Companion unreachable/recovered, malware finding detected, backup relay silent/recovered, server update failed, queue worker restart failed) — both `MattermostNotifier` and `SlackNotifier` implement the full set and gate each method on its own per-event setting. `companion_unreachable`/`companion_reachable` have two independent callers: `ContactFormTester` (form testing enabled but no response) and the daily `clockwork:detect-stuck-companion-state` sweep (a failed install never retried, or an installed Companion gone silent for days) — see [Reference → Scheduled jobs](/docs/reference/scheduled-jobs). `backup_relay_stale`/`backup_relay_recovered` are the only two events with no `Site` involved at all (the relay is a fleet-level concern) — see [Features → Backup relay](/docs/features/backup-relay). `ClientSlackNotifier` implements the interface too but only actually sends for a handful of client-relevant events (see [Integrations → Slack](/docs/integrations/slack)); everything else is a silent no-op for that channel. Each channel independently no-ops when unconfigured, so having all three registered is always safe — a channel with no webhook URL simply never sends.

Adding a fourth channel means: implement `ChatNotifier`, gate each method with an `isEventEnabled()`-style check, and tag the class `clockwork.notifiers` (in `AppServiceProvider`, or in a module's own service provider). Callers never change.

## Setup

1. In Mattermost: Integrations → Incoming Webhooks → Add Incoming Webhook. Pick a channel.
2. Copy the webhook URL.
3. Set in `.env`:

   ```
   CLOCKWORK_MATTERMOST_ENABLED=true
   CLOCKWORK_MATTERMOST_WEBHOOK_URL=https://chat.example.com/hooks/...
   CLOCKWORK_MATTERMOST_CHANNEL=monitoring   # lowercase!
   CLOCKWORK_MATTERMOST_USERNAME=Clockwork
   CLOCKWORK_MATTERMOST_ICON_EMOJI=:lock:
   ```

4. Configure per-event toggles at **Settings → Mattermost** (`/settings/mattermost`). All events default to ON; turn off any you don't want in chat.
5. Test:

```bash
php artisan clockwork:mattermost-test
```

A message should appear in the channel within a second.

## Auth

The webhook URL is the credential. Treat it like a secret — don't commit it; rotate if leaked.

## What we POST

Standard Mattermost incoming-webhook payload:

```json
{
  "text": "🔴 site.example.com is DOWN (HTTP 502)",
  "channel": "monitoring",
  "username": "Clockwork",
  "icon_emoji": ":lock:",
  "attachments": [...]
}
```

`Modules\Mattermost\MattermostNotifier` exposes a few semantic helpers so callers don't compose the payload by hand:

- `send($text, $attachments = [])` — generic.
- `siteWentDown(Site, status, detail)` — formatted down alert.
- `siteWentUp(Site, downtimeMinutes)` — formatted recovery.
- `ipBlocked(BlockedIp)` — manual or auto-approved ban.
- `sslStateChanged(Site, fromState, toState)` — cert state transition.
- `llarInstalled(Site)` — LLAR install event.
- `contactFormFailed(ContactFormTest)` / `contactFormRecovered(ContactFormTest)` — form test alert/recovery.
- `companionUnreachable(Site, reason)` / `companionReachable(Site, stuckForSeconds)` — Companion gone silent (a failed install never retried, or an installed site not heard from in days — see [Reference → Scheduled jobs](/docs/reference/scheduled-jobs)'s `clockwork:detect-stuck-companion-state` entry) and its recovery counterpart.
- `pluginUpdateFailed(Site, PluginUpdateJob)` — nightly auto-update failure (manual bulk-update runs stay silent; only `nightly-*` batches page). Also fires from `clockwork:reap-stale-update-jobs` for a `nightly-*` job that got stuck and reaped, not just a live failure.
- `malwareFindingDetected(Site, SiteSecurityScan)` — fires on the clean → malware-hit transition (Sucuri SiteCheck or the Companion in-WP probe), not on every re-scan of a site that's still infected.
- `serverUpdateFailed(Server, reason)` — a live `apt-get update/upgrade` failure, or a stuck update reaped by `clockwork:reap-stale-server-updates`. No `Site` involved — server-level.
- `backupRelayStale(daysSinceLastRun, lastRunAt)` / `backupRelayRecovered()` — the offsite backup relay droplet has gone quiet (6+ days with no new run recorded) and its recovery. No `Site` involved — see [Features → Backup relay](/docs/features/backup-relay).
- `queueWorkerRestartFailed(reason)` — the `com.clockwork.queue` launchd watchdog (`clockwork:ensure-queue-worker`, every 5 min) found the worker crashed AND its own restart attempt also failed. The worst case in this whole list: every queued job in the app is stuck until someone intervenes by hand.

Each event type has a toggle in **Settings → Mattermost**. The setting is stored in the `app_settings` table under the key `notifications.mattermost.events` (JSON object). When `CLOCKWORK_MATTERMOST_ENABLED=false` all toggles are moot — no messages fire regardless.

When `CLOCKWORK_MATTERMOST_ENABLED=false` the notifier is a no-op (no HTTP call). Failures are logged at warning level — they never break the action that triggered the notification.

## Settings page (`/settings/mattermost`, `Modules\Mattermost\MattermostSettingsController`)

Auto-discovers its checkbox list straight from `ChatNotifier::EVENTS` — adding a new event type to the interface (as this session's alerting work did four times: Companion stuck-state, backup relay, server updates, queue worker) makes it show up here with zero UI changes needed. `index()` reads the stored `notifications.mattermost.events` Settings JSON, falling back to each event's own `default` (currently `true` for everything) when a key hasn't been explicitly saved yet. `update()` treats any event key missing from the submitted form as unchecked (an HTML checkbox quirk — unchecked boxes don't appear in the POST body at all), so an all-off submit works without a hidden field per event. Every toggle flip is logged at `Log::info` level (`mattermost.notifications_settings_updated`) with a before/after diff, so "when did we silence `ssl_state_changed`" is answerable from the Laravel log without a dedicated audit UI.

## Files

- `modules/Mattermost/composer.json` — real, independently installable Composer package (`clockwork/mattermost`), same path-repository pattern as every cloud/hosting provider module. Not required — an agency that doesn't want Mattermost simply doesn't include it.
- `modules/Mattermost/src/MattermostNotifier.php`
- `modules/Mattermost/src/MattermostSettingsController.php`
- `modules/Mattermost/src/MattermostCheck.php` — the `/settings/diagnostics` connectivity check, contributed via `ModuleRegistry::diagnosticChecks()`.
- `modules/Mattermost/src/MattermostServiceProvider.php` — tags the notifier into `clockwork.notifiers`, loads the module's own routes, contributes its nav link and diagnostic check.
- `modules/Mattermost/routes/web.php` — the settings-page routes, moved out of core `routes/web.php`.
- `app/Console/Commands/MattermostTest.php` — stays in core app (the `clockwork:mattermost-test` connectivity command).
- Config: `config/clockwork.php` → `mattermost` key.

## Scheduled jobs that fire alerts

Many — but the notifier itself isn't on a schedule. Triggers come from event-driven code paths:

- `clockwork:check-site-uptime` (every 5 min) → up/down transitions.
- `clockwork:check-ssl-certs` (daily 04:00) → SSL state changes.
- Manual + auto IP bans (every minute via `process-pending-bans`).
- Companion install / upgrade flows.
- Migration phase transitions.

## Gotchas

- **Mattermost rejects uppercase channel names** silently — message returns 200 but doesn't post. Always use the slug (`monitoring`, not `Monitoring`).
- **Long attachment payloads can hit the server's body size limit.** Trim large fields (e.g. log excerpts) before sending.
- **Webhook URL leak ≠ catastrophic** — a leaker can post into the channel, but they can't read past messages or impersonate users. Still rotate if leaked.
- **No retries** beyond the standard Laravel HTTP retry. A flaky Mattermost server means missed alerts; the check still happened, the local DB row is correct, but the chat post is gone.
- **Disabling Mattermost doesn't silence everything.** `CLOCKWORK_MATTERMOST_ENABLED=false` only stops this channel — Slack and the per-site client Slack channel (if either is configured) keep firing independently through the same `ChatNotifier` fan-out.

## Related

- [Slack](/docs/integrations/slack) — the other ops-facing channel (same event set) plus the per-site client-facing channel, both behind the same `ChatNotifier` interface this page's notifier implements.
