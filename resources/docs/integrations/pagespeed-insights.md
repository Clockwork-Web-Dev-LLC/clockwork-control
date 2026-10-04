---
title: Google PageSpeed Insights
section: Integrations
order: 80
updated: 2026-10-03
author: Aaron Reimann
tags: [integrations, performance, lighthouse, google, care-plan, pressable, modularization]
tracks: [modules/PageSpeedInsights/src/PageSpeedInsightsClient.php, modules/PageSpeedInsights/src/PageSpeedInsightsServiceProvider.php, app/Console/Commands/RunPerformanceScans.php]
---

> **Modular performance engine.** Packaged as `modules/PageSpeedInsights` (`clockwork/pagespeed-insights`) and self-registered in `ModuleRegistry`. [GTmetrix](/docs/integrations/gtmetrix) serves as the primary performance engine, with PSI available as the fallback or standalone engine.

Google's PageSpeed Insights v5 API runs a Lighthouse scan on demand — the same engine Google uses for its own SEO ranking, free.

## Why it was demoted

PSI originally served as our primary performance engine. It's free (25k req/day) and authoritative, but its day-over-day variance is ±10-15 points on an unchanged page: Lighthouse simulated-throttling noise, a different Google datacenter each run, per-run Chrome version drift, and CrUX field-data blending. That noise floor made real regressions indistinguishable from nothing. GTmetrix pins the test environment and gets variance down to roughly ±5.

## Current role

`RunPerformanceScans` tries GTmetrix first — for SpinupWP sites. On a GTmetrix failure it retries via PSI (unless the site is flagged `psi_unavailable_at`), and the resulting row is relabeled `engine='psi-fallback'` so the dashboard can distinguish "PSI ran because GTmetrix errored tonight" from a primary PSI scan. If the fallback also fails, the GTmetrix error is what gets recorded.

Pressable sites never enter this chain at all — they use Pressable's own always-on Lighthouse report via `PressableLighthouseClient` (`engine='pressable'`). See [Features → Performance scans](/docs/features/performance-scans).

Force a PSI-only run (recovery probes, backfills):

```bash
php artisan clockwork:run-performance-scans --site=42 --engine=psi
```

## Setup

1. Google Cloud Console → enable "PageSpeed Insights API" on your project.
2. Google Cloud Console → enable "Chrome UX Report API" on the same project (required for real-user field data).
3. Credentials → Create API key.
4. Put in `.env`:

   ```
   CLOCKWORK_PSI_API_KEY=AIzaSy...
   # Optional: specify a distinct key for CrUX, otherwise falls back to CLOCKWORK_PSI_API_KEY
   CLOCKWORK_CRUX_API_KEY=AIzaSy...
   ```

No key required strictly speaking for basic PSI — the Google API permits anonymous calls at a lower rate limit, but the key avoids shared-IP throttling on busy agency networks. The Chrome UX Report API requires an enabled key.

## Endpoints we call

### PageSpeed Insights v5 (Lab Data)
`GET https://www.googleapis.com/pagespeedonline/v5/runPagespeed`

Query params: `url`, `key`, `strategy=mobile` (or `desktop`), repeating `category=performance&category=accessibility&category=best-practices&category=seo`.

Response is parsed via `PageSpeedInsightsClient`. We extract:

- Performance score (0–100)
- Accessibility, Best Practices, and SEO scores (0–100)
- Core Web Vitals: LCP, FCP, TBT, Speed Index, CLS
- Page weight (bytes), network request count

### Chrome UX Report API (Real-User Field Data)
`POST https://chromeuxreport.googleapis.com/v1/records:queryRecord?key={key}`

Payload JSON: `{"origin": "https://{domain}", "formFactor": "PHONE"}` (and `DESKTOP`).

Response is parsed via `ChromeUxReportClient`. We extract:
- 75th percentile values for LCP, INP, FCP, TTFB, and CLS
- Histogram distribution buckets (`good_pct`)
- Rolling 28-day collection period bounds (`period_start`, `period_end`)
- Core Web Vitals overall pass status (`cwv_pass`)
- HTTP 404 responses are classified as `no_data` (insufficient Chrome traffic) rather than errors.

## Scheduled jobs that depend on it

- `clockwork:run-performance-scans` (daily 04:45 UTC, 1/7th rotation) is GTmetrix-primary and only reaches PSI on GTmetrix failure.
- `clockwork:collect-field-metrics` (weekly Sundays 05:30 UTC) queries the Chrome UX Report API for all active care-plan sites.

## Storage

- `site_performance_scans` — one row per lab run. Columns: `site_id`, `scanned_at`, `status` (`ok|failed`), `strategy`, `engine` (`gtmetrix|psi|psi-fallback`), `performance_score` (0-100), `lcp_ms`, `fcp_ms`, `tbt_ms`, `si_ms`, **`cls_x1000`** (CLS × 1000 to avoid float drift in MySQL — divide on read), `accessibility_score`, `best_practices_score`, `seo_score`, `page_weight_bytes`, `request_count`, `page_url`, `region`, `error`, `elapsed_ms`.
- `site_field_metrics` — one row per site × form factor × scope × collection period. Columns: `site_id`, `form_factor` (`phone|desktop`), `scope` (`origin|url`), `status` (`ok|no_data|failed`), `lcp_p75_ms`, `inp_p75_ms`, `fcp_p75_ms`, `ttfb_p75_ms`, `cls_p75_x1000`, `good_pct`, `cwv_pass`, `period_start`, `period_end`, `collected_at`, `error`. Unique on `(site_id, form_factor, scope, period_end)`.


Each scan also writes a summary row to `action_logs` (`TYPE_PERFORMANCE_SCAN`), which auto-pushes to Companion's Performance admin page so the client sees the same numbers we do.

## Gotchas

- **Repeating `category=` is required.** `http_build_query` array encoding produces `category[0]=`, which PSI v5 ignores. The client concatenates `category=performance&category=accessibility&category=best-practices&category=seo`. One HTTP call is still one Lighthouse run and one quota unit.
- **PSI is genuinely slow.** 20–60s per scan is normal; a stuck Lighthouse run can push past 60s. The default 90s timeout gives headroom without hanging the loop forever on a wedged remote browser.
- **PSI and GTmetrix scores aren't comparable.** Different throttling and environments. Filter by `engine` when analyzing historical trends.
- **CLS is stored ×1000 as integer.** Don't compare raw column values to display values (e.g., 125 in the column is 0.125 to a user).
- **Care-plan only.** The runner skips sites with `care_plan_enabled=false`. Hosting-tier clients see "what care plan would add" copy on the Companion Performance page.
- **The circuit-breaker columns kept PSI's name.** `sites.psi_unavailable_at` / `psi_unavailable_reason` now mean "performance scan unavailable" regardless of engine — 3 consecutive failures flip it, a successful scan clears it.
