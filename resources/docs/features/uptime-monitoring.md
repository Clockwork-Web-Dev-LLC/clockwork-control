---
title: Uptime monitoring
section: Features
order: 30
updated: 2026-09-08
author: Aaron Reimann
tags: [monitoring, uptime, alerts, hosting, pressable, slack]
tracks: [app/Services/Uptime/**, app/Http/Controllers/MonitoringController.php, app/Console/Commands/CheckSiteUptime.php]
---

Every site we host gets probed every 5 minutes — SpinupWP or Pressable, doesn't matter, it's a plain HTTP probe against the public URL either way (`Site::hostMonitored()` is the scope covering both). If a site stops answering for two probes in a row, you get a chat alert. When it recovers, you get another alert. That's the whole feature in one sentence — the rest is detail.

Monitoring queries use `Site::hostMonitored()` across the fleet, ensuring both server-hosted sites (SpinupWP, Cloudways) and serverless managed hosts (Pressable) are monitored and visible in status views.

## Where to look

- **`/monitoring`** — fleet-wide status board. Big "ALL UP" / "X DOWN" hero, currently-up/currently-down counts, fleet-wide average uptime headline cards for both **7d** and **30d** (`MonitoringController::index()` computes `avg7d`/`avg30d` from `UptimeStatsCalculator::bulkUptime()`), a per-site table with uptime % over 24h / 7d / 30d, and a latest-events feed.
- **`/monitoring/settings`** — global probe interval (1 / 5 / 10 / 15 min) and failure threshold (1–6 failures). Changes here apply to every monitored site.
- **`/sites/<id>/overview`** — the Status card on the per-site Overview tab. Shows current state plus how long it's been that way.
- **Companion → `Tools → Clockwork → Uptime`** — the client-visible version. Same data, friendlier copy. Clients see this in their wp-admin.

## What "down" means

A site is **up** if it responds with HTTP 2xx or 3xx within 10 seconds — or if it returns 401/403 indicating it's alive but auth-protected (see below). Anything else is **down**:

- HTTP 5xx
- HTTP 4xx other than 401 (with `WWW-Authenticate`) and 403
- Connection refused, DNS failure, TLS handshake failure
- Request timeout

A site has to fail **two probes in a row** (about 10 minutes at the default cadence) before it transitions to `down` and fires the Mattermost alert. Recovery is instant — the moment the next probe succeeds, we transition back to `up` and fire the recovery alert.

The 2-failure threshold is the de-jitter logic. A single bad probe (CF blip, momentary network flicker, a brief WAF rate-limit) won't fire an alert.

## The 401 / 403 auth-protected carve-out

Sites behind `.htaccess` basic auth, IP allowlists, staging gates, or a WAF challenge return 401/403 to anonymous probes. The origin is alive — it's just refusing us. These are classified as **up** (`authProtected`) rather than down:

- **401 with a `WWW-Authenticate` header** (per RFC 7235) → `authProtected = true`, probe succeeds.
- **403** → `authProtected = true`, probe succeeds.
- **Bare 401 without `WWW-Authenticate`** → still treated as down — this indicates a malformed backend rather than a real auth gate.

The per-site Overview status pill shows **"Up · auth required"** with a lock icon when the latest state was auth-protected.

If you want to exempt our probe from a CF WAF rule, the User-Agent is `Clockwork-Uptime/1.0` plus `(+CLOCKWORK_OPERATOR_CONTACT_EMAIL)` if that's set (see `reference/env-vars`) — `UptimeProber::userAgent()` builds it dynamically as of the modularization roadmap's Phase 8, replacing what used to be a hardcoded email address. Whitelist whatever your instance's actual User-Agent string is in the WAF and the probe will receive a 200 instead.

## The 503 maintenance mode carve-out

WordPress core automatic updates (`.maintenance`), popular maintenance plugins (SeedProd, Kadence, WP Maintenance Mode), and hosting panel maintenance modes legitimately return **HTTP 503 Service Unavailable** for SEO protection (RFC 7231 §6.6.4).

Clockwork distinguishes between real server outages and scheduled maintenance:
- **503 with a `Retry-After` header** (standard WordPress core & reputable plugins) → classified as **maintenance**.
- **503 with WordPress maintenance markers in the response body** (e.g. `Briefly unavailable for scheduled maintenance`, `Scheduled Maintenance`, `Maintenance Mode`) → classified as **maintenance**.
- **503 with a server-side `.maintenance` or maintenance configuration file** (detected via SSH by `UptimeDiagnostician` across SpinupWP, GridPane, and custom VPS) → classified as **maintenance**.
- **Bare 503 without maintenance markers** → treated as down (FPM exhaustion, database timeout, or origin crash).

When a site is in maintenance mode:
- Status pill shows 🔧 **"Maintenance"** (or `MAINT` on the status board).
- Time in scheduled maintenance does **not** degrade rolling 24h / 7d / 30d uptime percentages.
- Critical outage notifications and on-call SMS pages are suppressed; an informational chat notice is posted instead.
- **Safety net**: If a site remains in maintenance mode for longer than 2 hours, `IssueCounter` flags it as a lingering maintenance issue to prevent forgotten maintenance windows.

## Per-site opt-out

`sites.uptime_monitoring_enabled` is the flag. Default is true for every site. Toggle from the per-site **Settings** tab when you have a site that legitimately shouldn't be monitored — staging, archived, customer-managed.

Staging-pattern domains (`staging.*`, `dev.*`, `*.staging.*`, `*-dev.*`) get auto-disabled on first import. Manual toggle for everything else.

## Ignoring alerts (when a site is down indefinitely)

Different from disabling. **Ignore** keeps the probe running, keeps the state column updating, keeps the event log honest — it just suppresses the noise. No Mattermost alert, no Issues entry, no nav badge contribution.

Use it when a client's site is offline for a known reason and there's no ETA — domain expired, project paused, hosting moved away — and you don't want it cluttering your daily Issues list. The site still shows on `/monitoring` (with a muted yellow "ignored" badge so you can see the state at a glance), and when it eventually recovers, the event log will show that too — you just won't get pinged.

How: per-site **Settings** tab → "Ignore uptime alerts" section. There's a reason field — fill it in (e.g. "client offline, no ETA"). To stop ignoring, hit **Stop ignoring** on the same screen. Action log entries are written for both directions so there's a record of who paused what and when.

## Tuning the global settings

`/monitoring/settings` exposes two knobs. Change them carefully — they affect every site.

- **Probe interval** — how often we hit each site. Default 5 minutes. The minimum is 1 minute (gives ManageWP-tier sensitivity), max is 15 minutes (gentlest, lowest log noise). Changes only apply on the next `schedule:work` restart.
- **Failure threshold** — how many failures in a row trigger the down transition. Default 2. Lower values = more sensitive (1 = alert on first miss, you'll get noise). Higher values = fewer alerts (6 = ~30 minutes at default cadence before you hear about it). Threshold changes apply on the next probe — no restart needed.

## Mattermost / Slack / client alerts

Every transition fires through `App\Services\Chat\ChatNotifier` — a fan-out dispatcher, not a single channel. `UptimeStateUpdater` type-hints the interface and doesn't know or care which concrete channels are active underneath it. Today that's up to three:

- **Mattermost** (`MattermostNotifier`) — ops channel, on by default. 🔴 **Down**: site name, HTTP code or transport error, server, the **auto-diagnosis** (see below) when available, links to the site and its Clockwork detail page. 🟢 **Recovered**: site name, downtime duration, server, link. Channel is set in `.env`: `CLOCKWORK_MATTERMOST_CHANNEL`. Lowercase channel slug — uppercase names are silently rejected by Mattermost.
- **Slack** (`SlackNotifier`) — same event set, an ops-facing alternative/addition to Mattermost. See [Integrations → Slack](/docs/integrations/slack).
- **Client-facing Slack** (`ClientSlackNotifier`) — only for `site_went_down`/`site_went_up` (plus contact-form events), posted to a per-site webhook the client configures themselves in Companion's wp-admin. Silent no-op for every other event type. See [Integrations → Slack](/docs/integrations/slack).

Each channel gates itself on its own `enabled` config/settings — safe to have all three active at once. `SmsNotifier` (Twilio) also fires independently, gated on `care_plan_enabled` — see [Integrations → Twilio](/docs/integrations/twilio).

## Down-event auto-diagnosis

When a site transitions `up → down`, `UptimeDiagnostician` opens a single SSH session to the box and gathers the five signals that explain ~all of the 5xx outages we've ever debugged:

| Signal | What it tells you |
|---|---|
| `maintenance.conf` non-empty | **Maintenance mode is active** (SpinupWP-style layout; file holds `return 503;`). Disable maintenance mode or truncate the file to recover. |
| `/run/php/php*-{site_user}.sock` missing | **PHP-FPM pool socket is gone** — the backend isn't running. `sudo systemctl restart phpX.Y-fpm`. |
| `systemctl is-active php*-fpm` = `failed`/`inactive` | **FPM master service is down** — same fix, restart the service. |
| `/proc/loadavg` ÷ cores > 4× | **Origin is overloaded** — workers timing out under load, the box itself needs attention. |
| Tail of `/sites/{domain}/logs/error.log` | Last 10 nginx error-log lines, in case the cause is none of the above. |

One round-trip, ~1–2 seconds, soft-fails to "could not SSH" if the box itself is unreachable (which is itself a useful signal — "we can't even talk to the server"). The result lands in two places:

- **`site_uptime_events.diagnosis`** — JSON column on the down-event row. Inspectable forever in the per-site event history.
- **Mattermost alert body** — the channel post now leads with the diagnosis summary instead of "Failed 2 probes in a row." For example: *"Maintenance mode is active (SpinupWP-style layout) — /etc/nginx/sites-available/{site-slug}/server/maintenance.conf returns 503. Disable maintenance mode or truncate the file."*

The check runs **once per outage**, on the transition only — not on every probe. Steady-state probing is unaffected. Recovery alerts don't carry a diagnosis (nothing to diagnose when a site is up).

This used to be a 30-minute SSH-and-grep session every time a site went down. The Mattermost alert now tells you what's wrong before you've finished reading it.

## What it's not (yet)

- **No content keyword check.** ManageWP lets you say "alert if the response body doesn't contain 'WordPress'". Marginal value for our fleet; defer.
- **No multi-region probing.** Everything probes from the operator's own machine. If you need an outside-the-agency-network viewpoint, that's a v2 feature.
- **No per-site cadence override.** All sites use the global interval. If a client wants 1-min checks while everyone else stays at 5, we'd add nullable `uptime_interval_minutes` columns. Defer until someone actually asks.
- **No SLA report PDF.** The data is in `site_uptime_events` — the report-generation feature is a separate plan.

## Common pitfalls

- **CF cached pages can mask origin death.** If Cloudflare serves a cached homepage even when the origin has imploded, our probe sees 200 → records as up. CF cache TTL determines how long the masking lasts.
- **Broken SSL chain = down.** A missing intermediate CA fails the probe with cURL 60 and we mark it down. That's correct — real visitors get the same error. Coordinate with the daily SSL checker if you're confused.
- **First-probe failures don't transition.** A site that's broken on its first-ever probe stays in `unknown` state with `consecutive_failures = 1` until its second probe fails. ~5-min lag from first detection to alert.
- **Restart required after interval changes.** If you change the cadence on `/monitoring/settings` and forget to restart `schedule:work`, you'll keep firing on the old cron. The settings page tells you this; trust the warning.

## Want more detail?

- The technical deep-dive lives in `docs/claude/uptime-monitoring.md` (in the codebase) — covers the state machine, the storage decisions, why we don't store every probe.
- The fleet-wide math (uptime % calculation, bulk computation) is in `docs/claude/monitoring.md`.
- Code: `app/Services/Uptime/`, `app/Console/Commands/CheckSiteUptime.php`, `app/Http/Controllers/MonitoringController.php`.
