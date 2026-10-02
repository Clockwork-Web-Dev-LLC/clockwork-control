---
title: Twilio (SMS)
section: Integrations
order: 75
updated: 2026-10-02
author: Aaron Reimann
tags: [integrations, twilio, notifications, sms, on-call, subscribers]
tracks: [modules/Twilio/src/**, app/Services/Twilio/OnCallResolver.php, app/Http/Controllers/NotificationSettingsController.php, resources/views/settings/notifications.blade.php, app/Models/SiteNotificationSubscriber.php, app/Mail/SiteDownClientMail.php, app/Mail/SiteUpClientMail.php]
---

> **Status: verified & production-ready.** The full SMS infrastructure is implemented — internal team on-call recipients, off-windows, on-call resolver, per-site client alert subscribers, storm circuit-breaker, fallback email + Mattermost, and A2P 10DLC support. Set `TWILIO_ENABLED=true` and the three `TWILIO_*` env vars (`TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER`) to turn on SMS downtime paging.

We use Twilio to send **SMS alerts** when a care-plan site goes down or recovers. Mattermost and Slack fire for ops monitoring; SMS is layered on top for both internal engineering on-call escalation and client-friendly notifications to subscribed client contacts.

The notification hub at `/settings/notifications` manages two recipient types:
1. **Team On-Call Rotation**: Internal engineers on a 24/7 rotation using **off-windows** to opt out of recurring time periods (e.g. weekends, religious observance). When nobody is on call, alerts fall through to email + Mattermost warning.
2. **Client Alert Contacts**: External client stakeholders subscribed to specific sites. They receive clean, reassuring SMS texts (via Twilio) and/or transactional emails (via Laravel Mail) without internal operational jargon.

## Why we use it

Mattermost works for daytime ops, but at 3am nobody's watching the channel. SMS is the right tool for "wake somebody up." Twilio is the canonical PHP-friendly SMS provider, has a good local dev path (trial accounts text verified numbers for free), and supports US A2P 10DLC registration which is now mandatory for production business SMS.

## Setup

### 1. Twilio account

1. Sign up at [twilio.com](https://www.twilio.com/). The trial gives you ~$15 credit, enough to send several thousand SMS.
2. From the Console dashboard, copy:
   - **Account SID** (starts with `AC...`)
   - **Auth Token**
3. Buy a US phone number with SMS capability (~$1.15/month). Phone Numbers → Buy a number → filter "SMS". Note the E.164 format (`+15555550100`).

### 2. A2P 10DLC registration *(production, US-only)*

US carriers require business SMS senders to register their brand and campaign. Without this, Twilio still accepts your messages but US carriers will reject them with **error 30034** ("invalid sender ID"). Trial accounts can still text *verified* numbers without registering — useful while you're testing.

1. Console → Messaging → Regulatory Compliance → A2P 10DLC.
2. Register your brand (name, tax ID, address). One-time fee ~$4.
3. Create a campaign — pick "Low Volume Mixed" or "Account Notification". ~$10 one-time + ~$1.50/month per campaign.
4. Attach your purchased phone number to the campaign.
5. Wait 1–7 business days for carrier approval. You'll get an email when the brand and the campaign are both `APPROVED`.

After approval, production messages flow normally and your Twilio dashboard will show "registered" next to the number.

### 3. Wire it up

Add to `.env`:

```
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_FROM_NUMBER=+15555550100
TWILIO_ENABLED=true
```

`TWILIO_ENABLED=false` is the master kill switch — even with valid credentials, the SMS notifier is a no-op until you flip it. Useful for staging the integration before A2P approval.

Twilio is a real, independently installable module (`modules/Twilio/`, package `clockwork/twilio`) implementing `Modules\Core\Contracts\SmsNotifier` — the same pattern every hosting/cloud-provider and chat-notifier module uses. An agency that doesn't want SMS paging at all can leave `clockwork/twilio` out of `composer.json`; the container falls back to `Modules\Core\NullSmsNotifier` (every method a safe no-op) so `UptimeStateUpdater` and `NotificationSettingsController` never need their own "is SMS even installed" check. Unlike Mattermost/Slack (which fan out to every enabled channel in parallel), SMS is single-vendor by design — at most one `SmsNotifier` is ever active, resolved via `ModuleRegistry::smsNotifiers()`.

### 4. Add recipients

Visit `/settings/notifications`:

#### A. Team on-call engineers
1. In the **Team On-Call Rotation** section, click **+ Add team recipient**:
   - Name (display only)
   - Phone in E.164 (`+15555550100`)
   - Optional email fallback (used if SMS to this engineer fails)
   - Enabled = active in rotation
2. For recurring off-call periods: open the recipient's row → **+ Add off-window**:
   - Label: `Shabbat` or `Vacation`
   - Start day & time, end day & time
   - Timezone (e.g. `America/New_York`)
3. The "Currently on-call" card updates immediately.
4. Click **Test SMS** on any recipient row to send a test text.

#### B. Client alert contacts
1. In the **Client Alert Contacts** section (or directly on any site's Settings tab in Card 6 **Alert Subscribers**), click **+ Add client recipient**:
   - Name (e.g. `Client Admin`)
   - Channel toggles: **SMS Alerts** (`notify_sms`) and **Email Alerts** (`notify_email`)
   - Phone (required if SMS alerts enabled)
   - Email (required if Email alerts enabled)
   - Subscribed Sites: Select which care-plan sites this contact should receive outage notices for.
2. Client contacts do not participate in internal on-call rotations or off-windows. They receive personalized, reassuring notices for their subscribed sites only.

## How it works

### When a site goes down

`UptimeStateUpdater::fireDownNotification` runs after 2 consecutive failed probes. It calls (in this order, each in its own `try/catch`):

1. `ChatNotifier` (Mattermost/Slack, whichever channels are installed and enabled) — always fires for any non-ignored site.
2. `SmsNotifier::siteWentDown` (resolved to `Modules\Twilio\TwilioSmsNotifier` when the module is installed) — fires only if the site is care-plan-enabled.
3. `ActionLogger::record` — writes the `uptime_transition` action_log entry.

`TwilioSmsNotifier::siteWentDown` then:

1. Returns false early if `! $site->care_plan_enabled`.
2. Dispatches client notifications:
   - Queries `OnCallResolver::clientRecipientsForSite($site->id)`.
   - Sends client-friendly SMS via `TwilioSmsNotifier::dispatchClients()` to subscribers with `notify_sms = true`:
     `"{Name}, the website {domain} is currently unreachable. Our team has been notified and is investigating."`
     (records `notification_log` entries with event type `client_site_went_down`).
   - Sends transactional email via `SiteDownClientMail` to subscribers with `notify_email = true`.
3. Resolves on-call team engineers via `OnCallResolver::activeAt(now())`:
   - If empty: fires the email + chat fallback (see below).
   - Otherwise: sends internal engineering SMS per recipient with technical details. Each attempt records a row in `notification_log`.

`TwilioSmsNotifier::siteWentUp` is the recovery path:
- Dispatches recovery SMS to client subscribers: `"{Name}, the website {domain} is back online and responding normally."` (`client_site_went_up`).
- Sends recovery transactional email via `SiteUpClientMail`.
- Sends internal recovery SMS to on-call team engineers with downtime duration.

### How "on-call" is computed

`OnCallResolver::activeAt(Carbon $now)`:

1. Loads every internal team recipient (`is_client = false`) with `enabled = true`.
2. For each, checks every off-window with `enabled = true`.
3. An off-window matches when `now` (in the window's timezone) falls inside the span between (`start_dow`, `start_time`) and (`end_dow`, `end_time`). Spans cross midnight and day boundaries cleanly — a weekly religious observance can be modeled as a single row covering the full ~27 hours.
4. Recipients with at least one matching off-window are excluded.
5. The remainder is the on-call set.

A team recipient with zero off-windows is on-call 24/7.

### Storm circuit-breaker

Before resolving on-call recipients, `TwilioSmsNotifier::dispatch` checks how many `site_went_down`/`site_went_up` SMS sends succeeded in the last **10 minutes**. At **4 or more**, it pauses: no more SMS go out until the window rolls past, and a single chat `@channel` warning fires once per storm window ("⚠️ SMS storm detected... A mass-outage or DNS failure may be in progress — check /issues") so on-call still finds out, just not via a flood of texts. A `notification_log` row (`EVENT_SMS_STORM_PAUSED`) records the pause itself.

This exists for the mass-outage case — a DNS provider blip or a shared-server incident that takes down a dozen sites within the same 10-minute window would otherwise page on-call with a dozen separate texts. The threshold and window are constants (`TwilioSmsNotifier::SMS_STORM_THRESHOLD = 4`, `SMS_STORM_WINDOW_MINUTES = 10`), not settings — change them in code if the real-world threshold needs tuning.

### Fallback when nobody is on-call

If the team on-call set is empty (everyone in a break) OR every Twilio send fails (account suspended, all numbers invalid), we fall through to:

1. **Email** to `clockwork.alerts.email` (`CLOCKWORK_ALERTS_EMAIL` in `.env`). Subject: `[Clockwork on-call fallback] site_down — domain.com`.
2. **Mattermost** `@channel` warning: "🔕 No on-call SMS recipient available for site_event=`site_down` on **domain.com**. Email fallback sent to ..."

Both events get their own row in `notification_log` so the audit trail captures the chain of attempts.

## Files

- `modules/Twilio/composer.json` — real, independently installable Composer package (`clockwork/twilio`).
- `modules/Twilio/src/TwilioClient.php` — thin wrapper around the official `twilio/sdk` package. Throws `RuntimeException` on transport / API failure.
- `modules/Twilio/src/TwilioSmsNotifier.php` — implements `Modules\Core\Contracts\SmsNotifier`; the high-level dispatcher (`siteWentDown`, `siteWentUp`, `dispatchClients`, `test`).
- `modules/Twilio/src/TwilioCheck.php` — the `/settings/diagnostics` connectivity check, contributed via `ModuleRegistry::diagnosticChecks()`.
- `modules/Twilio/src/TwilioServiceProvider.php` — binds `TwilioClient` (credential-resolved), contributes `smsNotifier()`, `diagnosticCheck()`, and a nav link.
- `modules/Core/src/Contracts/SmsNotifier.php` — the contract; `modules/Core/src/NullSmsNotifier.php` — the no-SMS-module-installed fallback every method safely no-ops on.
- `app/Services/Twilio/OnCallResolver.php` — stays in core app (vendor-agnostic on-call schedule matching, not Twilio-specific) — `activeAt(Carbon)` and `clientRecipientsForSite(int $siteId)`.
- `app/Http/Controllers/NotificationSettingsController.php` — recipient + off-window CRUD + subscriber management + per-recipient Test SMS.
- `app/Http/Controllers/SitesController.php` — per-site subscriber management (`syncNotificationRecipients`, `storeNotificationRecipient`).
- `resources/views/settings/notifications.blade.php` — the settings page with dual Team and Client sections.
- `resources/views/sites/tabs/settings.blade.php` — site settings tab with Card 6 Alert Subscribers.
- `app/Models/NotificationRecipient.php`, `NotificationOffWindow.php`, `NotificationLog.php`, `SiteNotificationSubscriber.php`.
- `app/Mail/SiteDownClientMail.php`, `app/Mail/SiteUpClientMail.php` — client transactional email mailables.
- Hook into uptime: `app/Services/Uptime/UptimeStateUpdater.php` `fireDownNotification` + `fireRecoveryNotification` — depends on the `SmsNotifier` contract, not a concrete Twilio class.
- Config: `config/clockwork.php` → `twilio` key.
- Migrations: `2026_05_09_190000`, `_190100`, `_190200`, and `2026_10_02_100000_add_client_fields_to_notification_recipients_table`.

## Costs

- Account: free.
- Phone number: ~$1.15/month per US number.
- A2P 10DLC: ~$4 brand + ~$10 campaign one-time, ~$1.50/month per campaign.
- Per-message: ~$0.0079 outbound US SMS. At our volume — a few site-down events per month — total Twilio bill is well under $5/month.

## Gotchas

- **A2P 10DLC mandatory for production.** Trial accounts work to verified numbers only. Without registration, US carriers reject with error 30034 even though Twilio's dashboard shows "queued/sent". This is the most common "why aren't my texts arriving" cause.
- **Phone numbers must be E.164** (`+1XXXXXXXXXX`). The settings form validates `^\+\d{8,15}$` — paste from a US-style "(404) 555-0100" and it'll reject. Easy mistake.
- **Auth token rotation invalidates immediately.** If you rotate from the Twilio console, every Clockwork SMS attempt fails until you update `.env`.
- **Off-window timezones are per-window**, not per-recipient. If a recipient travels and wants their off-window to follow them, you'd edit the window's timezone — or add a second window in the new zone and disable the first.
- **No retry on Twilio errors.** A failed send writes a `notification_log` row with `ok=false` and the error excerpt. The next site-down event is a fresh attempt; we don't replay old ones.
- **"Currently on-call" widget is real-time.** Refresh the page to see the current set; it'll match whoever would be paged that exact second.
- **Chat channels still fire regardless of SMS state.** Adding SMS doesn't replace Mattermost/Slack — it adds. Disable those separately (`CLOCKWORK_MATTERMOST_ENABLED=false`, `CLOCKWORK_SLACK_ENABLED=false`, or leave their modules out of `composer.json` entirely) if you want SMS-only.
- **4+ SMS in 10 minutes pauses SMS entirely** (storm circuit-breaker, see above). If on-call reports "I only got one text during that outage" during a real mass-down event, check for the `EVENT_SMS_STORM_PAUSED` row before assuming a delivery failure — it's very likely working as designed.

## Related

- [Mattermost](/docs/integrations/mattermost) / [Slack](/docs/integrations/slack) — the fire-and-forget chat channels that SMS layers on top of (all reached independently — SMS uses its own `SmsNotifier` contract, single-vendor, not the fan-out `ChatNotifier`/`clockwork.notifiers` tag those two share).
- [Mailgun](/docs/integrations/mailgun) — used for the email fallback when nobody is on-call.
