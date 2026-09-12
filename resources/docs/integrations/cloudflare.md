---
title: Cloudflare
section: Integrations
order: 30
updated: 2026-09-11
author: Aaron Reimann
tags: [integrations, cloudflare, dns, waf, security]
tracks: [app/Services/Cloudflare/**, app/Console/Commands/CheckCloudflare.php, app/Console/Commands/CloudflareRules.php, app/Console/Commands/RefreshCloudflareRealIp.php, app/Console/Commands/SweepCfBans.php, app/Console/Commands/AddCloudflareRateLimit.php]
---

We talk to Cloudflare for a few reasons: detect which sites are CF-proxied, dump WAF / Redirect / Transform rules for diagnostic work, and manage rate-limiting/custom-firewall rules. Read-only work and write work use **two different tokens** with different scopes, kept independent so a leak of the read token can't change anything.

## Why we use it

- **Detection** — knowing whether a site is CF-proxied, dns_only, or not_using changes how we read its nginx logs and which IP ends up in `blocked_ips`. CF-proxied sites need three layers of defense (see [Architecture → Ingest pipeline](/docs/architecture/ingest-pipeline)) or fail2ban will end up banning Cloudflare itself.
- **Rule diagnosis** — when an LE renewal fails or a redirect rewrites unexpectedly, `clockwork:cf-rules <domain>` dumps every active rule across every phase so you can find the culprit in one place.
- **Rate limiting / custom WAF rules** — `clockwork:cf-rate-limit` and `putCustomFirewallRules()` write mitigation rules directly, for scraper/DDoS-style spikes a diagnostic dump alone can't fix.

## Setup — read token

1. `dash.cloudflare.com/profile/api-tokens` → Create Token → Custom Token.
2. Permissions (per phase you want the diagnostic CLI to dump):

   | Phase | Token permission needed |
   |---|---|
   | Zone lookup | `Zone: Read` |
   | Custom WAF rules | `Zone WAF: Read` |
   | **Redirect Rules** | **`Dynamic Redirect: Read`** ← recently split out from Transform Rules; search "redirect" in the dropdown |
   | Transform / URL Rewrite | `Transform Rules: Read` |
   | Configuration Rules | `Config Rules: Read` |
   | Page Rules | `Page Rules: Read` |

3. Zone resources: All zones (or specific zones if you want to scope it tighter).
4. Set `CLOCKWORK_CLOUDFLARE_API_TOKEN` in `.env`.

## Setup — write token

Generate a **separate** token, set as `CLOCKWORK_CLOUDFLARE_WRITE_TOKEN`. Keep it independent from the read token so you can rotate either one without touching the other.

- **Rate limiting + custom firewall rules** (`Zone.Firewall Services: Edit`) — used by `clockwork:cf-rate-limit` and `CloudflareClient::putCustomFirewallRules()`. **Not** `Zone WAF: Edit`, despite the name similarity — that's a read-only-adjacent legacy scope that doesn't cover the ruleset-phase write endpoint these use. `AddCloudflareRateLimit` fails loudly with the exact scope name if the token is missing this.

## Auth

Bearer token in `Authorization`. `App\Services\Cloudflare\CloudflareClient` selects read vs write based on the call site.

## Endpoints we call

Base URL `https://api.cloudflare.com/client/v4`.

| Method | Path | Token | Purpose |
|---|---|---|---|
| GET | `/zones?name=...` | Read | Zone lookup by hostname. |
| GET | `/zones/{id}/rulesets/phases/{phase}/entrypoint` | Read | List rules in a phase. We dump all phases for `clockwork:cf-rules`. |
| GET | `/zones/{id}/pagerules` | Read | Legacy Page Rules. |
| GET | `https://www.cloudflare.com/ips-{v4,v6}` | None | Published edge ranges. Cached daily; baked-in fallback list per family. |
| PUT | `/zones/{id}/rulesets/phases/http_request_firewall_custom/entrypoint` | Write | `CloudflareClient::putCustomFirewallRules()` — replaces the full custom-WAF-rule ruleset for a zone. Added for the clientsite.example-style scraper-mitigation use case; no command currently calls it (client method exists ahead of a consuming feature). |
| PUT | `/zones/{id}/rulesets/phases/http_request_dynamic_redirect/entrypoint` | Write | `CloudflareClient::putRedirectRules()` — replaces the full Redirect Rules ruleset for a zone, mirroring `putCustomFirewallRules()`'s pattern. Added for bulk domain-rebrand redirect use cases; same as above, no command currently calls it. |
| POST | rate-limiting rule endpoint | Write | `clockwork:cf-rate-limit` — add/list/remove a rate-limiting rule for a zone. |

## Files

- `app/Services/Cloudflare/CloudflareClient.php` — HTTP client. `phaseRules()` returns `['rules' => [...], 'error' => ?string]` so a 403 on one phase doesn't abort the whole dump. `putCustomFirewallRules()` and `putRedirectRules()` are the write-side counterparts for custom WAF rules and Redirect Rules, respectively — both currently client methods with no consuming command yet.
- `app/Services/Cloudflare/CloudflareDetector.php` — fetches edge ranges, classifies per-site state. Public: `ranges()`, `rangesV6()`, `isCloudflareIp()`.
- `app/Console/Commands/CheckCloudflare.php` — weekly per-site state detection.
- `app/Console/Commands/CloudflareRules.php` — diagnostic dump (`clockwork:cf-rules`).
- `app/Console/Commands/AddCloudflareRateLimit.php` — add/list/remove a rate-limiting rule (`clockwork:cf-rate-limit`). Needs the write token's `Zone.Firewall Services: Edit` scope.
- `app/Console/Commands/RefreshCloudflareRealIp.php` — pushes the nginx CF-real-IP snippet to every server and bridges it into `sites-enabled/` (see below).
- `app/Console/Commands/SweepCfBans.php` — one-shot mass-unban of any historical misattributed CF-edge IPs.
- Config: `config/clockwork.php` → `cloudflare` key.

## The real-IP snippet (and why conf.d alone doesn't work)

`clockwork:refresh-cloudflare-real-ip` writes `/etc/nginx/conf.d/clockwork-cloudflare-real-ip.conf` — `set_real_ip_from` for every published CF edge range plus `real_ip_header CF-Connecting-IP` — so PHP, fail2ban, LLAR, Wordfence, and the traffic rollup's visit count all see the actual visitor instead of the CF edge. Non-CF requests are unaffected (they don't carry the header), so it's safe fleet-wide.

**The conf.d file alone is inert on SpinupWP boxes.** Their `nginx.conf` includes `sites-enabled/*` (at http context) but never `conf.d/*`. Without a bridge, origins keep logging CF edge IPs rather than real visitors. The command creates a symlink to `sites-enabled/000-clockwork-cloudflare-real-ip.conf` — the `000-` prefix sorts it first in the alphabetical glob, establishing real-IP at http scope before any `server{}` block. conf.d stays the single source of truth; the symlink ensures nginx evaluates it.

Safety and idempotency details:

- **`nginx -t` gates every reload** and reverts both the file and a newly-created bridge on failure — a bad snippet never reaches a running nginx.
- **Unchanged content + present bridge = no reload.** Most weekly runs are cheap no-ops.
- **Pre-existing real-IP setups are respected.** Some servers already load real-IP another way (an older include-style bridge, or a SpinupWP per-site `before/` include). Adding our symlink there would load the directives twice — nginx fails with "real_ip_header duplicate". The command checks `nginx -T` for an active `real_ip_header` first and skips the bridge if real-IP is already live, only refreshing conf.d content if the CF ranges changed.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| daily 05:00 | `clockwork:check-cloudflare` — per-site state detection. (Promoted from weekly — CF state changes too often.) |
| Sun 05:30 | `clockwork:refresh-fail2ban-ignoreip` — pushes CF v4+v6 + fleet IPs into every jail's `ignoreip`. |
| Sun 05:45 | `clockwork:refresh-cloudflare-real-ip` — pushes the `set_real_ip_from` nginx snippet + sites-enabled bridge. |

## Real-world story

`store.cfclient.example` was failing LE renewal because a Transform Rule rewrote non-US visitor URLs (`(ip.geoip.country ne "US") → concat("/international-redirect", http.request.uri.path)`). LE's secondary validators come from EU IPs, so the validator's request was rewritten to `/international-redirect/.well-known/acme-challenge/...` and 404'd. The rule fired *twice* (once on the initial request, once after LE followed the redirect), producing the doubled-prefix path in the access log.

**Always exclude `/.well-known/acme-challenge/` from any CF rule that mutates path or status:**

```
(your-condition) and not starts_with(http.request.uri.path, "/.well-known/acme-challenge/")
```

`clockwork:cf-rules <domain>` exists to find these without clicking through every dashboard panel.

## Gotchas

- **Permission scopes don't match the dashboard groupings**, and CF has split groups over time (see Setup table). The diagnostic command shows "forbidden — token needs '<perm>: Read'" per phase if a scope is missing.
- **CF rules can break LE renewal.** Geo-redirects, JS challenges, URL rewrites that fire for non-US visitors will trip LE's secondary validators (which originate in EU). See the example above.
- **Smart quotes vs straight quotes** in CF rule expressions — copy-paste from Slack / notes can introduce smart quotes that break the expression silently.
- **`Http::retry()` rethrows on final failure** by default. Pass `throw: false` if you handle status codes manually (the rules dump does).
