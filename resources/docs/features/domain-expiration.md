---
title: Domain expiration tracking
section: Features
order: 65
updated: 2026-09-07
author: Aaron Reimann
tags: [domains, rdap, expiration, registrars, monitoring]
tracks: [app/Services/Domains/**, app/Console/Commands/CheckDomainExpirations.php]
---

Automated fleet-wide domain expiration and registrar tracking powered by ICANN RDAP (Registration Data Access Protocol). Monitors domain expiration dates and registry statuses via standardized RFC 7483 endpoints, surfaces upcoming renewals on the Issues page, and sends chat notifications on state transitions.

Sites marked inactive (`sites.is_inactive`) are excluded from Issues-page listings and notifications.

## State machine

`sites.domain_expiration_state` ∈:

- **`none`** — no RDAP data collected yet, or all previous lookups failed.
- **`green`** — healthy; more than 30 days until domain expiration.
- **`yellow`** — expiration within 30 days (`<= 30 days`). Indicates domain renewal is due or requires verification with the registrar.
- **`red`** — critical; expiration within 7 days (`<= 7 days`), already expired, OR the domain is in `redemptionPeriod` or `pendingDelete` status.

State transitions are persisted to `sites.domain_expiration_state_changed_at` and trigger chat alerts when worsening to `yellow` or `red`.

## Where to look

- **`/issues`** — `#section-domain-expiration` card table displays all sites in `yellow` and `red` states, along with expiration date, days remaining, registrar, and an inline Recheck button.
- **Chat channels (Mattermost / Slack)** — alerts sent when a domain transitions into `yellow` or `red`, or recovers back to `green`.

## Root domain extraction & RDAP lookup (`RdapClient`)

Clockwork extracts root registered domains using the Public Suffix List via `jeremykendall/php-domain-parser`, accurately resolving multi-part TLDs (e.g. `sub.example.co.uk` → `example.co.uk`).

Outbound RDAP requests are handled by `RdapClient` (`app/Services/Domains/RdapClient.php`). Lookups query the canonical RDAP bootstrap endpoint (`https://rdap.org/domain/{domain}`), follow HTTP 302 redirects to authoritative registry endpoints (e.g. Verisign, PIR), and parse:
- Expiration date from `events[action=expiration]`
- Registrar name from `entities[role=registrar].vcardArray`
- Lifecycle status from `status[]` (`redemptionPeriod`, `pendingDelete`)

## Cadence & rate limits

- **Schedule**: `clockwork:check-domain-expirations` runs daily at **06:05 UTC**.
- **Per-site cadence guard**:
  - `none`: checked immediately.
  - `yellow` / `red`: checked daily (at least 20 hours since last check).
  - `green`: checked weekly (at least 6 days since last check).
- **Rate limiting**:
  - Paced lookup loop honoring rdap.org's Cloudflare rate limits (default: 10 requests per 10 seconds).
  - TLD backoff: on HTTP 429 or 503, the affected TLD's registry enters a 2-hour cooldown to protect registry quotas.

## Manual run & on-demand recheck

Run across the fleet:
```bash
php artisan clockwork:check-domain-expirations
```

Run initial silent backfill without chat notifications:
```bash
php artisan clockwork:check-domain-expirations --silent
```

On-demand single site:
```bash
php artisan clockwork:check-domain-expirations --site=42 --force
```

Or click **Recheck** on the Issues page row (`POST /sites/{site}/domain/recheck`), which bypasses cadence guards and returns the updated state immediately.

## Configuration

Configurable via `config/clockwork.php` or environment variables:
- `CLOCKWORK_RDAP_TIMEOUT` (default: 10 seconds)
- `CLOCKWORK_RDAP_RATE_LIMIT` (default: 10 requests per 10 seconds)
- `CLOCKWORK_RDAP_TLD_BACKOFF_HOURS` (default: 2 hours)

## What this isn't

- **Not a DNS monitor.** RDAP tracks registry records, not nameserver delegations or DNS records.
- **Not an auto-renew broker.** Clockwork alerts when renewals are needed; payment and renewal execution remain with the client's registrar.
