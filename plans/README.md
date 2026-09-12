# Roadmap plans

Researched implementation plans for Clockwork Control. Several of the 2026-09-05 batch
have already shipped (installer, theme system, SemVer/release cadence, backup relay,
domain expiration, SEO indexability). Treat each file's own status notes as authoritative;
this index is a map, not a claim that everything below is still unbuilt.

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
- [`module-submission-workflow.md`](./module-submission-workflow.md) — Architecture and implementation plan
  for the community module submission flow, intake forms, automated feed validation, and in-app directory UX.
- [`archive/rdap-domain-expiration.md`](./archive/rdap-domain-expiration.md) — Automated domain expiration & registrar tracking (superseded)
  via the free ICANN RDAP bootstrap API (`rdap.org`), with multi-tier warnings (30/14/7 days).
- [`archive/accidental-noindex-watchdog.md`](./archive/accidental-noindex-watchdog.md) — Sentinel monitoring production sites for
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

## Client Reports follow-up (2026-09-07, Claude)

- [`client-reports-templates-scheduling.md`](./client-reports-templates-scheduling.md) — finishes
  the Client Reports module's half-built, currently-inert scheduling feature (a `ClientReportSchedule`
  model, table, and `clockwork:send-client-reports` command already exist, but the command ignores
  due-dates and `delivery_mode`, and isn't registered on the scheduler at all — plus there's no UI to
  create a schedule) and adds a genuinely new Templates feature (named, reusable subsets of report
  sections), matching a ManageWP-style panel Aaron shared as reference. 4 phases: fix what's broken →
  Templates → Scheduling UI → conditional-delivery stretch goal.

## Final, ready-to-build plans (2026-09-06, Claude)

The two features from the research above worth building next — corrected, and rewritten to exactly
match this codebase's existing SSL-cert-tracking conventions (confirmed file-by-file: state machine
shape, migration split, Blade section/table/recheck-button structure, `IssueCounter`/`IssuesController`
sync contract) rather than the module structure Gemini's original drafts proposed.

- [`domain-expiration-tracking.md`](./domain-expiration-tracking.md) — supersedes
  [`archive/rdap-domain-expiration.md`](./archive/rdap-domain-expiration.md). Corrections: `app/Services/Domains/` not `modules/DomainExpiration`;
  3-state (green/yellow/red) not 4; `jeremykendall/php-domain-parser` instead of hand-rolled root-domain
  regex; rdap.org's 10-req/10-sec rate limit called out explicitly; exact `IssueCounter`/Blade
  integration steps spelled out.
- [`seo-indexability-watchdog.md`](./seo-indexability-watchdog.md) — supersedes
  [`archive/accidental-noindex-watchdog.md`](./archive/accidental-noindex-watchdog.md). Corrections: piggybacks on the existing 5-minute uptime probe instead
  of adding a redundant 6-hourly fetch; the WordPress-side `blog_public` signal is scoped as real,
  separate Companion-repo work rather than something already available; `bopoda/robots-txt-parser`
  (verified to correctly implement Allow/Disallow precedence) instead of hand-rolled parsing; a real DOM
  parser (`DOMDocument`/`DOMXPath`) instead of regex for the meta-tag check.

---

## Next cycle (2026-09-11)

- [`next-cycle-restore-closed-plugins-eol.md`](./next-cycle-restore-closed-plugins-eol.md) — operator-confirm Glacier restore for custom sites (two-step stage/apply, fail-closed), then WP.org closed-plugin audit + CISA KEV badges, then PHP EOL on `/capacity`. Implementation brief for Claude. Board items like DNSBL, Green Web, Safe Updates, and new host modules are explicitly out of scope.
- [`gemini-implementation-restore-closed-plugins-eol.md`](./gemini-implementation-restore-closed-plugins-eol.md) — the Gemini-ready build plan for the file above, written after verifying both repos' actual state (2026-09-11): most of Workstream 1A is already committed; locks the open design decisions (per-archive sha256 sidecars, ignore-user-abort + polled status instead of WP-cron, current-prefix-only SQL import, copy-over file apply, `BackgroundArtisan`-driven Control flow) and maps every step to real files, conventions, schedule slots, and tests.

