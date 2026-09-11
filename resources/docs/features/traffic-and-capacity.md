---
title: Traffic + capacity
section: Features
order: 120
updated: 2026-09-09
author: Aaron Reimann
tags: [traffic, capacity, visits, analytics, pressable]
tracks: [app/Console/Commands/RollupTraffic.php, app/Http/Controllers/CapacityController.php, app/Console/Commands/PressableTrafficReport.php, modules/Pressable/src/PressableClient.php]
---

Per-site daily traffic rollups (visits, unique IPs, requests, status codes) plus a fleet-wide Capacity dashboard for shared servers. Built from the nginx tail data — no external analytics provider.

**This whole feature is SpinupWP-only.** Pressable has no nginx access log for us to tail, so the per-site Traffic tab (below) and the Capacity page's server-level rollups don't apply to Pressable sites — the tab is hidden entirely rather than shown empty. See [Pressable's own traffic reporting](#pressable-traffic-reporting--pushed-to-companion-not-this-page) below for what Pressable sites get instead.

**Why this needs SSH, and why a non-SSH rebuild wouldn't reduce risk.** `clockwork:rollup-traffic` is built from raw nginx access-log data pulled by `clockwork:tail-nginx-logs` (`tail -c +N /path/to/access.log`) over SSH — see [Architecture → Security model → SSH](/docs/architecture/security-model#ssh). Pressable's push-based alternative (above) proves traffic data *can* be sourced without SSH when the provider's API exposes it — but SpinupWP's API doesn't expose per-request log data, only the log file itself. Even if that changed (a future Companion-side log reader, or an OS-level polling agent), the same server still needs its SSH credential stored for apt-get updates, fail2ban, and `wp-cli` plugin/checksum checks. Removing SSH from traffic alone wouldn't shrink the fleet's actual credential-storage risk — the same key would still be sitting in the `servers` table for everything else this app does to an SpinupWP box.

## Per-site traffic

`/sites/{id}/traffic` shows:

- 30-day visits chart (the WP-Engine-style number).
- Daily breakdown table.
- Top paths and top IPs for the most recent day.
- Status-code mix.

Data lives in `site_traffic_daily` — one row per `(site_id, date)`, idempotent upsert. Refreshed hourly by `clockwork:rollup-traffic --backfill=2` (today + yesterday).

## The visit definition

WP-Engine-style: **DISTINCT IP per UTC day**, excluding 403s and static-asset paths.

We deliberately do NOT filter known bots at rollup time — adding it made the per-day query 50–100× slower. Net effect:

- **Non-CF sites** — visit counts are inflated by bots (the search engines we know about would otherwise be filtered).
- **CF-proxied sites** — visit counts under-count because the origin only sees CF edges. CF aggregates at its end; we see one IP per edge per visitor.

Both are predictable distortions. We chose this over a slower query.

## Capacity page

`/capacity` is **shared-server only** — sites tagged `Shared` (one of the three canonical tier tags). The page partitions:

- **Pressure** — servers running near or above headroom in any of CPU / memory / disk.
- **Headroom** — servers with comfortable margin, candidates for taking on a new shared site.
- **Per-site over-quota table** — sites exceeding the configured visit threshold (default 30k visits / 30 days, configurable via `/capacity/settings`). The threshold is the early-warning version; for invoicing we use the calendar-month-to-date number. CF-proxied sites show a Cloudflare icon (mirrors the existing indicator in the "Trending toward overage" section).

Dedicated and Staging servers are excluded — capacity planning only matters for the shared partition where we trade off site density vs server health.

### Capacity settings (`/capacity/settings`)

Accessible via the "Capacity settings" button on `/capacity` and under Tools in the global gear menu:
- **Visit quota threshold**: The rolling-window visit threshold (default 30,000 visits, with quick presets for 10k, 25k, 30k, 50k, 100k). Sites exceeding this threshold populate the over-quota table and increment the navigation issues badge.
- **Lookback & trending windows**: Rolling lookback days (default 30d) and trending projection window (default 7d).
- **Shared server pressure thresholds**: 24-hour average percentage limits for CPU (default 70%), memory (default 80%), and disk (default 85%) that classify a shared server into "Pressure" vs "Headroom".

## Two windows for visit thresholds

Different windows for different decisions:

- **Calendar month-to-date** — what we invoice against.
- **Rolling days window** — early-warning over-quota alert + Issues badge (default 30 days). Catches "they're trending toward over-quota mid-month" before the invoice closes.

## Manual rollup

```bash
# Refresh today + yesterday:
php artisan clockwork:rollup-traffic --backfill=2

# Backfill more days for a specific site:
php artisan clockwork:rollup-traffic --site=42 --backfill=30
```

The rollup is idempotent on `(site_id, date)` — re-running for a day overwrites the row. Use this when you've changed the visit definition or fixed an upstream nginx parser bug.

## Retention

- `site_traffic_daily` — durable. Keep forever. It's small.
- `threat_logs` (the raw nginx data the rollup is built from) — pruned nightly by `clockwork:prune-threat-logs` to the window set at `/settings/ingest` (default 30 days). On MySQL the table is rebuilt as monthly partitions (`clockwork:rebuild-threat-logs-partitions`) so `DROP PARTITION` reclaims disk. Once rolled up, the raw data is the prune candidate.

So if you need to backfill rollups for a date older than 30 days, the data isn't there — the rollup row is the only memory.

## Pressable traffic reporting — pushed to Companion, not this page

`clockwork:pressable-traffic-report` (daily 06:37) builds a 30-day **daily** rollup for each Companion-equipped Pressable site — sourced from Pressable's time-series metrics API (`POST /sites/{id}/metrics`, via `PressableClient::siteMetrics()`). Pressable's `/metrics` endpoint provides per-day requests (by HTTP status), daily unique visitors, and per-path request counts, enabling the command to build the standard `{totals: {today, month_30d}, daily: [...]}` payload shape expected by `PushCompanionTraffic`. Companion's `TrafficPage` renders the normal chart display seamlessly.

Two real Pressable API quirks the command has to work around: metrics/dimensions must come from the same "family" to combine (mixing `views` with `http_status` silently returns empty rather than erroring), and the auto-selected time resolution differs by family for the same date range (daily buckets for Uniques & Views, 8-hour buckets for Edge Logs — aggregated into calendar days here).

This still doesn't feed `site_traffic_daily` or this app's own `/sites/{id}/traffic` tab, though — that tab stays hidden for Pressable sites regardless (no nginx access log to source it from). The daily rollup above is pushed straight to Companion's Traffic page in wp-admin (`/traffic-report`); Pressable clients see the same kind of daily chart as SpinupWP clients, just via a different pipeline that never touches this app's own dashboard.

## What this isn't

- **Not a real-time dashboard.** Hourly rollup means the latest hour's view is approximate. Live PHP-FPM-by-site on the server detail page shows current activity.
- **Not Google Analytics replacement.** No referrer tracking, no UTM parameters, no conversion tracking.
- **Not a billing system.** Capacity surfaces the over-quota signal; the actual invoicing happens in Bill.com.

## Gotchas

- **WP-Engine-style visit count is approximate** for the reasons above. Don't compare it directly against a SaaS analytics product's number.
- **MySQL `REGEXP` is POSIX ERE, not PCRE** — no lookahead/lookbehind/named groups. Affects any regex you write against `top_paths` JSON queries.
- **Rollup is hourly, not real-time.** A spike at 10:55 UTC won't show until the 11:00 hourly run.
- **`top_ips` and `top_paths` are JSON columns.** Treat them as opaque blobs in app code — query for membership, don't try to update them in place.
