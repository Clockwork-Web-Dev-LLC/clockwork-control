---
title: SEO indexability watchdog
section: Features
order: 66
updated: 2026-09-06
author: Aaron Reimann
tags: [seo, indexability, noindex, robots, watchdog, monitoring]
tracks: [app/Services/Seo/**, app/Console/Commands/CheckRobotsTxt.php]
---

Continuous automated monitoring to prevent the accidental de-indexing of production sites. Detects stray `noindex` meta tags, `X-Robots-Tag` HTTP response headers, and blanket `Disallow: /` rules in `robots.txt` before search engine organic traffic is harmed.

## Detection vectors

Clockwork checks three independent indexability vectors with strict precedence:

1. **Meta tags** (`meta_noindex`) — DOM-parsed `<meta name="robots" content="...noindex...">` and `<meta name="googlebot" content="...noindex...">` tags. Handled via PHP `DOMDocument` and `DOMXPath` with libxml error suppression so malformed HTML or HTML comments containing `noindex` never cause false positives.
2. **HTTP headers** (`header_noindex`) — `X-Robots-Tag` HTTP header values emitted by web servers, reverse proxies, or caching layers.
3. **Robots.txt** (`robots_disallow_all`) — Root `/robots.txt` parsed via `bopoda/robots-txt-parser`, evaluating RFC-compliant longest-match precedence so explicit `Allow:` exceptions are respected.

## Staging vs production behavior

- **Production sites**: An active blocking vector transitions the site to `seo_indexable = false`, surfaces as a critical red issue on `/issues`, and immediately fires a high-priority chat notification (`seo_indexability_blocked`). Restoring indexability fires a recovery notification (`seo_indexability_recovered`).
- **Staging environments**: Staging sites (servers tagged `staging` via `$site->server?->isStaging()`) are expected to block search engines. They appear as a neutral gray **Protected from Search** status on the Issues page and **never** trigger chat alerts.

## Zero HTTP overhead for homepage checks

Clockwork inspects homepage meta tags and `X-Robots-Tag` headers **with zero additional HTTP requests** by piggybacking on the existing 5-minute `clockwork:check-site-uptime` cycle. The uptime prober retains the already-fetched homepage body and response headers in memory for `IndexabilityChecker::checkFromProbeResult()`.

The `/robots.txt` URL is fetched independently once daily via `clockwork:check-robots-txt` at **07:15 UTC**.

## On-demand pre-flight launch check

Before promoting staging sites to production or after deploying changes, operators can run an immediate synchronous check across all three vectors:
- Click **Pre-flight check** on the `/issues` page table row.
- Or send an authenticated HTTP request: `POST /sites/{site}/seo/pre-flight-check`.

The endpoint executes fresh live fetches of the homepage and `/robots.txt`, updates the site state, and returns a JSON summary:

```json
{
  "ok": true,
  "indexable": true,
  "status_label": "Indexable",
  "reason": null,
  "snippet": null,
  "is_staging": false,
  "meta": null,
  "header": null,
  "robots": null
}
```

## Manual run

Check `robots.txt` across the fleet:
```bash
php artisan clockwork:check-robots-txt
```

Single site:
```bash
php artisan clockwork:check-robots-txt --site=42
```

## Configuration

Configurable in `config/clockwork.php` or environment variables:
- `CLOCKWORK_SEO_MONITORING_ENABLED` (default: `true`)
- `CLOCKWORK_SEO_ROBOTS_TXT_TIMEOUT` (default: `10` seconds)

## What this isn't

- **Not an SEO audit tool.** This watchdog does not analyze keyword density, headings, or Core Web Vitals. It exclusively prevents accidental de-indexing by search engine crawlers.
- **Not a crawler simulator.** It verifies directives on the homepage and root `/robots.txt`, where accidental fleet-wide blocks occur.
