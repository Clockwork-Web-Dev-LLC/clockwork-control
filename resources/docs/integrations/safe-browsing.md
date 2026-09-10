---
title: Google Safe Browsing
section: Integrations
order: 85
updated: 2026-09-07
author: Aaron Reimann
tags: [integrations, security, blacklist, google]
tracks: [app/Services/Security/BlacklistChecker.php]
---

Optional source for the daily blacklist scan. Drives Chrome's red interstitial — if Google has a site flagged as malware, phishing, unwanted software, or potentially harmful application, Chrome users see a full-screen warning before reaching it. Catching that ahead of the client matters.

## Why we use it

Of the three blacklist sources we query (Google Web Risk / Safe Browsing, URLhaus, Spamhaus DBL), Google has the highest **practical** signal — it's what Chrome enforces, so a threat hit means real client-visible damage. URLhaus and Spamhaus catch different things (malware C2 hosting, spam reputation); Google is the one whose listing actually breaks the user experience.

## Setup

Google Cloud's **Web Risk API** is the commercial standard for domain threat lookups (free up to 100,000 requests/month). Legacy **Safe Browsing v4** keys are supported as an automatic fallback.

1. `console.cloud.google.com/apis/credentials` → enable the **Web Risk API** (or Safe Browsing API) → create an API key.
2. Set in `.env`:

   ```
   CLOCKWORK_GOOGLE_WEB_RISK_KEY=...
   # Or legacy fallback:
   # CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY=...
   ```

3. Trigger a one-off scan:

```bash
php artisan clockwork:check-blacklists --site=42
```

If the key is empty, the Google check is silently skipped — Spamhaus and URLhaus still run.

## Auth

API key as a query parameter (`key=...`).

## Endpoints we call

- **Web Risk API (Primary)**: `GET https://webrisk.googleapis.com/v1/uris:search?uri=...&threatTypes=MALWARE&threatTypes=SOCIAL_ENGINEERING&threatTypes=UNWANTED_SOFTWARE&key=...`
- **Safe Browsing v4 (Fallback)**: `POST https://safebrowsing.googleapis.com/v4/threatMatches:find?key=...`

## Files

- `app/Services/Security/BlacklistChecker.php` — the all-in-one blacklist client (Web Risk / GSB + URLhaus + Spamhaus).
- `app/Console/Commands/CheckBlacklists.php`
- Config: `config/clockwork.php` → `security_scans.google_web_risk_key` / `security_scans.google_safe_browsing_key`.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| daily 02:15 | `clockwork:check-blacklists` — combined GSB + URLhaus + Spamhaus check per site. Hosting-tier (every site, not care-plan-gated). |

## Free tier

10,000 requests/day on the free tier — comfortably 60x our fleet size.

## Gotchas

- **Empty key disables silently.** No error in the logs — by design, since GSB is opt-in. Check `/settings/security-scans` if you expect data and don't see any.
- **Result is per-domain, not per-URL.** We don't pass per-page URLs. A clean response means Google has nothing on the bare hostname.
- **GSB classifies aggressively.** A false positive (rare but it happens) will appear as a `blacklist` row with `status=issues_found` until Google de-lists. The Companion Security page shows which provider flagged.
