---
title: Email Authentication (SPF, DMARC, DKIM)
section: Features
order: 42
updated: 2026-10-03
author: Aaron Reimann
tags: [email-auth, security, spf, dmarc, dkim, dns, doh]
tracks: [modules/EmailAuth/**]
---

> **Email deliverability & spoofing defense.** Packaged as `modules/EmailAuth` (`clockwork/email-auth`) and categorized under `security` in `ModuleCatalog`. Inspects SPF 10-lookup limits, loop detection, DMARC enforcement policies, and DKIM selector probing across client domains.

Monitors email authentication records across your fleet's root apex domains to prevent spoofing, maintain Google/Yahoo inbox deliverability compliance, and alert operators when client DNS records degrade or break.

## Why DNS-over-HTTPS (DoH)

Traditional PHP `dns_get_record()` has three fatal problems for automated monitoring:
1. **Resolver caching**: host-level DNS caches (systemd-resolved, dnsmasq, or ISP resolvers) can mask DNS updates for hours or return stale records.
2. **Ambiguous failures**: `dns_get_record()` conflates `NXDOMAIN` (domain doesn't exist), empty response (domain exists but has no TXT record), and network timeout/SERVFAIL into an empty array or `false`. That would trigger false "missing DMARC" alerts during transient resolver hiccups.
3. **Untestable**: native DNS lookups cannot be mocked with `Http::fake` in automated tests.

`EmailAuth` introduces the `DnsTxtResolver` contract. By default, it uses **DNS-over-HTTPS (`DohDnsTxtResolver`)**, querying Cloudflare (`https://cloudflare-dns.com/dns-query`) with an automated fallback to Google Public DNS (`https://dns.google/resolve`). A lookup failure is classified as `unknown`, never a false `fail`. A native `NativeDnsTxtResolver` is available for air-gapped or offline environments.

## What gets checked

Checks run per **registrable apex domain** (via `RootDomainResolver::resolve()`), automatically deduplicated across sites so `www.example.com` and `sub.example.com` share a single check.

### 1. SPF (Sender Policy Framework)
- **Single record rule**: Exactly one `v=spf1` record. Multiple SPF records cause permanent authentication errors per RFC 7208.
- **10-DNS-lookup limit**: Recursively expands `include:`, `redirect=`, `a`, `mx`, and `exists` mechanisms. Exceeding 10 lookups causes SPF `permerror` and inbox rejection. Warns at 8–10 lookups; fails at >10.
- **Loop & recursion detection**: Detects circular `include:` chains without infinite loops.
- **Void lookups**: Tracks lookups returning `NXDOMAIN` or no records; warns if void lookups exceed 2.
- **Terminal qualifier**: Recommends `-all` (hard fail) or `~all` (soft fail). Flags `?all` (neutral) with a warning and `+all` (allow all) or missing qualifiers as critical failures.
- **Deprecated mechanisms**: Flags deprecated `ptr` lookups.

### 2. DMARC (Domain-based Message Authentication)
- Queries `_dmarc.{domain}` for RFC 7489 compliance.
- Ensures a single valid `v=DMARC1` TXT record exists.
- **Policy enforcement**: `p=reject` or `p=quarantine` pass. `p=none` (monitoring only) produces an advisory warning.
- **Reporting destination**: Warns if `rua=` aggregate report mailto destination is missing.
- Inspects `pct=` enforcement percentage and `sp=` subdomain policy.

### 3. DKIM (DomainKeys Identified Mail)
- DNS cannot enumerate DKIM selectors without knowing the key names in advance.
- Probes common industry selectors: `google`, `selector1`, `selector2` (Microsoft 365), `k1`, `s1`, `s2`, `mx`, `smtp`, `pm` (Postmark), `mandrill`, `default`, `dkim`.
- Allows operators to configure **custom selectors** per domain directly from the web drawer.
- Missing probe results are treated as **advisory `unknown`/`warn`**, never a hard failure, because legitimate custom selectors may not match the standard probe list.

### 4. Parked & Non-Sending Domains
- Checks MX records: if no MX or null MX (`0 .`) is found, the domain is evaluated as a parked/non-sending domain.
- The recommended posture shifts to `v=spf1 -all` with `p=reject`, and DKIM records are marked as not applicable.

## Surfacing and UI

- **Fleet Hub (`/email-auth`)**: A centralized table displaying every client apex domain, associated site count, SPF lookup counts, DMARC policy pills (`reject`, `quarantine`, `none`), DKIM status, and overall grade.
- **Slide-Over Drawer**: Click any domain to open the detail drawer featuring:
  - Copyable raw DNS records (`v=spf1...`, `v=DMARC1...`).
  - Plain-English advisory findings with color-coded severity (`fail`, `warn`, `info`).
  - Suggested fix records tailored to the domain.
  - Custom DKIM selector manager.
  - One-click **Ignore Domain** toggle to silence alerts for third-party-managed client DNS.
- **Chat Alerts**: Fires `email_auth_degraded` to Slack and Mattermost only on **state transitions** (e.g., when a previously passing domain degrades to `fail`).

## CLI and Scheduled Cadence

- Runs weekly via `clockwork:check-email-auth` on Mondays at 04:00 UTC.
- On-demand fleet scans or single-domain checks via CLI:

```bash
# Check all active, monitored domains
php artisan clockwork:check-email-auth

# Check a single domain
php artisan clockwork:check-email-auth --domain=example.com
```
