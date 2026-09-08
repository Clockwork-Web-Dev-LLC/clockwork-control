---
title: Slack
section: Integrations
order: 61
updated: 2026-09-08
author: Aaron Reimann
tags: [integrations, slack, notifications, alerts, pressable]
tracks: [modules/Slack/src/**, app/Services/Chat/**, resources/views/settings/slack.blade.php]
---

Slack is a second chat notification channel alongside [Mattermost](/docs/integrations/mattermost), added so an agency-wide alert can land in Slack instead of (or as well as) Mattermost. There are actually **two separate Slack integrations** in this codebase that are easy to conflate — this page covers both.

## Two notifiers, same interface, different audience

Both implement `App\Services\Chat\ChatNotifier` and are fanned out to by `ChatNotifierDispatcher` — see [Integrations → Mattermost](/docs/integrations/mattermost) for how the fan-out works.

| | `SlackNotifier` | `ClientSlackNotifier` |
|---|---|---|
| Audience | Us (ops) | The client, per site |
| Webhook | One, agency-wide, from `.env` | One per site, client-configured |
| Events | Full `ChatNotifier::EVENTS` set (same as Mattermost) | Only `site_went_down` / `site_went_up` / contact-form failed/recovered — everything else is a silent no-op |
| Configured via | `.env` + `/settings/slack` per-event toggles | The client's own Companion wp-admin (not this app) |
| Message tone | Internal — IPs, verdicts, server names | Plain-English — "your website is unreachable," a support link, no internal fields |

## SlackNotifier — the ops channel

### Why we use it

Some team members or channels prefer Slack over Mattermost, or the agency wants monitoring alerts in both places during a migration between chat tools. `SlackNotifier` mirrors `MattermostNotifier`'s full event set exactly — same `ChatNotifier::EVENTS` registry — so nothing is Mattermost-only by omission.

### Setup

1. In Slack: create an Incoming Webhook (`api.slack.com/apps` → your app → Incoming Webhooks) for the target channel.
2. Set in `.env`:

   ```
   CLOCKWORK_SLACK_ENABLED=true
   CLOCKWORK_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
   CLOCKWORK_SLACK_CHANNEL=monitoring
   CLOCKWORK_SLACK_USERNAME=Clockwork
   CLOCKWORK_SLACK_ICON_EMOJI=:lock:
   ```

3. Configure per-event toggles at **Settings → Slack** (`/settings/slack`). All events default to ON — same defaults as Mattermost's per-event settings, tracked independently (`notifications.slack.events` vs `notifications.mattermost.events` in `app_settings`).

There's no dedicated `clockwork:slack-test` command yet — verify by toggling on a low-noise event (e.g. `llar_installed`) and triggering it, or by temporarily setting `CLOCKWORK_SLACK_CHANNEL` to a test channel and watching a real transition fire.

`SlackNotifier` is a real, independently installable module (`modules/Slack/`, package `clockwork/slack`) — same pattern as every hosting/cloud-provider module. An agency that only wants Mattermost can leave `clockwork/slack` out of `composer.json` entirely; there's no dead settings page or half-configured integration left behind, since the module owns its own routes, settings controller, and nav link too.

### Auth

The webhook URL is the credential, same trust model as Mattermost's — don't commit it, rotate if leaked.

### What we POST

Slack's incoming-webhook payload shape (`text` + `attachments`), same semantic helpers as `MattermostNotifier` (`send`, `siteWentDown`, `siteWentUp`, `ipBlocked`, `sslStateChanged`, `llarInstalled`, `contactFormTestFailed`, `contactFormTestRecovered`, `companionUnreachable`/`companionReachable`, `pluginUpdateFailed`, `malwareFindingDetected`, `serverUpdateFailed`, `backupRelayStale`/`backupRelayRecovered`, `queueWorkerRestartFailed`) since both implement the same `ChatNotifier` contract — see [Mattermost](/docs/integrations/mattermost) for what each one covers.

### Files

- `modules/Slack/composer.json` — real, independently installable Composer package (`clockwork/slack`).
- `modules/Slack/src/SlackNotifier.php` — a thin subclass of `Modules\Core\Support\WebhookChatNotifier` (shared with `Modules\Mattermost\MattermostNotifier` — both channels are ~85% identical code, extracted into one base once both were modularized; this class supplies only the `clockwork.slack.*` config namespace and log-message label).
- `modules/Slack/src/SlackSettingsController.php`
- `modules/Slack/src/SlackCheck.php` — the `/settings/diagnostics` connectivity check, contributed via `ModuleRegistry::diagnosticChecks()`.
- `modules/Slack/src/SlackServiceProvider.php` — tags the notifier into `clockwork.notifiers`, loads the module's own routes, contributes its nav link and diagnostic check.
- `modules/Slack/routes/web.php`
- `resources/views/settings/slack.blade.php` — stays in core app (module views aren't required to move, per the same pattern `modules/BillCom` established).
- Config: `config/clockwork.php` → `slack` key.

## ClientSlackNotifier — per-site client alerts

A second, unrelated Slack integration: `Modules\ClientSlack\ClientSlackNotifier` (`modules/ClientSlack/`) posts plain-English alerts to a **per-site** Slack webhook that the *client* configures themselves — in their own WordPress admin (Companion → Notifications page), not anywhere in this app. The webhook URL is read straight out of `$site->companion_snapshot['client_notifications']['slack_webhook_url']`, so there's no separate Clockwork-side column or settings screen for it — it rides in on the same snapshot Companion already pushes.

### Why it's scoped down

Clients don't need to know an IP got blocked or a plugin auto-update failed — those are operational details. `ClientSlackNotifier` only actually sends for the handful of events a client would recognize as "my website" problems:

- `siteWentDown` / `siteWentUp` — "Your website example.com is currently unreachable" / "...is back online," with a link to your configured support URL (`CLOCKWORK_OPERATOR_SUPPORT_URL`) on the down message. No status codes, no server names.
- `contactFormTestFailed` / `contactFormTestRecovered` — same tone, for the contact-form smoke test.

Every other `ChatNotifier` method (`ipBlocked`, `sslStateChanged`, `llarInstalled`, `pluginUpdateFailed`, `companionUnreachable`, `malwareFindingDetected`, generic `send`) is implemented as a hard `return false` — not gated by a setting, just a no-op by design. This is the one channel in the fan-out where "doesn't send most events" is intentional, not a missing feature.

### Why a site-level webhook instead of a Clockwork setting

The webhook lives in Companion's own `wp_options`, surfaced to Clockwork through the snapshot Companion already pushes — same "push pattern" used for backups/traffic/security-summary data (see [Architecture → Companion plugin](/docs/architecture/companion-plugin)). This means the client sets it up entirely within their own wp-admin, with no Clockwork-side UI, credential, or migration needed. If a site has no webhook configured (the common case), `webhookUrl()` returns `''` and every send is skipped silently.

### Files

- `modules/ClientSlack/src/ClientSlackNotifier.php`

## Gotchas

- **Don't confuse the two.** `SlackNotifier` (ops, agency-wide) and `ClientSlackNotifier` (client, per-site) are separate classes with separate webhooks and separate audiences — there's no shared config between them beyond both implementing `ChatNotifier`.
- **Turning off Mattermost doesn't turn off Slack, or vice versa.** Each channel gates independently on its own config — and, since both are real modules, an agency that only wants one can simply not install the other's Composer package at all.
- **No retries beyond the standard HTTP client behavior**, same as Mattermost. A flaky Slack endpoint means a missed post, not a missed check — the underlying event still got recorded.

## Related

- [Mattermost](/docs/integrations/mattermost) — the original chat channel and the `ChatNotifierDispatcher` fan-out both notifiers sit behind.
- [Architecture → Companion plugin](/docs/architecture/companion-plugin) — the push pattern `ClientSlackNotifier`'s webhook URL rides in on.
