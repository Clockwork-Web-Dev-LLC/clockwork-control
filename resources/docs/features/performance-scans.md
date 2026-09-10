---
title: Performance scans
section: Features
order: 50
updated: 2026-09-06
author: Aaron Reimann
tags: [performance, lighthouse, gtmetrix, psi, care-plan, pressable]
tracks: [app/Services/Performance/**, app/Console/Commands/RunPerformanceScans.php, modules/Pressable/src/PressableLighthouseClient.php, modules/Pressable/src/PressableClient.php]
---

A weekly Lighthouse score per care-plan site (via a nightly 1/7th-of-fleet rotation), via GTmetrix's REST API v2 (primary) with Google PageSpeed Insights as a fallback. Stored in `site_performance_scans`, mirrored to Companion's Performance admin page so the client sees the same numbers.

**This GTmetrix→PSI chain is SpinupWP-only.** Pressable sites skip it entirely — `RunPerformanceScans` branches on `$site->isPressable()` and uses `PressableLighthouseClient` instead, which wraps Pressable's own built-in Lighthouse report (`engine=pressable`). No fallback chain needed there since it's not a rate-limited third-party API — but it also isn't generated fresh on every poll (see "Pressable's monthly report cadence" below, a real gotcha the nightly scan has to account for). See below.

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

- **`/sites/{id}/overview` Performance card** — latest score plus a 30-day trend.
- **Companion → Tools → Clockwork → Performance** — the client view. Hero cards, full Core Web Vitals breakdown, scan history table.
- **Issues page** — sites whose Performance score has dropped meaningfully since the previous run.

## Cadence

**One scheduled run per night at 04:45 UTC, scanning 1/7th of the fleet** (`--weekly-rotation`) — so each site gets one primary-engine scan per week.

Why the rotation: GTmetrix refills API credits daily at ~04:27 UTC and **credits don't accumulate**. The full-fleet nightly run would burn credits quickly and fall back to PSI. The rotation slices sites (ordered by domain) into 7 near-equal buckets by `index % 7 == UTC weekday`, ~11 sites per night, which fits comfortably within the daily budget. A site's scan weekday can shift when sites are added/removed — fine, since scores are compared week-over-week either way.

The run time is scheduled at 04:45 UTC to land just *after* the credit refill. The Advanced plan provides 50 credits/day, and the weekly rotation keeps generous headroom for on-demand manual scans.

It's one scan per site per run, not two. GTmetrix's tier doesn't expose device emulation (`/devices` + `/connections` return empty; `/browsers` only lists desktop Chrome and Firefox), so "mobile" and "desktop" strategies would submit identical desktop-Chrome scans. Running twice would double quota usage for identical data.

The scheduled command keeps `--strategy=mobile` purely so new rows line up with pre-cutover PSI rows in historical analysis — the flag is display-only on this tier. If GTmetrix unlocks mobile device emulation on a higher tier, re-add the desktop scheduler entry and restore per-strategy attributes in `GtmetrixClient::submitTest`.

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
| `--engine=gtmetrix\|psi` | Forces one engine, bypassing the fallback chain. |
| `--weekly-rotation` | Scan only tonight's 1/7th slice of the fleet. What the scheduler passes. Ignored when `--site` is given. |

The site's Performance tab surfaces `psi_unavailable_reason` as a banner so you know why scans stopped.

## Pressable sites — a third engine, no fallback chain, monthly cadence

`PressableLighthouseClient::scanSite()` calls Pressable's own performance-report endpoint (`PressableClient::sitePerformanceReport()`) and maps its `mobile_report`/`desktop_report` sub-object onto the same `PerformanceScanResult` shape GTmetrix and PSI produce — same columns, same Companion Performance page, `engine='pressable'` in `site_performance_scans`. Pressable returns accessibility/best-practices/SEO scores (now stored — see above), and the Core Web Vitals fields line up 1:1 with what GTmetrix/PSI populate.

Because Pressable's report is always-on (not a rate-limited third-party quota), there's no fallback chain for these sites. The weekly-rotation site-selection query itself doesn't currently distinguish by provider, though — Pressable sites get sliced into the same 1/7th-per-night buckets as everyone else, even though nothing about their engine requires it. Harmless (each Pressable site still gets its weekly scan on schedule) but worth knowing if you're wondering why a Pressable site isn't scanned nightly despite having no credit budget to protect — that's an implementation-simplicity choice, not a limitation of Pressable's report.

### Pressable's report regenerates monthly — the dedup guard

**"Always-on" doesn't mean "freshly generated on every poll."** Pressable's underlying reports regenerate on a monthly cadence rather than daily. Polling on the regular rotation without deduplication would repeatedly store the same report snapshot.

Fixed with `sites_performance_scans.source_generated_at` — the report's own generation timestamp, distinct from when we happened to poll it (null for GTmetrix/PSI, where every call is a genuinely fresh scan). `PerformanceScanRecorder::record()` compares a new result's `source_generated_at` against the last stored row for that `(site, strategy, engine)`; an exact match means nothing actually changed since last time, and the insert is skipped — `record()` returns `null` instead of a new row. `RunPerformanceScans` treats that as a third bucket alongside ok/failed: `duplicate_skipped`, logged in the run summary and shown per-site with `-v`. Not a failure — there's just nothing new to store yet.

Practically: a Pressable site's `site_performance_scans` history will show real gaps of roughly a month between rows, not a row every night. That's correct — it's real signal frequency, not a broken schedule.

## What this replaces

ManageWP's "Performance Check" feature. We get a more authoritative number (Chrome's Lighthouse vs ManageWP's roll-your-own), with a pinned test environment ManageWP never offered, at the same daily cadence.

## What this doesn't do

- **Doesn't run the audit ourselves.** GTmetrix does (their managed Chrome, their datacenters). We never operated Lighthouse infrastructure and still don't.
- **Doesn't optimize anything.** Performance scans are diagnostic. The remediation work (image compression, lazy loading, etc.) happens elsewhere.
- **Doesn't gate any feature.** A 12 / 100 score won't stop a deploy. It'll show up red on the dashboard and in the client's Companion view, and that's the signal to do something about it.

## Gotchas

- **Paid GTmetrix plan required.** The free tier is 5 tests/day — nowhere near a 50-site care-plan run.
- **Mobile vs desktop is display-only.** Both strategies submit identical desktop-Chrome scans on this tier.
- **Engine column matters for trends.** GTmetrix and PSI scores aren't directly comparable (different throttling and environment). Filter by `engine` when analyzing across different scan engines.
- **CLS is integer ×1000.** Don't compare raw column values to display values; divide on read.
- **Sequential, not parallel.** Each scan takes 15-60s and we don't stampede either API. The nightly rotation slice (~11 sites) finishes in ~5-10 minutes; a full-fleet manual run is more like 20-40.
- **GTmetrix credits refill daily and don't bank.** A full-fleet manual run right before the ~04:27 UTC refill can starve the scheduled 04:45 run onto the PSI fallback. Prefer `--site=X` for one-offs.
- **Keys are optional.** With neither engine key set, the scheduled command short-circuits without erroring.
- **Circuit-breaker trips at 3 consecutive failures.** Check `sites.psi_unavailable_reason` on the Performance tab and force a retry with `php artisan clockwork:run-performance-scans --site=domain.com`.
