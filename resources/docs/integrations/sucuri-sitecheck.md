---
title: Sucuri SiteCheck
section: Integrations
order: 95
updated: 2026-09-07
author: Aaron Reimann
tags: [integrations, security, sucuri, malware, care-plan, pressable, modularization]
tracks: [modules/Sucuri/src/SucuriSiteCheckClient.php, modules/Sucuri/src/SucuriServiceProvider.php, app/Console/Commands/ScanSiteCheck.php]
---

> **Modular security engine (ManageWP Suite).** Packaged as `modules/Sucuri` (`clockwork/sucuri`) and self-registered in `ModuleRegistry`. Sucuri SiteCheck provides free, automated remote malware and blacklist scanning for care-plan and monitored sites.

Sucuri SiteCheck is the free public scan API ManageWP and most other "WordPress security" providers resell. We hit it directly — no auth, no fee. Daily per care-plan site (moved from weekly to daily so all three care-plan scans — Sucuri, checksums, blacklist — match the same once-a-day operator expectation).

## Why we use it

This one feature is what ManageWP charged for in its premium tier. Same engine, available for free directly from Sucuri (they're GoDaddy siblings). Replacing the ManageWP security feature with this scan was the entire reason `site_security_scans` exists. Care-plan only — hosting-tier sites get the free blacklist scan instead.

## Setup

Nothing. No auth, no key.

```bash
php artisan clockwork:scan-sitecheck --site=42
```

A one-off scan against a specific site is the easiest way to verify the integration works.

## Auth

None. Public API.

## Endpoints we call

Base URL `https://sitecheck.sucuri.net` (override via `CLOCKWORK_SUCURI_BASE_URL`).

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v3/?scan=<url>` | Scan a site for malware, blacklist hits, software issues, software updates. |

Response is JSON; we extract the verdicts we care about and store one row in `site_security_scans` with `scan_type='sitecheck'`.

## Files

- `app/Services/Security/SucuriSiteCheckClient.php`
- `app/Console/Commands/ScanSiteCheck.php`
- Config: `config/clockwork.php` → `sucuri` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| daily 02:00 | `clockwork:scan-sitecheck` — care-plan sites only, via `Site::hostMonitored()` (includes eligible Pressable sites — this scan is host-agnostic, it hits a public URL either way). Background-scheduled so the long sequential run doesn't block other jobs. Gated on `Settings('security_scans.sitecheck_enabled')`. |

## Rate limits

Sucuri rate-limits the public API around **30 req/min**. The artisan loop sleeps 250 ms between sites to stay comfortably under that ceiling. A 60-site care-plan run takes ~30 seconds plus the actual scan time per site.

## What status means

- `clean` — Sucuri found nothing.
- `issues_found` — Sucuri flagged something. The `details` JSON has the verdict structure.
- `failed` — Sucuri couldn't fetch the site. Most common cause is a CF WAF 403 — Companion's Security page recognises this shape and renders "Blocked by site firewall (likely Cloudflare)" instead of a red "scan failed" alarm. The daily blacklist scan recovers the missing signal directly.

## Gotchas

- **CF WAFs 403 Sucuri's scanner.** Common; not a real failure. The `failed` row is informational. The blacklist scan covers the same signal from a different angle.
- **Runs daily now, not weekly.** Sucuri's database doesn't change minute-by-minute, so daily is more than the underlying data needs — but it keeps this scan's cadence consistent with the other two care-plan security scans (checksums, blacklist) rather than optimizing rate-limit headroom that isn't actually tight (30 req/min comfortably covers a daily fleet run at 250ms/site).
- **Care-plan only.** Hosting-tier sites get the daily blacklist scan instead.
- **Sucuri unavailability circuit-breaker.** If Sucuri's API is unreachable, the scanner sets `sites.sucuri_unavailable_at` on the affected site and skips future scans until it's cleared. This prevents false failures from appearing in the security dashboard during Sucuri outages.
