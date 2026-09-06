---
title: Arcjet well-known-bots
section: Integrations
order: 100
updated: 2026-09-05
author: Aaron Reimann
tags: [integrations, bots, arcjet, allowlist]
tracks: [app/Console/Commands/SyncAllowedBots.php]
---

Daily refresh of a curated bot allowlist from `arcjet/well-known-bots` on GitHub. Used at ingest time to skip nginx log entries from real search engines and other legitimate crawlers — keeping them out of the review queue.

## Why we use it

Without an allowlist, every Googlebot or Bingbot crawl that gets a 4xx (a missing image, a stale URL) looks like a suspicious request to our nginx ingest. Pre-filtering with Arcjet's curated list keeps the review queue focused on actual abuse instead of search engine noise.

We use Arcjet's list because it's actively maintained, public, and CC0-licensed. Building our own would be a side project we'd never finish.

## Setup

Nothing. The default URL is hardcoded in `config/clockwork.php`. Override only if you mirror the file:

```
CLOCKWORK_ARCJET_BOTS_URL=https://your-mirror.example.com/well-known-bots.json
```

Trigger a manual sync:

```bash
php artisan clockwork:sync-allowed-bots
```

## Auth

None. It's a plain GET against raw GitHub content.

## Endpoints we call

| Method | URL | Purpose |
|---|---|---|
| GET | `https://raw.githubusercontent.com/arcjet/well-known-bots/main/well-known-bots.json` | Fetch the latest bot list. |

## Files

- `app/Console/Commands/SyncAllowedBots.php`
- `app/Models/AllowedBot.php` — `matches($userAgent)` wraps regex patterns with `~...~` to avoid forward-slash collisions.
- Config: `config/clockwork.php` → `arcjet` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| daily 03:00 | `clockwork:sync-allowed-bots` — refresh `allowed_bots` from the upstream JSON. |

## What lands in the DB

`allowed_bots` rows: `name`, `ua_pattern`, `pattern_type` (`substring|regex`), `source` (`arcjet|manual`), `reference_url`, `synced_at`. Unique on (`source`, `ua_pattern`) so re-syncing is idempotent.

The model's `matches($ua)` runs at ingest time. `pattern_type=regex` runs `preg_match` with `~...~` delimiters (PCRE, not POSIX) — every Arcjet-synced row is always this type, since `SyncAllowedBots` hardcodes `AllowedBot::PATTERN_REGEX` for everything it upserts. `pattern_type=substring` (plain, case-sensitive `str_contains`) only ever shows up on manually-added rows — the sync command never produces one.

## Gotchas

- **UA-string match only in v1.** Known to be spoofable — anyone can claim to be Googlebot. Reverse-DNS verification is the canonical hardening (resolve the IP back to a `*.googlebot.com` hostname, then verify the forward lookup). Planned, out of scope for v1.
- **Manual rows survive re-sync.** `AllowedBot::source='manual'` rows are not touched by the Arcjet sync. Use them for one-off allowances.
- **Don't allowlist generic strings.** A pattern like `bot` matches half the internet. The Arcjet list is specific (`Googlebot/2.1`, `bingbot/2.0`); add to it rather than substituting.
