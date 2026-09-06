---
title: Weird Stats
section: Features
order: 140
updated: 2026-09-05
author: Aaron Reimann
tags: [stats, analytics, security, traffic, dashboard]
tracks: [app/Http/Controllers/WeirdStatsController.php, app/Services/Stats/WeirdStatsAggregator.php, app/Console/Commands/WarmWeirdStats.php, resources/views/settings/weird-stats.blade.php, resources/views/settings/weird-stats/**]
---

`/settings/weird-stats` — fleet-level patterns that don't compose into a single picture on any of the regular per-site or per-server dashboards. Read-only, no interaction, one page with seven blocks: four summary tiles, four "selling point" stats, and ranked tables.

## Why it exists

Everything else in Clockwork is scoped to a site, a server, or a single issue. Weird Stats is deliberately the opposite — it exists to answer questions that only make sense fleet-wide: which paths get hit hardest across every site at once, whether Cloudflare-proxying measurably reduces attack volume, which IPs show up in more than one server's jail. Nothing here drives an alert or a workflow; it's for spotting a pattern a human wouldn't otherwise go looking for.

## The seven blocks

`WeirdStatsAggregator` has public methods wired through by `WeirdStatsController::index()` with no view-layer logic of its own:

| Method | Block | Window |
|---|---|---|
| `summaryTiles()` | Total sites, attacks (7d), active bans, unprotected-site count | 7d / live |
| `settlingPointStats()` | Four sub-stats below (CF reduction, auto-ban speedup, tier density, self-ban prevention) | mixed |
| `pluginCoverageMatrix()` | LLAR/Wordfence coverage broken down by Cloudflare state | live |
| `topSitesByVisits()` | Top 10 sites by visits, with concentration framing (top-10 % of fleet, 1st:10th ratio) | 30d |
| `mostAttackedPaths()` | Top 10 request paths fleet-wide, with status-code mix (404-heavy = scanner probe, 200/302-heavy = legitimate load) | 7d |
| `worstRepeatOffenders()` | Top 10 banned IPs ranked by **distinct servers hit**, not raw ban count — the "this attacker is everywhere" framing | all-time |
| `unprotectedSitesByTraffic()` | Sites with both LLAR and Wordfence off, sorted by 30-day visits so the highest-traffic exposure sorts to the top | 30d |

`settlingPointStats()` in turn covers four sub-stats meant to quantify the value of specific operator setup choices rather than surface a problem:

- **`cf_attack_reduction`** — attacks-per-site for Cloudflare-proxied sites vs. sites not using Cloudflare, expressed as a percent reduction. Returns `null` when either bucket has zero sites (nothing to compare).
- **`auto_ban_speedup`** — average review-queue decision time for `auto-repeat` bans vs. `manual` ones, expressed as a speedup factor.
- **`tier_density`** — servers/sites/avg-sites-per-server broken down by the `Dedicated`/`Shared`/`Staging` tags.
- **`self_ban_prevention`** — how many would-be bans the CF/fleet-IP ignore-list matcher dismissed at ingest over the last 30 days (`decided_by = 'system-cf-filter'`), plus coverage numbers (fleet server count, CF IP ranges covered). `jail_protected_ips` is hardcoded to 0 — fail2ban jail state isn't mirrored into this app in real time, and the last audit found 0 protected IPs actually in an active jail, so it's reported as a floor rather than computed live.

## What "visits" means here

A visit is a **distinct IP per site per UTC day**, excluding `403` responses and static-asset paths (`.js`, `.css`, images, fonts) — the WP Engine-style definition, closer to "humans who looked at the site today" than "page views." Source is the hourly `threat_logs` → `site_traffic_daily` rollup (`clockwork:rollup-traffic`; see [Features → Traffic + capacity](/docs/features/traffic-and-capacity)).

Two known biases the page itself documents in a `<details>` disclosure, worth repeating here since they directly affect how to read the Top Sites and Unprotected Sites tables:

- **Bots are not filtered out at rollup time** — doing so made the rollup query 50-100x slower, so sites without Cloudflare have visit counts inflated by bot traffic.
- **Cloudflare-proxied sites under-count** — the origin only ever sees CF edge IPs, so distinct-IP-per-day collapses every real visitor behind the same handful of edge addresses. Cross-CF-state comparisons are noisier than the raw numbers suggest.

## Caching

Stats sourced from `threat_logs` (millions of rows/day: `mostAttackedPaths()`, `attackHourHistogram()`, the `attacks_7d` tile, `cfAttackReduction()`) are cached for 60 minutes under `weird_stats:*` keys. Everything else (sites, blocked_ips, site_traffic_daily — all small or already pre-rolled-up) runs live on every page load; v1 doesn't cache those.

`clockwork:warm-weird-stats` runs every 9 minutes (`*/9 * * * *`, `withoutOverlapping(15)`, backgrounded) specifically to keep the cache from ever expiring under a real page visit — cold compute on the `threat_logs`-derived stats is ~30 seconds on a 2M+ row table, past PHP-FPM's typical `max_execution_time`. The 60-minute TTL is deliberately far longer than the 9-minute warm cadence: if the warmer itself dies for an hour, a visitor still sees slightly-stale cached data instead of a 30-second hang. The most-attacked-paths query specifically needs a `USE INDEX` hint (`threat_logs_event_at_request_path_index`, from the `2026_05_02_080000_add_request_path_index_to_threat_logs` migration) — without it MySQL's optimizer picks a full table scan + filesort and the query takes 30+ seconds even warm.

## Security note

`SecurityCheck`'s `cache_credential_leak` check scans `weird_stats:*` cache entries for anything credential-shaped, since these are the largest cached payloads in the app and a natural place to double-check nothing sensitive leaked into a cache key or value. See [Architecture → Security model](/docs/architecture/security-model).
