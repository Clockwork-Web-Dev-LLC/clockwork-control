# EmailAuth Module

Email authentication monitoring (SPF, DMARC, DKIM, and MX posture) for Clockwork Control.

## Overview
- **SPF Verification**: Validates `v=spf1` records, prevents duplicate SPF definitions (RFC 7208), counts recursive DNS lookup mechanisms (hard failure over 10 lookups), tracks void lookups (>2 warn), detects recursive include loops, flags deprecated `ptr` mechanisms, and verifies terminal qualifiers.
- **DMARC Compliance**: Inspects `_dmarc.{apex}` records, enforces single definition, verifies enforcement policies (`p=reject`, `p=quarantine`, or warning on `p=none`), checks reporting addresses (`rua`), and detects partial percentage rollout (`pct`).
- **DKIM Probing**: Probes standard known selectors (`google`, `selector1`, `selector2`, `k1`, `s1`, `s2`, `mx`, `smtp`, `pm`, `mandrill`, `default`, `dkim`) and supports per-domain custom selectors.
- **MX & Parked Domain Grading**: Context-aware recommendations for parked domains with null MX (`0 .`) or no mail exchangers.
- **DNS-over-HTTPS (DoH)**: Resolves DNS queries via Cloudflare with Google fallback, bypassing host caches and distinguishing between `NXDOMAIN`, empty answers (`NO_DATA`), and network errors. Native resolver is available as an alternative.
- **Fleet-Wide Inspector & Global Scan**: Surfaced at `/security/email-auth` (and `/email-auth`) with interactive drawer, plain-English findings, suggested fix records, filterable status pills, single-domain re-scan, and one-click global fleet scan (`POST /email-auth/scan-all`).
- **Domain Soft Deletion**: Untracked domains can be deleted (`DELETE /email-auth/domains/{domain}`) to remove them from views and exclude them from scheduled checks.
- **Chat Alerts**: Fires `email_auth_degraded` notification upon state degradation to `fail`.
