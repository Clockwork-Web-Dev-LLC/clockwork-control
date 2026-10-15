---
title: Performance scans
section: Features
order: 50
updated: 2026-10-15
author: Aaron Reimann
tags: [performance, lighthouse, gtmetrix, psi, care-plan, pressable]
tracks: [app/Services/Performance/**, app/Console/Commands/RunPerformanceScans.php, modules/Pressable/src/PressableLighthouseClient.php, modules/Pressable/src/PressableClient.php]
---

A weekly Lighthouse score per care-plan site (via a nightly 1/7th-of-fleet rotation), via GTmetrix's REST API v2 (primary) with Google PageSpeed Insights as a fallback. Pressable sites aren't in the rotation: Pressable's own report is read nightly instead (see "Pressable sites" below). Stored in `site_performance_scans`, mirrored to Companion's Performance admin page so the client sees the same numbers.

**This GTmetrix→PSI chain covers every site except Pressable ones** (SpinupWP, GridPane, custom and other hosts). Pressable sites skip it entirely — `RunPerformanceScans` branches on whether the site's host supports `HostingProvider::CAP_PERFORMANCE_SCAN` (only Pressable does) and uses `PressableLighthouseClient` instead, which wraps Pressable's own built-in Lighthouse report (`engine=pressable`). No fallback chain needed there since it's not a rate-limited third-party API — but it also isn't generated fresh on every poll (see "Pressable's report regenerates monthly — the dedup guard" below, a real gotcha the nightly read has to account for). See below.

## Why GTmetrix is our primary engine

PSI's day-over-day variance was operationally untenable: the same unchanged homepage routinely scored ±10-15 points (Lighthouse simulated-throttling noise, a different Google datacenter each run, per-run Chrome version drift, CrUX field-data blending). Operators couldn't tell real regressions from the noise floor.

GTmetrix pins the test location, browser version, and connection profile — observed variance drops to roughly ±5. The architecture was engine-agnostic from day 1 (value object + recorder + table shape), so the swap needed no UI changes. See [Integrations → GTmetrix](/docs/integrations/gtmetrix) for API details.

PSI stays wired up as a fallback: if GTmetrix errors on a site, the same run retries via PSI so a transient outage doesn't lose that site's nightly data point.

## What's stored per scan

- Performance score (0-100).
- Core Web Vitals: LCP, FCP, TBT, SI, CLS.
- **Accessibility, Best Practices, and SEO scores** (0-100 each, nullable). Every engine's raw API response carries these alongside the Performance score. Populated for both **PageSpeed Insights** and **Pressable** rows — `PageSpeedInsightsClient` and `PressableLighthouseClient` extract them, while GTmetrix's current tier focuses primarily on Performance metrics.
- Page weight (bytes), request count.
- Strategy (mobile or desktop), region, the URL scanned.
- **Engine**: `gtmetrix`, `psi`, `psi-fallback`, or `pressable`. `psi-fallback` means GTmetrix errored that night and PSI filled in — so the column always tells primary data from safety-net data. `pressable` is Pressable's own report, not part of the GTmetrix/PSI chain at all (see below).
- **`source_generated_at`** (nullable timestamp) — when the underlying report was actually generated, distinct from when we polled it. Null for GTmetrix/PSI (every call there is a genuinely fresh scan). Set for Pressable rows — see the dedup section below for why this column exists.

CLS is stored as `cls_x1000` (CLS × 1000 as integer) to avoid float drift in MySQL — divide by 1000 on read.

## Where to look

- **`/sites/{id}/overview` Performance card** — latest mobile and desktop scores.
- **`/sites/{id}/performance` tab** — 30-day score trend chart plus scan history.
- **Companion → Tools → Clockwork → Performance** — the client view. Hero cards, full Core Web Vitals breakdown, scan history table.

## Cadence

**Three scheduled runs per night:** 04:45 UTC scans 1/7th of the GTmetrix/PSI fleet (`--weekly-rotation`), so each of those sites gets one primary-engine scan per week; 05:05 (mobile) and 05:15 (desktop) read Pressable's own report for every Pressable care-plan site (`--host-reports`). Host-report reads are free, need no GTmetrix/PSI key, and never trip the "unavailable" flag (that flag protects the paid quota): a failed read is just retried the next night.

Why the rotation: GTmetrix refills API credits daily at ~04:27 UTC and **credits don't accumulate**. The full-fleet nightly run would burn credits quickly and fall back to PSI. The rotation slices sites (ordered by domain) into 7 near-equal buckets by `index % 7 == UTC weekday`, ~8 sites per night now that Pressable sites are left out, which fits comfortably within the daily budget. A site's scan weekday can shift when sites are added/removed — fine, since scores are compared week-over-week either way.

The run time is scheduled at 04:45 UTC to land just *after* the credit refill. The Advanced plan provides 50 credits/day, and the weekly rotation keeps generous headroom for on-demand manual scans.

It's one scan per site per run, not two. GTmetrix's tier doesn't expose device emulation (`/devices` + `/connections` return empty; `/browsers` only lists desktop Chrome and Firefox), so "mobile" and "desktop" strategies would submit identical desktop-Chrome scans. Running twice would double quota usage for identical data.

The scheduled command keeps `--strategy=mobile` purely so new rows line up with pre-cutover PSI rows in historical analysis — the flag is display-only on this tier. If GTmetrix unlocks mobile device emulation on a higher tier, add a desktop `--weekly-rotation` scheduler entry and restore per-strategy attributes in `GtmetrixClient::submitTest`.

Gated on `Settings('performance_scans.enabled')` and on the per-site `care_plan_enabled` flag. Hosting-tier sites are skipped — they see "what care plan would add" upsell copy on the Companion Performance page.

## Test region

GTmetrix tests run from a pinned datacenter. The global default is location ID **4 (San Antonio TX)** — the closest US-central replacement for GTmetrix's discontinued Dallas location — via `CLOCKWORK_GTMETRIX_REGION`. A per-site override lives on `sites.performance_scan_region` (NULL = use the global default). Values are integer GTmetrix location IDs; list them with:

```bash
curl -u <api-key>: https://gtmetrix.com/api/2.0/locations | jq
```

## Manual run

```bash
# Single site (ID or domain) — bypasses the care-plan filter and the unavailable gate:
php artisan clockwork:run-performance-scans --site=42

# Tonight's 1/7th rotation slice (what the scheduler runs):
php artisan clockwork:run-performance-scans --strategy=mobile --weekly-rotation

# ALL care-plan sites in one run — burns a week of credits, use deliberately:
php artisan clockwork:run-performance-scans --strategy=mobile

# Force one engine, bypassing the fallback chain (probing recovery, backfills):
php artisan clockwork:run-performance-scans --site=42 --engine=gtmetrix
php artisan clockwork:run-performance-scans --site=42 --engine=psi
```

## Fallback orchestration

`RunPerformanceScans::scanSiteWithFallback` per site:

1. GTmetrix configured? Run it. Success → done, row tagged `gtmetrix`.
2. GTmetrix failed and PSI is configured (and the site isn't already flagged unavailable)? Run PSI. Success → row tagged `psi-fallback`.
3. If the fallback also failed, the **GTmetrix** error is recorded — it's the engine we're really running on, and "PSI is also unhappy" adds little signal.
4. GTmetrix key unset entirely → straight PSI (handy in local dev).

One row per site per run either way. `psi-fallback` rows should be rare — if they aren't, either PSI isn't the right safety net or the run is exceeding the GTmetrix credit budget (the exact failure mode the weekly rotation exists to prevent).

## What "failed" means

A GTmetrix test submits instantly, then runs 15-60s while we poll (5s interval, 180s ceiling). A failed scan writes a row with `status=failed` and an `error` string carrying GTmetrix's verbatim error detail — so you can tell "invalid location" from "quota hit" from "URL unreachable from the test region." The next day's run usually succeeds.

## Circuit-breaker

If scans repeatedly fail for a site (3 consecutive `failed` rows, across either engine), `RunPerformanceScans` sets `sites.psi_unavailable_at` and `psi_unavailable_reason` and stops scheduling scans for it. The column name is historical — it now means "performance scan unavailable" regardless of engine; kept to avoid a schema rename mid-cutover.

Recovery is automatic: any successful scan (via `--site=X` smoke-test, or an `--include-unavailable` probe) clears the flag — no manual reset needed.

| Flag | Effect |
|---|---|
| `--site=X` | Bypasses both the care-plan filter and the unavailable gate. Use for manual retry. |
| `--include-unavailable` | Runs against flagged sites for "is the engine back?" probes. |
| `--engine=gtmetrix\|psi` | Forces one engine, bypassing the fallback chain. Ignored for Pressable sites (always read from Pressable's report). |
| `--weekly-rotation` | Scan only tonight's 1/7th slice of the fleet (GTmetrix/PSI sites; Pressable sites are left out). What the scheduler passes. Ignored when `--site` is given. |
| `--host-reports` | Only sites whose host runs its own report (Pressable), read nightly. Ignored when `--site` is given. |

The site's Performance tab surfaces `psi_unavailable_reason` as a banner so you know why scans stopped.

## Pressable sites — a third engine, no fallback chain, monthly cadence

`PressableLighthouseClient::scanSite()` calls Pressable's own performance-report endpoint (`PressableClient::sitePerformanceReport()`) and maps its `mobile_report`/`desktop_report` sub-object onto the same `PerformanceScanResult` shape GTmetrix and PSI produce — same columns, same Companion Performance page, `engine='pressable'` in `site_performance_scans`. Pressable returns accessibility/best-practices/SEO scores (now stored — see above), and the Core Web Vitals fields line up 1:1 with what GTmetrix/PSI populate.

Because Pressable's report is always-on (not a rate-limited third-party quota), there's no fallback chain for these sites. **As of 2026-10-15 Pressable sites are read nightly, not in the rotation**: `--host-reports` selects only sites whose host supports `CAP_PERFORMANCE_SCAN`, and `--weekly-rotation` now leaves them out. Two scheduled runs (05:05 mobile, 05:15 desktop) read every Pressable care-plan site. Why: Pressable runs its own test about once a month per site (on a varying day, with no way to trigger one), and under the rotation a new report could sit unread for up to three weeks, so many sites had no score in a given month when client reports went out on the 1st. Reading is free and an unchanged report is skipped by the dedup guard below, so a new report now lands within a day. Client reports date these results by `source_generated_at` (when Pressable ran the test), not `scanned_at` (when we read it).

### Pressable's report regenerates monthly — the dedup guard

**"Always-on" doesn't mean "freshly generated on every poll."** Pressable's underlying reports regenerate on a monthly cadence rather than daily. Polling nightly without deduplication would repeatedly store the same report snapshot. The guard compares with the last *stored report* (the newest row with a `source_generated_at`), not the last row, so a failed read in between doesn't make the old report look new.

Fixed with `site_performance_scans.source_generated_at` — the report's own generation timestamp, distinct from when we happened to poll it (null for GTmetrix/PSI, where every call is a genuinely fresh scan). `PerformanceScanRecorder::record()` compares a new result's `source_generated_at` against the last stored report (newest row with a `source_generated_at`) for that `(site, strategy, engine)`; an exact match means nothing actually changed since last time, and the insert is skipped — `record()` returns `null` instead of a new row. `RunPerformanceScans` treats that as a third bucket alongside ok/failed: `duplicate_skipped`, logged in the run summary and shown per-site with `-v`. Not a failure — there's just nothing new to store yet.

Practically: a Pressable site's `site_performance_scans` history will show real gaps of roughly a month between rows, not a row every night. That's correct — it's real signal frequency, not a broken schedule.

## What this replaces

ManageWP's "Performance Check" feature. We get a more authoritative number (Chrome's Lighthouse vs ManageWP's roll-your-own), with a pinned test environment ManageWP never offered. Cadence is weekly per site (nightly rotation) rather than ManageWP's daily check; Pressable sites are read nightly.

## What this doesn't do

- **Doesn't run the audit ourselves.** GTmetrix does (their managed Chrome, their datacenters). We never operated Lighthouse infrastructure and still don't.
- **Doesn't optimize anything.** Performance scans are diagnostic. The remediation work (image compression, lazy loading, etc.) happens elsewhere.
- **Doesn't gate any feature.** A 12 / 100 score won't stop a deploy. It'll show up red on the dashboard and in the client's Companion view, and that's the signal to do something about it.

## Real-User Core Web Vitals (Chrome UX Report / CrUX)

While GTmetrix and Lighthouse measure simulated lab conditions from a single server location, **Google's Chrome UX Report (CrUX)** records actual user experiences from real Chrome browsers navigating client sites over a rolling 28-day window.

Clockwork queries the CrUX API directly (via `ChromeUxReportClient`) independent of which lab engine a site uses:

### What gets stored (`site_field_metrics`)
- **LCP (Largest Contentful Paint)** at p75 (ms).
- **INP (Interaction to Next Paint)** at p75 (ms) — Google's real-user responsiveness metric replacing FID.
- **CLS (Cumulative Layout Shift)** at p75 (stored as `cls_p75_x1000`).
- **FCP (First Contentful Paint)** and **TTFB (Time to First Byte)** at p75 (ms).
- **Core Web Vitals Pass Assessment (`cwv_pass`)**: `true` when LCP (≤ 2500ms), INP (≤ 200ms), and CLS (≤ 0.1) all meet Google's "Good" threshold at the 75th percentile.
- **Histogram Buckets (`good_pct`)**: The percentage of user visits categorized as "Good" for each metric.
- **Collection Window**: `period_start` and `period_end` date bounds.

### Handling small client sites (`no_data`)
Many small or niche client websites do not receive enough Chrome visitor traffic for Google to meet privacy and statistical thresholds. In those cases, the CrUX API returns HTTP 404. Clockwork records this as a first-class `status = no_data` state:
- It displays gracefully in the UI as *"Not enough Chrome traffic for Google to report real-user data."*
- **It is never treated as a scan failure** and never trips the circuit breaker or alerts operators.

### Where it appears
- **Site Performance Tab (`/sites/{id}/performance`)**: A dedicated **"Real users, last 28 days"** card rendered alongside the lab performance cards. Color-coded pills show Google's official thresholds (Good / Needs Improvement / Poor) for LCP, INP, and CLS.
- **Site Overview Performance Widget**: Displays the CWV Pass badge.
- **Monthly Client Reports (`ClientReportCompiler::compilePerformance`)**: Automatically incorporates real-user Core Web Vitals and TTFB into client-facing performance reports.

### Cadence and Configuration
- **Scheduled Weekly**: `clockwork:collect-field-metrics` runs every Sunday at 05:30 UTC for all active care-plan sites. Because CrUX represents a rolling 28-day window, weekly querying captures fresh data without wasting API quota.
- **API Key**: Requires the **Chrome UX Report API** to be enabled on your Google Cloud Console project. Uses the PageSpeed Insights key: the API key saved on the PageSpeed Insights integration, or `CLOCKWORK_PSI_API_KEY` in `.env`. A separate CrUX key currently has no effect. `CLOCKWORK_CRUX_API_KEY` isn't read because `config/clockwork.php` has no `crux` block. The integration's optional "CrUX API Key" field is saved as `psi.crux_api_key`, but the client looks for `crux.api_key`.
- **Toggle**: Gated by the `performance_scans.field_data_enabled` setting. It defaults to on and has no control in the UI. `CLOCKWORK_CRUX_ENABLED` isn't wired into config, so it has no effect. `--force` runs the command even when the setting is off.

## Gotchas

- **Paid GTmetrix plan required.** The free tier is 5 tests/day — nowhere near a 50-site care-plan run.
- **Mobile vs desktop is display-only.** Both strategies submit identical desktop-Chrome scans on this tier.
- **Engine column matters for trends.** GTmetrix and PSI scores aren't directly comparable (different throttling and environment). Filter by `engine` when analyzing across different scan engines.
- **CLS is integer ×1000.** Don't compare raw column values to display values; divide on read.
- **Sequential, not parallel.** Each scan takes 15-60s and we don't stampede either API. The nightly rotation slice (~8 sites) finishes in ~5-10 minutes; a full-fleet manual run is more like 20-40.
- **GTmetrix credits refill daily and don't bank.** A full-fleet manual run right before the ~04:27 UTC refill can starve the scheduled 04:45 run onto the PSI fallback. Prefer `--site=X` for one-offs.
- **Keys are optional for Pressable.** With neither engine key set, the GTmetrix/PSI runs print an error and exit without scanning (a failed run on the Scheduled Jobs dashboard); the `--host-reports` runs need no key and still read Pressable's report.
- **Circuit-breaker trips at 3 consecutive failures.** Check `sites.psi_unavailable_reason` on the Performance tab and force a retry with `php artisan clockwork:run-performance-scans --site=domain.com`.
- **CrUX 404s are normal.** Do not treat a missing CrUX row as an error — low-traffic sites simply lack Chrome data.

