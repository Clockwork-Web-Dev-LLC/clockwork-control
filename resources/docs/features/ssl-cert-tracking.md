---
title: SSL cert tracking
section: Features
order: 60
updated: 2026-09-05
author: Aaron Reimann
tags: [ssl, certificates, lets-encrypt, monitoring, pressable]
tracks: [app/Services/Ssl/**, app/Console/Commands/CheckSslCerts.php]
---

Daily SSL state check per site. Tracks expiry, renewal date, and source (Let's Encrypt, custom, none). Mattermost-alerts on state transitions. Surfaces on the Issues page when something's about to break.

A site marked inactive (`sites.is_inactive`) is excluded from both the Issues-page SSL section and the `ssl_state_changed` alert — the check itself still runs and `cert_state` still updates, only the surfacing is suppressed. See [Features → Inactive sites](/docs/features/inactive-sites).

## State machine

`sites.cert_state` ∈:

- **`valid`** — cert is valid, expires comfortably in the future.
- **`expiring_soon`** — within 30 days of expiry.
- **`expired`** — past expiry, real visitors get a browser error.
- **`renewal_overdue`** — Let's Encrypt-only. Past the scheduled `cert_renews_at` date plus the grace period (default 48 hours; tunable via `CLOCKWORK_SSL_RENEWAL_GRACE_HOURS`). Means SpinupWP's auto-renewal didn't fire.
- **`unknown`** — couldn't fetch the cert (DNS issue, port closed, etc.).

State transitions are persisted (`cert_state_changed_at`) and fire Mattermost alerts on the way to a worse state.

## Where to look

- **`/issues`** — surfaces sites in `expiring_soon`, `expired`, `renewal_overdue`, or `unknown`.
- **`/sites/{id}/overview` cert card** — current state, expiry, renewal date, source, edit button.
- **Mattermost** — alerts on state transitions.

## Source

`sites.cert_source` ∈ `none | spinupwp_le | external | redirect_only | live_probe`. Source is set automatically on import (LE detected via SpinupWP) and editable per-site if you have a non-LE cert (e.g. a wildcard from a separate CA) — `external` and `redirect_only` are both manual-override values that skip further automated refresh.

For SpinupWP LE sites, we know SpinupWP runs the renewal. For `external`, we just track expiry — the renewal is on the operator. `redirect_only` means the origin cert is intentionally ignored (site redirects before TLS matters) and is skipped by SSL state monitoring entirely.

### `live_probe` — sites with no per-site cert API (Pressable)

Any site with no `spinupwp_id` — Pressable today, any future non-SpinupWP provider automatically — has no host API to ask for cert data the way `SiteCertRefresher` asks SpinupWP. `App\Services\Ssl\LiveCertProbe` fills that gap with a direct TLS handshake (`stream_socket_client` + `openssl_x509_parse`) straight against the domain on port 443 — no SSH, no API dependency, works for any HTTPS site regardless of host.

Because it's the *only* source of cert data these sites have, `SslChecker` runs the probe **every cycle**, not gated on a non-green state transition the way the SpinupWP refresh path is — waiting for a state change would freeze `cert_expires_at` at whatever the very first probe happened to return. Sites with a manual `external`/`redirect_only` override are skipped so an operator's explicit choice isn't clobbered by the probe. A failed handshake (unreachable, DNS failure, TLS negotiation failure) just means "couldn't determine expiry this run" — it never throws or interrupts the fleet-wide check loop.

## Cadence

Daily at 04:00 UTC (`clockwork:check-ssl-certs`). Plus on-demand when you click **Recheck cert** on the per-site card — that button calls SpinupWP's own per-site API, so it's hidden for Pressable sites (whose cert data only ever comes from the scheduled `live_probe` cycle above, not an on-demand recheck yet).

## Manual run

```bash
php artisan clockwork:check-ssl-certs --site=42
```

## Why the renewal grace period exists

SpinupWP's LE renewal cron runs nightly. It doesn't fire at midnight on the renews date — typical SpinupWP behavior is to renew within 24-48 hours of the scheduled date. Without a grace, every LE site would briefly flap into `renewal_overdue` between midnight and the actual renewal. The 48-hour default catches genuine renewal failures while ignoring SpinupWP's normal cadence.

Tighten via `CLOCKWORK_SSL_RENEWAL_GRACE_HOURS` if you want sharper alerts (and accept some false positives).

## When a renewal fails for real

Most common cause: a Cloudflare rule (geo-redirect, JS challenge, URL rewrite) rewrites or blocks `/.well-known/acme-challenge/...` for non-US visitors. LE's secondary validators originate in EU IPs; if your CF rule fires on `country ne "US"`, the validator fails.

`clockwork:cf-rules <domain>` dumps every active rule across every phase so you can spot the culprit. **Always exclude `/.well-known/acme-challenge/` from any CF rule that mutates path or status:**

```
(your-condition) and not starts_with(http.request.uri.path, "/.well-known/acme-challenge/")
```

See [Integrations → Cloudflare](/docs/integrations/cloudflare).

## Broken SSL chain

A missing intermediate CA fails our probe (cURL 60). That's correct — real visitors hit the same error. If you see a confusing "down" alert paired with `cert_state=valid`, check whether the chain has gone broken since the cert was issued. Some hosting providers strip intermediates from their default config; explicit `ssl_certificate` paths usually fix it.

## What this isn't

- **Not a renewal trigger.** We track. SpinupWP renews its own certs; Pressable renews its own too — the live probe only reads what's already served, it never requests or triggers a renewal.
- **Not a CA monitor.** We check the cert serving on the live site, not what's published in CT logs.
- **Not multi-region.** We probe from the operator's own machine. A regional cert serving issue won't show up here.
