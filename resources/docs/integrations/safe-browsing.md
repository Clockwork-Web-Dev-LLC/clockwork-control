---
title: Google Safe Browsing
section: Integrations
order: 85
updated: 2026-09-05
author: Aaron Reimann
tags: [integrations, security, blacklist, google]
tracks: [app/Services/Security/BlacklistChecker.php]
---

Optional source for the daily blacklist scan. Drives Chrome's red interstitial — if Google has a site flagged as malware, phishing, unwanted software, or potentially harmful application, Chrome users see a full-screen warning before reaching it. Catching that ahead of the client matters.

## Why we use it

Of the three blacklist sources we query (Google Safe Browsing, URLhaus, Spamhaus DBL), GSB has the highest **practical** signal — it's what Chrome enforces, so a Safe Browsing hit means real client-visible damage. URLhaus and Spamhaus catch different things (malware C2 hosting, spam reputation); GSB is the one whose listing actually breaks the user experience.

## Setup

1. `console.cloud.google.com/apis/credentials` → enable the **Safe Browsing API** → create an API key.
2. Set in `.env`:

   ```
   CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY=...
   ```

3. Trigger a one-off scan:

```bash
php artisan clockwork:check-blacklists --site=42
```

If the key is empty, the GSB check is silently skipped — Spamhaus and URLhaus still run.

## Auth

API key as a query parameter (`key=...`).

## Endpoints we call

Base URL `https://safebrowsing.googleapis.com/v4`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/threatMatches:find?key=KEY` | Check the domain against `MALWARE`, `SOCIAL_ENGINEERING`, `UNWANTED_SOFTWARE`, `POTENTIALLY_HARMFUL_APPLICATION`. |

## Files

- `app/Services/Security/BlacklistChecker.php` — the all-in-one blacklist client (GSB + URLhaus + Spamhaus).
- `app/Console/Commands/CheckBlacklists.php`
- Config: `config/clockwork.php` → `security_scans.google_safe_browsing_key`.

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
