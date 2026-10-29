---
title: SEO module
section: Features
order: 67
updated: 2026-10-29
author: Aaron Reimann
tags: [seo, indexability, modules]
tracks: [modules/Seo/**]
---

> Packaged as `modules/Seo` (module id `seo`). It's the home for SEO tooling in Clockwork Control. Today it does one thing: shows the indexability of every monitored site on one page.

## What you get

**`/seo`** (gear menu → **SEO**) lists every active, monitored site with its search engine indexability:

- **Blocking Search Engines** (red): a production site sending `noindex` or blocking crawlers in `robots.txt`. These sort to the top.
- **Protected from Search** (gray): the same block on a staging-tagged server. That's expected, so it isn't an alarm.
- **Not checked yet** (yellow): monitoring is on but no probe has run yet.
- **Indexable** (green) and **Disabled** (gray): all clear, or SEO monitoring is off for the site.

The tiles across the top count each state, and the Reason column says which signal tripped (meta `noindex`, `X-Robots-Tag`, or `robots.txt` Disallow).

## Where the data comes from

This module doesn't fetch anything itself. It reads the `seo_*` columns on `sites` that the [SEO indexability watchdog](/docs/features/seo-indexability-watchdog) writes. That watchdog runs with every uptime probe, plus a daily `robots.txt` check. The watchdog stays in core (`app/Services/Seo/`) because the uptime prober and the Issues page depend on it. Turn this module off at **Settings → Modules** and the watchdog, its alerts and its Issues section keep working. Only the `/seo` page and its menu link go away.

## Adding to it

New SEO features go in `modules/Seo/`, using the same layout as the other feature modules: controllers in `src/Http/Controllers/`, views under the `seo::` namespace, and routes in `routes/web.php`, which load only while the module is enabled.
