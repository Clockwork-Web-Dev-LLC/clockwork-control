---
title: URLhaus + Spamhaus DBL
section: Integrations
order: 90
updated: 2026-09-05
author: Aaron Reimann
tags: [integrations, security, blacklist, urlhaus, spamhaus]
tracks: [app/Services/Security/BlacklistChecker.php]
---

Two domain-blacklist sources we query daily. Neither needs a paid account — Spamhaus DBL is a free DNS lookup, URLhaus is a free registration. Together with Google Safe Browsing, they form the daily blacklist scan that recovers the signal Sucuri loses when Cloudflare 403s its scanner.

## Why we use it

When a site sits behind Cloudflare's WAF, Sucuri SiteCheck often gets 403'd before it can fetch the homepage — so its "blacklist" verdict is actually "I couldn't reach the origin." We needed an independent path to the same signal that doesn't require fetching anything from the site itself. Spamhaus DBL and URLhaus check by domain — no HTTP fetch, no WAF-blocking surface.

## Spamhaus DBL

### Setup

Nothing. It's a DNS lookup. Always runs.

### How it works

We resolve `<domain>.dbl.spamhaus.org`. Returns 127.0.1.x for hits:

| Code | Meaning |
|---|---|
| 127.0.1.2 | Spam |
| 127.0.1.4 | Phish |
| 127.0.1.5 | Malware |
| 127.0.1.6 | Botnet |

NXDOMAIN means clean.

## URLhaus

### Setup

1. Free account at `auth.abuse.ch`.
2. Generate an Auth Key.
3. Set in `.env`:

   ```
   CLOCKWORK_URLHAUS_AUTH_KEY=...
   ```

If the key is empty, URLhaus is silently skipped — Spamhaus still runs.

### Auth

`Auth-Key` HTTP header.

### Endpoints we call

Base URL `https://urlhaus-api.abuse.ch/v1`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/host/` | Look up a domain for malicious URLs. |

URLhaus catches **malware-host** status — 4M+ entries focused on phishing campaigns and command-and-control hosting.

## Files

- `app/Services/Security/BlacklistChecker.php` — the all-in-one blacklist client (handles GSB, URLhaus, Spamhaus).
- `app/Console/Commands/CheckBlacklists.php`
- Config: `config/clockwork.php` → `security_scans.urlhaus_auth_key` + `security_scans.blacklist_timeout`.

## Scheduled jobs

| Cadence | Command |
|---|---|
| daily 02:15 | `clockwork:check-blacklists` — runs all three blacklist sources per site. Hosting-tier (every site, not care-plan-gated). |

## What lands in the DB

One row per run in `site_security_scans` with `scan_type='blacklist'`. Status:

- `clean` — no source matched.
- `issues_found` — at least one source matched. The `details` JSON names which provider (`spamhaus`, `urlhaus`, `gsb`) and what category.
- `failed` — none of the sources could be queried (rare — usually a transient network issue).

The Companion Security page → Domain blacklists card surfaces the same data to the client.

## Gotchas

- **Spamhaus DBL is rate-limited via DNS.** A massive batch of domains in a short window can trigger upstream rate limiting. Our 150-site daily run is well under any meaningful threshold; if the agency 10x's, consider a small per-domain sleep.
- **URLhaus auth header is `Auth-Key`, not `Authorization`.** Easy to confuse if you copy from another integration's client.
- **All three sources unset = Spamhaus only.** Still useful baseline. No source is "load-bearing" — partial failure of one doesn't fail the whole scan.
