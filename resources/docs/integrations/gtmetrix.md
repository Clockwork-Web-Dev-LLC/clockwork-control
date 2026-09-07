---
title: GTmetrix
section: Integrations
order: 79
updated: 2026-09-07
author: Aaron Reimann
tags: [integrations, performance, lighthouse, gtmetrix, care-plan, pressable]
tracks: [modules/GTmetrix/src/GtmetrixClient.php, modules/GTmetrix/src/GTmetrixServiceProvider.php, app/Console/Commands/RunPerformanceScans.php]
---

GTmetrix REST API v2 is the **primary** performance-scanning engine for SpinupWP sites. It runs a nightly Lighthouse scan against 1/7th of the care-plan fleet (each site weekly — see the credit budget below) from a pinned datacenter, pinned browser version, and pinned connection profile. [Google PageSpeed Insights](/docs/integrations/pagespeed-insights) serves as the fallback engine.

Pressable sites don't go through this chain at all — see [Features → Performance scans](/docs/features/performance-scans) ("Pressable sites — a third engine, no fallback chain") for their own always-on Lighthouse report.

## Why we use it

PSI's scores swung ±10-15 points day-over-day on unchanged pages (simulated-throttling noise, rotating Google datacenters, Chrome version drift, CrUX blending). GTmetrix pins the whole test environment, so day-over-day variance drops to roughly ±5 — a real signal for "did my page actually get slower." Costs a paid plan (the free tier's 5 tests/day won't cover the fleet), but it buys a usable regression signal PSI couldn't provide.

## Setup

1. Paid GTmetrix account → generate an API key at `gtmetrix.com/dashboard/api/`.
2. Set in `.env`:

   ```
   CLOCKWORK_GTMETRIX_API_KEY=...
   CLOCKWORK_GTMETRIX_REGION=4      # default test location (integer ID; 4 = San Antonio TX)
   ```

3. Trigger a manual run for one site:

```bash
php artisan clockwork:run-performance-scans --site=42 --engine=gtmetrix
```

If the key is empty the runner falls through to PSI (or short-circuits if that's unset too) — no error.

## Auth

HTTP Basic — API key as the username, password blank. Laravel's `withBasicAuth($key, '')` handles the encoding.

## Endpoints we call

Base URL `https://gtmetrix.com/api/2.0` (`CLOCKWORK_GTMETRIX_BASE_URL`).

| Method | Path | Purpose |
|---|---|---|
| POST | `/tests` | Submit a test (`url`, integer `location` ID, `report: lighthouse`). Returns 202 + a test resource ID. |
| GET | `/tests/{id}` | Poll until complete. 5s interval × 36 attempts = 180s ceiling. |
| GET | `/locations` | Manual only — list valid location IDs: `curl -u <key>: .../locations \| jq`. |

We request the Lighthouse report only. The completed payload carries `performance_score`, Core Web Vitals (LCP, FCP, TBT, SI, CLS), `page_bytes`, and `page_requests` — mapped onto the same `site_performance_scans` columns PSI used.

## Test regions

The `location` attribute is a GTmetrix datacenter. Global default: **4 = San Antonio TX** (closest US-central replacement for their discontinued Dallas location). Per-site override on `sites.performance_scan_region` — NULL means global default. Common IDs: 2=London, 3=Sydney, 4=San Antonio TX, 7=Hong Kong, 9=San Francisco CA, 10=Cheyenne WY, 11=Chicago IL, 12=Danville VA, 24=Seattle WA.

## API footguns (all handled in `GtmetrixClient`)

1. **`Content-Type: application/vnd.api+json` is mandatory.** GTmetrix implements JSON:API 1.0. Laravel's default `application/json` gets a 400 "Request must use Content-Type: application/vnd.api+json" from the submission endpoint.
2. **`location` is an integer ID, not a slug.** v2 dropped slug support — `/locations` returns `code: null` on every entry now. Send the integer or get "ID must be a non-negative integer".
3. **`simulate_device` + `connection` are plan-gated.** The standard paid tier can't use either; sending them 400s with "Invalid Simulate Device ID selected". They're stripped from the submission payload entirely — which is why mobile/desktop strategy is display-only (see below).
4. **Polling on `state` alone wedges past completion.** When a test finishes, GTmetrix flips `data.type` from `test` to `report` and **drops the `state` attribute**. `pollUntilComplete` treats `type=report` as terminal alongside `state=completed` / `state=error`.

## Mobile vs desktop

Display-only on this tier. `/devices` and `/connections` return empty arrays for us, and `/browsers` exposes only desktop Chrome (id=3) and Firefox (id=1) — every scan is desktop Chrome from the chosen datacenter regardless of `--strategy`. The scheduler therefore runs **one** nightly scan (the PSI era ran two). The `strategy` column is still written for row continuity with historical data. If GTmetrix unlocks device emulation on a higher tier, pin `browser` + `simulate_device` in `GtmetrixClient::submitTest` and re-add the desktop scheduler entry.

## Files

- `modules/GTmetrix/src/GtmetrixClient.php` — HTTP client: submit, poll, parse. Bound via `->bind()` (not `->singleton()`) in `GTmetrixServiceProvider` so a credential update via `/settings/integrations` takes effect on the next resolution, not just after a process restart.
- `app/Services/Performance/PerformanceScanResult.php` — engine-agnostic value object; `withEngine()` relabels fallback rows.
- `app/Services/Performance/PerformanceScanRecorder.php` — turns a result into a `site_performance_scans` row + an `action_logs` mirror entry.
- `app/Console/Commands/RunPerformanceScans.php` — orchestration incl. GTmetrix→PSI fallback.
- Config: `config/clockwork.php` → `gtmetrix` key.

## Scheduled jobs that depend on it

| Cadence | Command | Notes |
|---|---|---|
| daily 04:45 | `clockwork:run-performance-scans --strategy=mobile --weekly-rotation` | Tonight's 1/7th fleet slice; each site scanned weekly. 04:45 lands just after the ~04:27 UTC credit refill. `--strategy=mobile` kept for historical row continuity. |

Gated on `Settings('performance_scans.enabled')`.

## Credit budget (why the weekly rotation exists)

API credits refill daily at ~04:27 UTC and **don't accumulate**. To ensure coverage within credit limits, the scheduler passes `--weekly-rotation`: sites ordered by domain, sliced into 7 buckets by `index % 7 == UTC weekday`, ~11 sites/night. The Advanced plan provides 50 credits/day, and the weekly rotation keeps generous headroom for manual runs and retries.

## Storage

`site_performance_scans` — one row per run, tracking `engine` (`gtmetrix` / `psi` / `psi-fallback` / `pressable`). `region` holds the GTmetrix location ID as a string (column is varchar; kept the migration cheap) — Pressable rows write the literal string `pressable` instead. `accessibility_score`/`best_practices_score`/`seo_score` stay null for GTmetrix rows (GTmetrix's API doesn't expose those categories on our tier). Full column list on the [PSI page](/docs/integrations/pagespeed-insights) and in [Architecture → Data model](/docs/architecture/data-model).

## Gotchas

- **A scan is submit + poll, 15-60s typical.** Busy queues and heavy pages can push past 120s; the poll ceiling is 180s, submission HTTP timeout 120s. Genuinely-slower-than-that tests are usually pages Lighthouse can't measure stably anyway.
- **GTmetrix and PSI scores aren't comparable.** Different throttling, datacenters, and Chrome builds. Filter trend queries by `engine` when analyzing across engines.
- **Quota is per paid plan, not free-tier-generous like PSI.** Credits refill daily and don't bank — see the credit-budget section. A full-fleet manual run can starve the scheduled 04:45 slice onto the PSI fallback; prefer `--site=X` for one-offs.
- **Errors surface verbatim.** Submission and test errors land in the row's `error` column straight from GTmetrix's JSON:API `errors[].detail`, so "quota hit" vs "site unreachable from region" is distinguishable at a glance.
- **Circuit-breaker is engine-agnostic.** 3 consecutive failed rows flip `sites.psi_unavailable_at` (historical name, now means "performance scan unavailable") and the scheduler skips the site until a successful scan auto-clears it.
