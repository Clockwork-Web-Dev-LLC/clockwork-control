# Roadmap plans — 2026-09-05

Four independent, researched implementation plans, drafted by Claude (with parallel research/design
subagents grounded directly in this codebase) for Aaron to hand off to Gemini to build. Each plan
is self-contained: context, key architectural decisions with rationale, phased file-level steps,
and a verification section. None of this has been implemented yet — these are plans only.

Recommended build order: **versioning** (mostly process, unblocks tagging real releases for the
rest) → **theme-system** → **installer** → **backup-relay-generalization**.

- [`installer.md`](./installer.md) — a slick, guided web setup wizard (`/install`) covering the
  pre-auth gap (DB setup, `.env`/`APP_KEY`, migrations, first-admin-user) that today only exists as
  manual CLI steps. Deliberately NOT built as a `modules/` package — see the plan for why.
- [`theme-system.md`](./theme-system.md) — dark/light + PHPStorm-style named color schemes for the
  admin backend, built on Tailwind v4's CSS-variable model (`[data-theme]` attribute + override
  blocks), reusing an already-staged-but-unused `--color-surface-dark` token.
- [`backup-relay-generalization.md`](./backup-relay-generalization.md) — generalizing "Backup relay
  (Pressable → S3 Glacier)" beyond one hosting provider and beyond the original agency's private
  external-droplet dependency, while keeping that droplet working unmodified through the migration.
- [`versioning-release-cadence.md`](./versioning-release-cadence.md) — SemVer policy + a batched
  (not daily) release cadence, wiring up the self-update system (`/settings/updates`) that already
  exists in this codebase but has never actually been exercised (zero git tags today).

---

## Feature Roadmap & Candidate Modules (2026-09-05 Research)

- [`feature-roadmap-board.md`](./feature-roadmap-board.md) — **Live Feature Roadmap & Kanban Board**, tracking
  candidate features, free public APIs, and ecosystem integrations across 5 research domains.
- [`rdap-domain-expiration.md`](./rdap-domain-expiration.md) — Automated domain expiration & registrar tracking
  via the free ICANN RDAP bootstrap API (`rdap.org`), with multi-tier warnings (30/14/7 days).
- [`accidental-noindex-watchdog.md`](./accidental-noindex-watchdog.md) — Sentinel monitoring production sites for
  accidental `noindex` meta tags, `X-Robots-Tag` headers, and `robots.txt` blockers after launch.

---

## Research assessment & follow-on research (2026-09-05, Claude)

- [`gemini-research-assessment.md`](./gemini-research-assessment.md) — Verifies every API/technical claim
  in the three files above against live sources and this codebase's actual state. Most APIs are real;
  about half the plans have a factual error (wrong URL, wrong response field, an undisclosed rate limit)
  worth fixing before building. Also surfaces independent findings Gemini's research didn't cover — the
  best one: this app already fetches real-user performance + accessibility data on every PageSpeed
  Insights call and discards most of it (near-zero-effort win). Includes a recommended build order across
  everything in this directory.
- [`llm-auto-heal.md`](./llm-auto-heal.md) — Deep research into using a local LLM (LM Studio + Gemma 4 or
  similar) to diagnose and auto-remediate problems on customer servers, potentially as a premium tier.
  Covers: what self-healing already exists in this codebase (more than expected), why a bespoke
  tool-calling loop beats MCP for this specific use case (but a separate read-only `laravel/mcp` server
  is a good, lower-risk idea), model/quantization recommendations, a tiered-autonomy safety architecture
  (rule-based → LLM-assisted-with-human-approval → opt-in trusted auto-execute), six concrete playbook
  candidates ranked by safety, and an honest business-case assessment against the RMM/AI-SRE market.
  **Paused at the user's request — research/architecture only, not being built next.**

---

## Final, ready-to-build plans (2026-09-06, Claude)

The two features from the research above worth building next — corrected, and rewritten to exactly
match this codebase's existing SSL-cert-tracking conventions (confirmed file-by-file: state machine
shape, migration split, Blade section/table/recheck-button structure, `IssueCounter`/`IssuesController`
sync contract) rather than the module structure Gemini's original drafts proposed.

- [`domain-expiration-tracking.md`](./domain-expiration-tracking.md) — supersedes
  `rdap-domain-expiration.md`. Corrections: `app/Services/Domains/` not `modules/DomainExpiration`;
  3-state (green/yellow/red) not 4; `jeremykendall/php-domain-parser` instead of hand-rolled root-domain
  regex; rdap.org's 10-req/10-sec rate limit called out explicitly; exact `IssueCounter`/Blade
  integration steps spelled out.
- [`seo-indexability-watchdog.md`](./seo-indexability-watchdog.md) — supersedes
  `accidental-noindex-watchdog.md`. Corrections: piggybacks on the existing 5-minute uptime probe instead
  of adding a redundant 6-hourly fetch; the WordPress-side `blog_public` signal is scoped as real,
  separate Companion-repo work rather than something already available; `bopoda/robots-txt-parser`
  (verified to correctly implement Allow/Disallow precedence) instead of hand-rolled parsing; a real DOM
  parser (`DOMDocument`/`DOMXPath`) instead of regex for the meta-tag check.

