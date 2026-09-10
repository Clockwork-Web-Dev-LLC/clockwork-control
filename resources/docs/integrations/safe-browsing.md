---
title: Google Safe Browsing
section: Integrations
order: 85
updated: 2026-09-10
author: Aaron Reimann
tags: [integrations, security, blacklist, google]
tracks: [app/Services/Security/BlacklistChecker.php, app/Services/Diagnostics/Checks/GoogleSafeBrowsingCheck.php]
---

Optional source for the daily blacklist scan. Drives Chrome's red interstitial — if Google has a site flagged as malware, phishing, or unwanted software, Chrome users see a full-screen warning before reaching it. Catching that ahead of the client matters. Web Risk covers `MALWARE`, `SOCIAL_ENGINEERING`, and `UNWANTED_SOFTWARE` (not v4's `POTENTIALLY_HARMFUL_APPLICATION`).

## Why we use it

Of the three blacklist sources we query (Google Web Risk / Safe Browsing, URLhaus, Spamhaus DBL), Google has the highest **practical** signal — it's what Chrome enforces, so a threat hit means real client-visible damage. URLhaus and Spamhaus catch different things (malware C2 hosting, spam reputation); Google is the one whose listing actually breaks the user experience.

## Setup

Google Cloud's **Web Risk API** is the commercial standard for domain threat lookups (free up to 100,000 requests/month). Legacy **Safe Browsing v4** keys are used only when the Web Risk key is empty — they are different APIs and the v4 secret must not be reused as `CLOCKWORK_GOOGLE_WEB_RISK_KEY`.

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
| daily 02:15 | `clockwork:check-blacklists` — Google Web Risk (or legacy Safe Browsing v4) + URLhaus + Spamhaus. Hosting-tier (every site, not care-plan-gated). |

## Free tier

- **Web Risk:** 100,000 requests/month on the free tier.
- **Safe Browsing v4 (legacy):** 10,000 requests/day — still far above fleet size, but Google's ToS restrict v4 to non-commercial use.

## Gotchas

- **Do not reuse a v4 key as `CLOCKWORK_GOOGLE_WEB_RISK_KEY`.** Config no longer copies the Safe Browsing secret into the Web Risk slot. `BlacklistChecker::effectiveGoogleSource()` picks Web Risk only when that key is non-empty (after trim); otherwise it uses v4. Empty/whitespace keys skip Google entirely — Spamhaus and URLhaus still run.
- **Diagnostics follow the same hierarchy.** `/settings/diagnostics` and Settings → Integrations probe Web Risk when that key is set; otherwise they POST `threatMatches:find`. A Web Risk–only install is no longer reported as “unconfigured.”
- **Empty key disables Google silently.** No error in the logs — by design, since Google is opt-in. Check `/settings/security-scans` if you expect data and don't see any.
- **Result is per-domain, not per-URL.** We don't pass per-page URLs. A clean response means Google has nothing on the bare hostname.
- **Google classifies aggressively.** A false positive (rare but it happens) will appear as a `blacklist` row with `status=issues_found` until Google de-lists. The Companion Security page shows which provider flagged.
