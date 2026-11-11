---
title: SEO module
section: Features
order: 67
updated: 2026-11-11
author: Aaron Reimann
tags: [seo, indexability, search-console, ga4, modules]
tracks: [modules/Seo/**]
---

> Packaged as `modules/Seo` (module id `seo`). It's the home for SEO tooling in Clockwork Control. It does two things: it shows the indexability of every monitored site, and it syncs Search Console and GA4 data for SEO clients every night so the monthly report's numbers come from Control instead of from Chrome.

## What you get

**`/seo`** (gear menu → **SEO**) lists every active, monitored site with its search engine indexability:

- **Blocking Search Engines** (red): the site is sending `noindex` or blocking crawlers in `robots.txt`. These sort to the top.
- **Not checked yet** (yellow): monitoring is on but no probe has run yet.
- **Indexable** (green): all clear.
- **Disabled** (gray): SEO monitoring is off for the site.

The tiles across the top count each state, and the Reason column says which signal tripped (meta `noindex`, `X-Robots-Tag`, or `robots.txt` Disallow). The **Search data** column links each site to its numbers and profile, or to **Set up**. A red banner shows when the Google connection was revoked.

Sites on staging-tagged servers aren't listed. Monitoring skips those servers, so the watchdog never checks them.

## Where the indexability data comes from

That page doesn't fetch anything itself. It reads the `seo_*` columns on `sites` that the [SEO indexability watchdog](/docs/features/seo-indexability-watchdog) writes. That watchdog runs with every uptime probe, plus a daily `robots.txt` check. The watchdog stays in core (`app/Services/Seo/`) because the uptime prober and the Issues page depend on it. Turn this module off at **Settings → Modules** and the watchdog, its alerts and its Issues section keep working.

## Search Console and GA4 sync

The plan behind this is `plans/seo-semrush-replacement.md` (Phase 1).

### One-time setup

1. **Google Cloud project.** In the agency's Google Workspace organization (clockworkwd.com), create a project with the OAuth consent screen set to **Internal**. Internal apps skip Google's verification and the 7-day testing expiry.
2. **Enable three APIs:** Google Search Console API, Google Analytics Data API, Google Analytics Admin API.
3. **OAuth client.** Credentials → Create OAuth client ID → Web application. Add the redirect URI that **SEO → Settings** shows, `https://<control host>/seo/google/callback`. It differs from the login callback (`/auth/google/callback`) on purpose.
4. **Enter the client.** Paste the client ID and secret on **Settings → Integrations → SEO** (stored encrypted). `CLOCKWORK_SEO_GOOGLE_CLIENT_ID` / `_SECRET` in `.env` are the fallback.
5. **Connect.** An admin opens **SEO → Settings → Connect Google** and signs in as **systems@clockworkwd.com**. Whatever that account can see in Search Console and GA4 is what Control can sync; nothing is set up per client in Google. Control asks only for read-only access.

Reconnect only when needed: Google keeps at most 100 refresh tokens per account and client, and silently drops the oldest.

### Per-site profiles

Each SEO client gets a profile at `/seo/sites/{site}/profile` (any active user):

- **Search Console property**, picked from the list the connection sees (with its access level). Keep the URL-prefix property the monthly reports already use; systems@ often can't read the `sc-domain:` property. **Refresh list** queues a new `sites.list`.
- **GA4 property ID** (GA4 Admin → Property details), and a checkbox when the property has a custom primary channel group.
- **Brand terms** (comma separated). Queries containing any of them are left out of the non-branded numbers. The preview shows which of last month's top queries count as branded.
- **Key events** (default `generate_lead`, `form_submit`, `contact_link_click`), the seasonal YoY toggle, the Semrush domain, and **report notes** (tone, angle, things to avoid). Report notes can only be edited on this page.
- **AI impressions** from Search Console's Generative AI report, which has no API: type the month's number, or mark it "not available". It's never stored as 0.

Saving a new profile pulls 16 months of history in the background. Changing a property asks first, then deletes that property's stored history for the site and re-pulls 16 months, so comparisons never mix two properties. Changing brand terms re-marks stored queries and re-pulls the non-branded daily totals Google still holds; when older stored days keep the old terms, an annotation at the boundary puts a caveat on comparisons that cross it.

### The nightly sync

`clockwork:seo-sync-google` runs at **08:10 UTC** (after midnight Pacific, when Search Console's day has closed), one site at a time:

- **Search Console:** daily site totals (all and non-branded), re-pulling the last 5 days because late data keeps filling in; and page, query and page×query rows per calendar month until the month is **final** (month end + 5 days, set on SEO → Settings).
- **GA4:** daily channel and AI-referral rows for charts, and month figures from a request for the whole calendar month. GA4 counts sessions approximately and a session crossing midnight counts on both days, so summed days never equal the month figure in GA4.

A quota answer from Google stops that site for the night; the trailing window catches up the next night. One site's error doesn't stop the next. The command exits failed if any site errored or the connection was revoked, so it shows on the Scheduled Jobs page. **Sync now** on a profile queues one site.

If Google revokes the connection, Control marks it revoked, sends one chat message, and shows a banner on `/seo` until an admin reconnects. Disconnecting on purpose stops the schedule; stored data is kept.

### Reading the numbers

`/seo/sites/{site}` shows a month (default: last month) against the previous month and the same month last year: clicks, impressions, CTR, average position, non-branded clicks and impressions, organic keywords, organic sessions, engaged sessions, engagement rate, AI assistant sessions, each key event, and AI impressions with their share of web impressions. Caveats are listed under the table:

- **Not comparable.** Google's `num=100` change (2025-09-10 to 14) cut impressions and lifted average position overnight. Position comparisons whose baseline starts on or before 2025-09-14 show "not comparable"; impressions and CTR get a caveat.
- **Provisional** until month end + 5 days.
- **Non-branded = known queries only**: Google never lists anonymized queries.
- **Organic keywords** = distinct Search Console queries with impressions, not a ranking tool's count.
- **AI assistant sessions** before GA4's AI Assistant channel (2026-05-13) are an estimate from referral sources (SEO → Settings → AI referrer pattern).
- **GA4 thresholding**: GA4 may withhold small numbers, often key events.

Missing data shows as "—" or "not entered", never 0.

## Reading it from Claude

The same numbers are available to Claude through six `seo_` tools: list sites, month numbers, daily series, page and query rows, record AI impressions, and update brand terms, key events or the year-over-year flag. `seo_month_numbers` returns exactly what the site page shows, so the two can't disagree. See [Claude access (MCP)](/docs/features/claude-access) for setup and the full list. For now this works from Claude Code on the Control host only.

## Adding to it

New SEO features go in `modules/Seo/`: controllers in `src/Http/Controllers/`, Google clients in `src/Google/`, services in `src/Services/`, views under the `seo::` namespace, migrations in `database/migrations/`, and routes in `routes/web.php`, which load only while the module is enabled. Pages and reports read the `seo_*` tables, never the Google APIs.
