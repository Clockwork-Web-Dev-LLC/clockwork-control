---
title: SSL renewal failed
section: Runbooks
order: 30
updated: 2026-05-04
author: Aaron Reimann
tags: [runbook, ssl, lets-encrypt, cloudflare, incident]
---

A site's `cert_state` is `renewal_overdue` or `expired` and the alert just hit. Most failures are one of three things — work the list.

## 1. Confirm what the cert serving says

```bash
echo | openssl s_client -connect example.com:443 -servername example.com 2>/dev/null | openssl x509 -noout -dates -issuer
```

- **Issuer** — Let's Encrypt? GoDaddy? Cloudflare? Tells you which renewal flow is responsible.
- **Dates** — confirm the expiry. Sometimes the alert is firing on stale data and the renewal already happened.

## 2. Is it really LE?

`/sites/{id}/overview` cert card shows `cert_source`. If it says `letsencrypt` but the cert serving is from a different CA (or vice versa), update the source — the renewal flow is on whoever actually issues the cert.

## 3. The Cloudflare ACME-challenge trap

This is the #1 cause of LE renewal failure on our fleet. A CF rule (Redirect / Transform / WAF / Page Rule) fires on non-US visitors and rewrites or blocks the request. LE's secondary validators originate in EU IPs, so when LE retries from there, the validator's request to `/.well-known/acme-challenge/<token>` gets rewritten to something else and 404s.

Check:

```bash
php artisan clockwork:cf-rules <domain>
```

Look for:

- Geo-based redirects (`(ip.geoip.country ne "US")` or similar).
- URL rewrites that mutate `http.request.uri.path`.
- WAF rules that 403 anything based on path or country.

**Fix:** add an exclusion for `/.well-known/acme-challenge/` to the offending rule:

```
(your-condition) and not starts_with(http.request.uri.path, "/.well-known/acme-challenge/")
```

If the rule has a redirect target prefix that gets re-rewritten on the second pass, exclude that too:

```
(your-condition)
  and not starts_with(http.request.uri.path, "/.well-known/acme-challenge/")
  and not starts_with(http.request.uri.path, "/your-redirect-prefix/")
```

After fixing, force a renewal from SpinupWP (or wait — LE will auto-retry).

## 4. SpinupWP renewal cron didn't run

LE renews via SpinupWP's internal cron. If the cron is broken, no renewal happens. Check SpinupWP's site detail page → Activity log for the renewal attempt entry.

If it didn't try, escalate to SpinupWP support or trigger a manual renewal from their dashboard.

## 5. DNS issues

LE validates by fetching `http://<domain>/.well-known/acme-challenge/<token>`. Things that break that fetch:

- The `A` record points to the wrong server.
- CF is set to "DNS only" but the `A` record resolves to an old IP.
- IPv6 (`AAAA`) record exists and points somewhere unexpected.

Quick check from the box:

```bash
dig +short A example.com
dig +short AAAA example.com
```

Both should resolve to the current server's public IP (or to CF's edges if the site is proxied).

## 6. The cert is fine but our probe is broken

If the cert is valid (correct dates, correct issuer) but Clockwork still says `renewal_overdue` or `expired`:

- Click **Recheck cert** on the per-site card. Forces a fresh probe.
- Check whether `CLOCKWORK_SSL_RENEWAL_GRACE_HOURS` (default 48) is meaningful for this site — LE renewal can lag the scheduled date by a day or two normally. The grace exists for that.

## 7. Custom-cert sites

`cert_source=custom` means the cert is whatever you uploaded to SpinupWP. We track expiry but don't drive renewal. If it's expiring, you need to provision a new one (CA of your choice) and upload it.

## When you're done

- Verify the renewal succeeded (`cert_state` should flip back to `valid` on the next 04:00 UTC check, or click Recheck).
- If the cause was a CF rule, document the exclusion you added in the per-site action log so the next CF rule edit doesn't reintroduce it.
- If it was SpinupWP cron, watch the next renewal date carefully.
