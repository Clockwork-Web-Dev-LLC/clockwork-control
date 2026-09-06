# Assessment of Gemini's research + independent findings (2026-09-05)

Gemini spent its research pass in `plans/feature-roadmap-board.md`, `plans/rdap-domain-expiration.md`,
and `plans/accidental-noindex-watchdog.md`. This document verifies every API/technical claim in
those three files against live sources and this codebase's actual state (not just plausibility), then
adds independent findings from a separate research pass covering ground Gemini didn't touch. Every
claim below was checked by an agent with live web access and/or by reading the actual source file —
nothing here is taken on faith from either AI's training data.

**Bottom line: Gemini's research instincts were good — the feature ideas are genuinely useful and the
APIs are almost all real — but about half the plans have a factual error (wrong URL, wrong response
field, a rate limit or reliability issue left undisclosed) that would only surface once someone tried
to actually build against them. The single best finding across both passes wasn't in Gemini's list at
all: this app already pays for real-user performance and accessibility data on every PageSpeed
Insights call it makes, and throws most of it away.**

---

## Part 1 — Gemini's two fully-spec'd plans

### `plans/rdap-domain-expiration.md` — build it, with corrections

The core idea (track domain expiration via the free RDAP protocol, alert before a lapsed domain takes
a client site dark) is sound and the API is real — `rdap.org/domain/{name}` genuinely 302s to the
authoritative registry and returns exactly the JSON shape the plan shows. Corrections before building:

1. **Use a real Public Suffix List library, not hand-rolled regex.** `jeremykendall/php-domain-parser`
   (Packagist) exists specifically to avoid the `sub.domain.co.uk` root-extraction bugs a regex will
   hit. The plan's "lightweight regex parser" is exactly the failure mode this library exists to prevent.
2. **rdap.org itself rate-limits at 10 requests/10 seconds** (Cloudflare-enforced) — not mentioned in
   the plan. A full-fleet nightly sweep needs to respect this explicitly, on top of the per-registry
   backoff the plan already describes.
3. **Match the codebase's existing 3-state convention, not a new 4-state one.** `Site.php` already has
   the exact analogous pattern for SSL: `cert_source`/`cert_expires_at`/`cert_state`
   (`none|green|yellow|red`, 3 states). The plan invents green/yellow/orange/red (4 states) — either
   collapse to match `sslState()`'s convention or have a clear reason not to.
4. **Reuse `IssuesController`/`IssueCounter`/`issues.blade.php`'s existing SSL section verbatim** as the
   UI template, and respect the documented "KEEP IN SYNC" contract between `IssueCounter::total()` and
   `IssuesController::index()` — the plan mentions wiring into the counter but not this specific
   sync requirement, which is a real footgun in this codebase.
5. Add a `resources/docs/features/domain-expiration.md` matching `ssl-cert-tracking.md`'s format —
   the plan's own doc phase doesn't mention this despite an almost-identical existing doc to copy.

**Verdict: high value, low effort, build after the corrections above.**

### `plans/accidental-noindex-watchdog.md` — good idea, wrong mechanism — rework before building

The problem this solves (a launched site left accidentally `noindex`d) is real and expensive for
agencies. But the plan's proposed *mechanism* needs real changes:

1. **Don't add a new HTTP probe cycle — piggyback on the existing one.** `UptimeProber`/`CheckSiteUptime`
   already fetches every site's homepage HTML every 5 minutes (288×/day per site) and then **discards
   the body and headers**, keeping only status/timing. The plan's proposed separate 6-hour probe would
   add ~600 redundant full-page fetches/day against client sites for data that's already being fetched
   far more often, for free. Extend `UptimeProbeResult` to optionally retain the body/`X-Robots-Tag`
   header instead.
2. **The plan's claim that Companion already reports `blog_public` is false** — verified zero hits for
   `blog_public` anywhere in this repo or its docs. That vector is genuinely new work in the separate
   Companion plugin repo (a new capability + version bump), not "surfacing existing data" as the plan
   implies.
3. **Swap regex parsing for a real DOM parser + a robots.txt library.** Regex on `<meta name="robots">`
   breaks on attribute-order variance and HTML comments; hand-rolled `Disallow: /` matching doesn't
   correctly handle a `User-agent: *` block that also has an `Allow:` exception.
4. The `IssueCounter`/`issues.blade.php` integration plan matches this codebase's actual conventions
   well — no changes needed there.

**Verdict: high value, rework the fetch mechanism (cheap win once done) and treat the Companion vector
as a real, separate mini-project rather than a freebie.**

---

## Part 2 — `plans/feature-roadmap-board.md`'s 13-item backlog, verified item by item

| # | Idea | Verdict |
|---|---|---|
| 1 | WP.org Closed Plugin Audit | **Build, with a correction.** API is real, but closed plugins return `{"error":"closed", "description": "..."}`, not a `closed: true` field — the reason has to be parsed from free text. Genuinely non-redundant with the existing `WpVulnerabilityClient` (CVE-only) — this catches plugins pulled for TOS/abandonment/undisclosed issues *before* any CVE is ever filed. |
| 2 | CISA KEV Catalog | **Build — easiest win on the list.** Real, free, ~1,700 entries, updates near-daily. `plugin_vulnerabilities.cve` is already an indexed column, so this is a single `WHERE cve IN (...)` lookup on data already collected — not a new pipeline. |
| 3 | crt.sh Certificate Transparency | **Build, but as best-effort only.** Real API, but crt.sh is a volunteer-run service with a hard 5 req/min limit and a documented history of multi-week outages. Needs explicit backoff/soft-fail handling the plan doesn't mention — never let it block or hard-alert. |
| 4 | DNSBL/RBL Server Blacklist Monitor | **Build, but as a new method on the existing `BlacklistChecker`, not a new subsystem.** Confirmed genuinely distinct from the domain-blacklist checks already there (Spamhaus `zen.*` = IP zones for mail reputation vs. the existing `dbl.*` = domain zone) — real, non-redundant, just belongs in the existing class/pattern. |
| 5 | SPF/DKIM/DMARC/BIMI Auditor | **Build — flagged independently by two separate research passes as a top pick.** Zero-cost (plain DNS TXT lookups, no API/key at all), real RFC mechanics confirmed. **Cut BIMI** — ~0.4% real-world adoption, not worth the added parsing complexity. |
| 6 | Multi-Node DoH Global Propagation | **Build, but rename/reframe.** Both DoH APIs are real and free, but querying from one server via two resolvers proves *cross-resolver-operator agreement*, not *geographic propagation* — the "US/Europe/Asia" framing is a real overclaim that needs to go. |
| 7 | Visual Fleet Grid (mShots) | **Build.** Real, works — but add a caveat: brand-new URLs can return a placeholder on first request pending async generation. |
| 8 | Visual regression smoke test | **Build only if scoped correctly.** No existing infra does this (the Dusk screenshot tool is the unrelated marketing-site tool). The plan understates the mechanism — classic WP white-screen-of-death often serves HTTP 200, so this needs real image-diffing, not status-code checking. |
| 9 | Green Web Foundation | **Build — easy, low-risk win.** Real, free, keyless, confirmed working. |
| 10 | PHP/WP End-of-Life (`endoflife.date`) | **Build, with a URL correction.** The plan's URL (`/api/v1/products/php.json`) 404s — current v1 API is `/api/v1/products/php` (no suffix), different response schema than the deprecated v0 shape. |
| 11-12 | RunCloud / Ploi.io modules | **Medium priority.** APIs verified current and accurate. Market relevance is defensible (rounds out the same competitive tier as the existing SpinupWP/GridPane coverage) but not urgently justified over alternatives — build if there's actual client demand, not speculatively. |
| 13 | Bunny.net CDN module | **Medium priority, low risk.** API verified real and accurate — build if/when there's an actual CDN need. |

---

## Part 3 — Independent findings (not in Gemini's research at all)

### The best find: this app already has the data for real-user + accessibility scores, and discards most of it

`PageSpeedInsightsClient::scanSite()` requests PSI with `category=performance` only, and its `parse()`
method only reads `lighthouseResult.*` fields. But every PSI v5 response has, on the exact same API
call already being made:

- **`loadingExperience`/`originLoadingExperience`** — real Chrome User Experience Report (CrUX) field
  data (actual users' LCP/INP/CLS), not synthetic lab data like the rest of what's parsed today. This
  is the same real-user signal a standalone CrUX API integration would add — already arriving on every
  existing PSI call, parsed by nobody.
- **`categories.accessibility/best-practices/seo`** — Lighthouse scores for three more categories,
  available by just adding those values to the `category` query param (same free 25k/day quota, zero
  additional cost). This is the standout part: `SitePerformanceScan` **already has an
  `accessibility_score` column**, and `tab-performance.blade.php` **already renders it** — it's
  populated today only for Pressable-sourced scans (`PressableLighthouseClient`) and sits `null` for
  every PSI/GTmetrix row. The schema and UI already exist; the data is one query-param and a few parse
  lines away.

**This is the single quickest, cheapest, most valuable thing found in this entire research pass.**

### A real compliance issue in existing code, not a new feature

`BlacklistChecker`'s use of the Google Safe Browsing API may be outside its terms of service — Safe
Browsing is explicitly restricted to non-commercial use; Google's own guidance directs commercial
products to the paid-sounding-but-actually-free-to-100k/month **Web Risk API** instead. Same response
shape, same integration effort, no functional change — this is a should-fix, not a nice-to-have.

### Other genuinely new, verified findings

- **Client-facing status page** — zero new API needed (built entirely on uptime/SSL history already
  collected), confirmed as a real, commonly-requested agency differentiator by multiple current sources.
- **urlscan.io** (free tier: 1,000 unlisted scans/day) — live browser-based scanning catches a
  freshly-compromised site before static blacklists (URLHaus/Safe Browsing/Spamhaus) have indexed it.
  Genuinely distinct signal from the existing static-blacklist checks.
- **AbuseIPDB** (free, 1,000 checks/day) — attacking-IP reputation enrichment, complements the
  existing fail2ban/WAF layer with a different data type (IP reputation vs. raw log volume).
- **HaveIBeenPwned** — not free anymore (from $4.39/mo, sandboxed-only free key), but flagged as
  worth the small cost given how different the signal is (admin-credential breach exposure vs.
  site-malware signals) — a judgment call, not a "free API," so listed here rather than above.
- **LM Studio has a fully dormant, documented-but-never-built integration** (`resources/docs/integrations/lm-studio.md`
  already describes it as "experimental," config exists, zero consumers). Best-fit use given what's
  already collected: drafting plain-English, client-facing incident summaries from `SiteSecurityScan`
  rows (an existing mail-sending pattern already exists to send into). Must stay human-review-before-send —
  local 7-8B models summarize structured facts reliably but should never decide severity/status
  themselves.
- **Broken-link checking** and **GDPR/cookie-consent banner detection** — both have real agency demand
  but no clean free *bulk* API; both are better built natively (a crawl+HEAD job, and homepage
  CMP-script detection respectively) than paying for a third-party scraper actor.
- **Explicitly ruled out**: Patchstack's real vulnerability API (not free — custom pricing only),
  WPScan's own API (25 req/day free tier, too low for a 150-250-site fleet), VirusTotal (ToS forbids
  commercial-product use), Cloudflare Radar (aggregate-only, no per-site value beyond what the existing
  CF integration already reaches), standalone CrUX API (skip in favor of the PSI-embedded fields above
  first), third-party uptime services (UptimeRobot's free tier is non-commercial-only; Better
  Stack/StatusCake free tiers cap around 10 monitors — none scale to a 150-250-site fleet for free).

## Recommended build order (cheapest/highest-value first)

1. Parse PSI's already-fetched `loadingExperience` + 3 extra Lighthouse categories — near-zero effort.
2. Fix the Safe Browsing → Web Risk API swap — compliance fix, same effort as what's there today.
3. CISA KEV cross-reference — a lookup on data already collected.
4. SPF/DKIM/DMARC auditor — zero-cost, zero-dependency, flagged twice independently.
5. RDAP domain expiration + noindex watchdog — Gemini's two fully-spec'd plans, after the corrections above.
6. WP.org closed-plugin audit, Green Web Foundation, endoflife.date (corrected URL) — all real, free, low-effort.
7. urlscan.io, AbuseIPDB, DNSBL server-IP monitor (folded into `BlacklistChecker`) — genuinely new signals, low-medium effort.
8. Client-facing status page — no new API, real agency demand, medium UI effort.
9. Everything else (mShots, crt.sh, RunCloud/Ploi/Bunny.net modules, visual regression, LM Studio incident drafting) — real and worth doing, lower urgency or higher effort.
