---
title: Release 1.9.1 + 1.10.0 plan
status: proposed
updated: 2026-09-30
author: Aaron Reimann
---

# Release 1.9.1 + 1.10.0 plan

**Status:** proposed. Do not build until Aaron gives an explicit go per workstream.
**Scope (agreed 2026-09-29/30):** real-user Core Web Vitals, an EmailAuth module (intended as a **paid** module), shipping auto-ignore, AiRemedy accuracy report, and Copilot follow-ups. The core Copilot approve-and-run fix is a **1.9.1 patch** (next section). Subdomain discovery was dropped.

## 1.9.1 (PATCH): make Copilot approvals actually work

**Why a patch:** 1.9.0 documents Copilot as "operators review the findings and click **[Approve & Execute]**" (`features/ai-remedy.md:61`), and the safety-tier matrix describes Tier 2 as "One-Click Approval." Neither exists for incidents. Runs that the watchdog (`WatchServerSpikes`, `PollServers`) or site-down detection (`UptimeStateUpdater`) create in Copilot mode can only be looked at. The incidents drawer (`index.blade.php`) and `show.blade.php` display commands, and the only caller of `POST /ai-remedy/runs/{run}/execute` is the on-demand Diagnose modal (`_server_modal.blade.php:86`), which is all-or-nothing. That's a bug against shipped docs. Per `plans/versioning-release-cadence.md`, PATCH = bug fixes, and new settings or additive migrations are MINOR. So **1.9.1 adds no migrations and no new settings.**

**Goal:** from any Copilot incident, an admin reviews the proposed fix, picks which commands to run (some, all, or none), and runs them. Every choice is recorded.

**Fix:**
1. **Approve & run panel** in the incidents-log drawer and on the run page, for runs with `actor = interactive` (and `manual`) in status `analyzed`. Each proposed command has its tier and a checkbox: Tier 1 pre-checked; Tier 2 unchecked, run only when deliberately ticked; Tier 3 visible, struck through, not selectable. Commands can be edited inline, and edits are re-checked by `CommandSafetyGuard`, as today. Shadow (`watch_mode`) runs stay view-only, matching the "executes zero commands" promise.
2. **Diagnose modal parity:** the on-demand modal gets the same checkboxes, so there is one way to run a fix.
3. **Executor guards** in `RemedyExecutor` / `AiRemedyController::execute`:
   - only status `analyzed`/`pending` may execute; anything else returns 409 (stops double-clicks and re-running)
   - `watch_mode` and `simulation` runs are refused server-side (not just hidden in the UI)
   - one execution per server at a time (cache lock)
   - blank or whitespace-only commands are rejected (today, blanking a field sends `""`)
   - a hardcoded age cutoff (a class constant, not a setting): runs older than 2 hours are refused with "re-diagnose first"
4. **Record decisions with no migration:** each command's decision (`run`/`skipped`/`edited`, plus the original text when edited) and its exit status go into the existing `ai_remediation` action-log `details` JSON. `approved_commands` holds exactly what ran, and `execution_output` gets per-command sections.
5. **Chat alert deep link:** Copilot-mode `ai_remedy_triaged` alerts link to the run with the panel open.
6. **Tier consistency:** manual diagnose stores the guard's tier, not the LLM's (`AiRemedyController.php:112` vs `AiRemedyTriager.php:305`), so the panel's tier labels match what the guard enforces.
7. **Docs:** `features/ai-remedy.md` describes the real flow; CHANGELOG `[1.9.1]` under Fixed. It also includes the post-1.9.0 fix already on `main` (`950c19d`, incident list stacking on small screens).

**Done when:** a watchdog-triggered Copilot run on a canary server opens from the Slack/Mattermost alert; 2 of 3 commands are selected and run and the third is skipped; the run page and action log show each decision, output, and exit status; a second execute is refused (409); a Shadow run can't be executed even by a hand-crafted POST; a run over 2 hours old is refused.

**Tests:** subset execution; skipped/edited recording; tier gating (Tier 3 never, Tier 2 only when sent); status 409; watch/simulation refusal; per-server lock; blank rejection; age cutoff; deep link present in the alert; admin-only; existing modal flow still works.

**Branch:** `fix/copilot-approvals` off `main` → PR → tag `v1.9.1`.

---

## 1.10.0 release goals

1.10.0 ships when every box is checked. Each goal is one feature branch and PR off `main`.

- [ ] **G1: Copilot follow-ups.** Staleness probe, review queue, and structured decisions on top of the 1.9.1 fix (§4A).
- [ ] **G2: AiRemedy proves itself.** Every Shadow/Copilot run gets an outcome and can get a verdict; `/ai-remedy/accuracy` exists (§4B).
- [ ] **G3: Auto-ignore is on record.** Already-built code reviewed, live-verified on a canary, and in the CHANGELOG (§3).
- [ ] **G4: Real-user speed data.** CrUX field metrics on the Performance tab and in client reports (§1).
- [ ] **G5: EmailAuth, sellable.** Where a paid module lives and how it's licensed is decided *before* code; then the module ships (§2).
- [ ] **G6: Guardrails.** Global stray-process guard in tests (§5).

Each workstream gets its own feature branch and PR (`feature/crux-field-data`, `feature/email-auth-module`, `feature/auto-ignore-release`, `feature/ai-remedy-accuracy`). All four are independent; suggested order is at the end.

---

## 1. Real-user Core Web Vitals (CrUX field data)

### What the research changed

The original idea was "PSI already returns `loadingExperience`, just stop discarding it." That only half works:

- PSI is a **fallback** engine. `RunPerformanceScans` uses Pressable's Lighthouse for Pressable sites, GTmetrix first for everything else, and PSI only when GTmetrix fails or isn't configured (`RunPerformanceScans.php:109-111, 176-225`). Most sites never produce a PSI payload.
- Scans are mobile-only, one-seventh of the fleet per night.

So parsing `loadingExperience` out of PSI would give field data to a handful of sites. Instead, **query the Chrome UX Report API directly**, independent of which lab engine a site uses.

### Design

**Where:** inside the existing `modules/PageSpeedInsights` module (same Google key, same "Google performance data" story). No new module.

**API** (verify every detail against the live API before building; written from memory):
- `POST https://chromeuxreport.googleapis.com/v1/records:queryRecord?key={key}`
- Body: `{"origin": "https://{domain}", "formFactor": "PHONE"}`, and a second call for `DESKTOP`. Optionally a `url` query for the homepage.
- Response: `record.metrics.{largest_contentful_paint, interaction_to_next_paint, cumulative_layout_shift, first_contentful_paint, experimental_time_to_first_byte}.percentiles.p75` plus `histogram` buckets, and `record.collectionPeriod`.
- **404 = not enough Chrome traffic.** Many small client sites will have no data. That is a first-class `no_data` state, never a failure, and it must not trip the PSI circuit breaker (`psi_unavailable_at`).
- Requires the **Chrome UX Report API** to be enabled on the Google Cloud project that owns the PSI key. Add an optional `crux_api_key` credential field that falls back to `psi.api_key`.

**Storage:** new table `site_field_metrics` (history, one row per site × form factor × collection).

| Column | Notes |
|---|---|
| `site_id`, `form_factor` (`phone`/`desktop`), `scope` (`origin`/`url`) | |
| `status` | `ok` / `no_data` / `failed` |
| `lcp_p75_ms`, `inp_p75_ms`, `fcp_p75_ms`, `ttfb_p75_ms` | ints, nullable |
| `cls_p75_x1000` | matches the existing `cls_x1000` convention on `site_performance_scans` |
| `good_pct` | JSON: share of "good" histogram bucket per metric |
| `cwv_pass` | bool: LCP, INP and CLS all "good" at p75 |
| `period_start`, `period_end`, `collected_at`, `error` | |

Unique on `(site_id, form_factor, scope, period_end)` so re-runs within a day are idempotent.

**Schedule:** new command `clockwork:collect-field-metrics`, weekly (CrUX is a 28-day rolling window, so daily adds nothing), care-plan sites only, throttled well under the API's per-minute quota. Gate on setting `performance_scans.field_data_enabled`.

**Where it shows:**
- Site Performance tab (`tab-performance.blade.php`): a "Real users, last 28 days" card beside the lab cards. It shows the CWV pass/fail badge, the p75 for each metric colored by Google's thresholds, and the collection period. `no_data` renders as "Not enough Chrome traffic for Google to report real-user data," not as an error.
- Site overview performance widget: CWV pass badge only.
- **Client reports:** `ClientReportCompiler::compilePerformance()` adds field data. This also fixes a real mismatch: the Speed & Performance section description (`ClientReportTemplate.php:57-59`) promises TTFB, which nothing stores today. CrUX TTFB fulfils it.

**Explicitly not doing:** storing raw payloads, CrUX History API trends (possible later), per-page URLs beyond the homepage, or changing lab-scan cadence.

**Tests:** `Http::fake` fixtures for ok / 404 no_data / 429 / partial metrics (sites with LCP but no INP); key fallback; no_data doesn't touch the PSI breaker; report compiler with and without field data.

**Docs:** `features/performance-scans.md`, `integrations/pagespeed-insights.md` (API enablement step), `reference/scheduled-jobs.md`, `architecture/data-model.md`. That last one also has a stale line (:135) claiming only Pressable rows carry a11y/SEO scores; fix it in passing.

---

## 2. EmailAuth module (SPF, DKIM, DMARC)

### Shape

A standalone module at `modules/EmailAuth`, id `email-auth`, following the AiRemedy layout: `src/EmailAuthServiceProvider.php`, `src/Services/`, `src/Models/`, `src/Console/Commands/`, `routes/web.php`, `database/migrations/`, `resources/views/` (namespace `email-auth::`), `README.md`.

Registration: psr-4 in root `composer.json` (as AiRemedy does), provider in `bootstrap/providers.php` security group, `ModuleCatalog::categorize()` → `security`, and add to the provider list in `tests/Feature/ArchitectureTest.php`. Disabling the module in `/settings/modules` removes routes, nav, schedule, and alerts.

### DNS lookups

There is no DNS TXT code or fakeable DNS layer anywhere today (`BlacklistChecker` calls raw `gethostbyname`; its tests hit real DNS). The module introduces an interface `DnsTxtResolver` with:

- **Default: DNS-over-HTTPS** (Cloudflare `cloudflare-dns.com/dns-query`, `application/dns-json`; Google `dns.google/resolve` as fallback). Reasons: it can be tested with `Http::fake`, it bypasses the app host's resolver cache, and it tells NXDOMAIN apart from "no TXT record" apart from "lookup failed". `dns_get_record()` conflates those three, which would produce false "missing DMARC" alerts on a resolver hiccup.
- A native `dns_get_record` implementation as a setting-selectable alternative for air-gapped installs.

A lookup failure is always `unknown`, never `fail`.

### What gets checked

Checks run per **registrable domain** (`RootDomainResolver::resolve()`), deduped across sites, so `www.` and subdomain sites share one result.

**SPF**
- Exactly one `v=spf1` TXT record. Two or more records is a hard fail per RFC 7208.
- Recursively expand `include:` / `redirect=` and count DNS-lookup mechanisms (`include`, `a`, `mx`, `ptr`, `exists`, `redirect`). Over 10 = fail. Track void lookups (over 2 = warn). Detect include loops.
- Terminal qualifier: `-all` pass, `~all` pass (note), `?all` warn, `+all` or none = fail.
- `ptr` present = warn (deprecated).

**DMARC**
- Look up `_dmarc.{apex}`. Missing = fail. Multiple `v=DMARC1` records = fail.
- `p=reject`/`quarantine` pass; `p=none` warn ("monitoring only"). Missing `rua` = warn. `pct<100` = note. Record `sp=`.

**DKIM**
- Selectors can't be enumerated from DNS. Probe a known list: `google`, `selector1`/`selector2` (Microsoft 365), `k1`, `s1`/`s2`, `mx`, `smtp`, `pm`, `mandrill`, `default`, `dkim`. Add a **per-domain custom selector** field.
- Report which selectors were found. "None of the known selectors found" is **warn/unknown, never fail**. Absence from a probe doesn't prove DKIM is missing.

**MX context**
- No MX or null MX (`0 .`): the domain doesn't handle mail. Grade it as a parked domain. The recommendation becomes `v=spf1 -all` + `p=reject`, and a missing DKIM record is expected.

BIMI and MTA-STS are out of scope for v1.

### Storage

`email_auth_checks`: one row per domain per run, pruned after 180 days.

| Column | Notes |
|---|---|
| `domain` | apex |
| `overall_status` | `pass` / `warn` / `fail` / `unknown` |
| `spf_status`, `spf_record`, `spf_lookup_count` | |
| `dmarc_status`, `dmarc_policy`, `dmarc_record` | |
| `dkim_status`, `dkim_selectors_found` (JSON) | |
| `mx_present` | |
| `findings` | JSON: list of `{check, severity, code, message}` |
| `checked_at`, `error` | |

Plus `email_auth_domains` (`domain`, `custom_dkim_selectors` JSON, `ignored_at`, `ignored_reason`, `last_overall_status`). This holds per-domain settings and the state used for transition alerts.

### Schedule and surfacing

- `clockwork:check-email-auth` (`--domain=`), weekly by default (DNS rarely changes), plus a Run-now button. Sites: `whereNotNull('domain')->where('is_inactive', false)->hostMonitored()`, the same set as domain expiration.
- **Module page `/email-auth`:** fleet table (domain, sites using it, SPF/DMARC/DKIM pills, lookup count, DMARC policy, last checked). There's a detail drawer with raw records, each finding in plain English, and a suggested fix record. It also has filters, per-domain ignore, and a custom-selector edit.
- **Chat alert** `email_auth_degraded`, fired on state transitions only (pass/warn → fail). Adding the event touches the five known places: `ChatNotifier::EVENTS` + interface, `ChatNotifierDispatcher`, `WebhookChatNotifier`, `ClientSlackNotifier` (`return false`), and `ChatNotifierGatingTest`.
- **Issues page:** see open decision D2.

### Framing

Findings are **advisory**. The agency often doesn't control client DNS or mail providers, so copy says "recommended" and each finding has a one-click ignore. There are no client-facing notices in v1.

### Tests

- Fake resolver fixtures: SPF include chains (under the limit, exactly 10, 11, loop, void lookups), duplicate SPF, `+all`, missing DMARC, `p=none`, duplicate DMARC, parked domain, DKIM found vs not found.
- DoH NXDOMAIN vs empty vs SERVFAIL.
- Apex dedupe; transition-only alerting; module disabled means no routes, schedule or alerts.

### Docs

New `features/email-auth.md`; update `integrations/overview.md`, `reference/scheduled-jobs.md`, `reference/web-routes.md`, `reference/artisan-commands.md`, and the Slack/Mattermost event lists.

---

## 3. Auto-ignore plugins after repeated update failures — ship what's built

### Current state (found during planning)

`plans/plugin-update-failure-ignore.md` still says "proposed, do not build." In fact **Phases 1 and 2 are already implemented**:

- **Control:** `plugin_update_failure_streaks` + ignore-table columns (migrations `2026_09_19_000001/000002`), `UpdateFailureStreakRecorder` hooked from `AbstractRunUpdate`, the `plugin_update_auto_ignored` chat event, `/updates` + care-plan + Issues view changes, `clockwork:push-update-exceptions` (scheduled), `ClockworkCompanionClient::pushUpdateExceptions`, tests (`UpdateFailureStreakTest.php`, `PushUpdateExceptionsTest.php`), docs in `features/updates.md`.
- **Companion and Renegade:** `UpdateExceptionsRoute`, `UpdateCoveragePage`, and tests, in both repos.

It landed inside the Dependabot batch commit `d638576` and **isn't in the Control CHANGELOG**, so it has never been announced or reviewed as a feature.

### 1.10.0 work

1. **Review pass** of the recorder against the plan's rules: nightly-only increments, transport errors and reaper-failed rows don't count, success resets the streak, new versions stay ignored, manual ignores are hidden from wp-admin, and the Issues count excludes ignored slugs. Each rule gets a test if one is missing.
2. **Confirm the plugin side has shipped:** which Companion and Renegade versions contain `UpdateExceptionsRoute`, and whether the fleet runs them. Control must no-op cleanly for sites without the `update-exceptions` capability.
3. **Live acceptance on one canary site:** force a streak to the threshold, then confirm the ignore row, the chat alert, the wp-admin Update coverage page, the `plugins.php` notice, and that Resume clears everything.
4. **Paperwork:** CHANGELOG entry under 1.10.0, and flip the old plan's status to "Phases 1–2 shipped."
5. **Phase 3 (client email drafts):** **not included** unless Aaron gives the separate yes the original plan requires. The same goes for mentioning auto-ignores in monthly client reports.

---

## 4. AiRemedy: working Copilot approvals + accuracy report

### 4A. Copilot follow-ups (after the 1.9.1 fix)

The core Copilot approve-and-run fix ships in **1.9.1** (see the top of this file). These are the MINOR-shaped extras that were deliberately left out of the patch:

- **Live staleness probe:** a read-only telemetry re-check showing "still happening" or "cleared since diagnosis" before Run is enabled, with a configurable `clockwork.ai_remedy.approval_stale_minutes`. 1.9.1 only has a hardcoded age cutoff.
- **Review queue:** a "Needs review" filter, a count badge on the AiRemedy nav item, and unreviewed Copilot runs auto-marked `expired` after 24 hours (new status and a scheduled command).
- **`command_decisions` column:** structured per-command decisions on the run itself, replacing 1.9.1's action-log storage, plus a backfill.
- **D7:** an operator-level approval permission and optional second confirmation for Tier 2.

### 4B. Accuracy report

**Goal**

Before switching any server from Shadow (`watch`) to Copilot (`interactive`) or Auto-Heal, show evidence of how often AiRemedy's diagnosis was right and whether its proposed fix would have been needed.

### Gaps found (4B)

- Shadow runs never record an outcome. `after_metrics` and `completed_at` are only written by `RemedyExecutor::execute()`.
- There is no operator feedback column.
- The LLM's `is_fixable`, `is_maintenance`, and `maintenance_type` are parsed (`OpenRouterClient.php:402-405`) but only survive as text inside `root_cause`.
- There is no server status history table, but `server_metrics` (5-minute samples, 90-day retention) covers cloud-linked servers.
- The manual diagnose path stores the **LLM's** tier while triager runs store the **guard's** tier (`AiRemedyController.php:112` vs `AiRemedyTriager.php:305`). Any tier statistics would mix the two, so fix it to use the guard's tier.

### Design

**a) Operator verdict.** 1.9.1's per-command run/skip/edit decisions are the strongest signal and feed this report automatically. An explicit verdict is still useful for Shadow runs, where nobody runs anything. Add columns to `ai_remedy_runs`: `verdict` (`correct` / `partial` / `wrong` / `unsure`), `verdict_note`, `verdict_by_user_id`, `verdict_at`. Show buttons in the run drawer and on the show page. Any authenticated operator can set it; changes are action-logged.

**b) Automatic outcome.** Add columns `outcome`, `outcome_details` (JSON), and `outcome_evaluated_at`. A new command, `clockwork:ai-remedy-evaluate-outcomes`, runs every 15 minutes. It picks runs aged 60 minutes or more with no outcome, from `watch_mode` and `interactive` actors (simulation and manual runs are excluded), and classifies them:

| Outcome | Server spikes (from `server_metrics`) | Site downtime (from `site_uptime_events`) |
|---|---|---|
| `self_resolved` | back under the trigger thresholds within 60 minutes, with no human action | an UP event within 60 minutes, with no human action |
| `human_resolved` | recovered, and `action_logs` show an operator action on that server or its sites in the window | same |
| `persisted` | still over the thresholds at 60 minutes | still down |
| `escalated` | server went red (CPU at or above the red threshold) after the run | n/a |
| `unknown` | no metrics (not cloud-linked) | no events |

Also store the structured LLM flags (`is_fixable`, `is_maintenance`, `maintenance_type`) as real columns on new runs.

**c) Report page `/ai-remedy/accuracy`**, filterable by date range, trigger type, and model:
- Runs; verdict breakdown; percent correct among verdicts given.
- Outcome breakdown, and the most useful number: **"would have acted, but it self-resolved"**, meaning Tier 1 commands were proposed yet the problem cleared on its own.
- Allowed-maintenance precision: how many maintenance-flagged runs actually self-resolved.
- Cost per run and cost per correct diagnosis.
- A descriptive readiness summary, for example "40 verdicts, 88% correct, 0 wrong Tier 1 proposals in 30 days." It **never changes the mode automatically**.

**Explicitly not doing:** a re-probe over SSH at +60 minutes for servers that aren't cloud-linked. It would cost extra SSH sessions, so it can be a later, off-by-default option.

### Tests

- Outcome classifier for each row of the table, using seeded `server_metrics`, `action_logs`, and uptime events.
- Simulation and manual runs excluded; verdict permissions; tier-source fix.
- Report aggregates on a seeded dataset.

### Docs

Update `features/ai-remedy.md` (the accuracy section and the recommended Shadow → Copilot rollout criteria) and `reference/web-routes.md` / `artisan-commands.md`.

---

## 5. Small hardening (ride along)

- **Global stray-process guard for tests.** Call `Process::preventStrayProcesses()` in `tests/TestCase.php` so a partial `Process::fake([...])` can never again run a real `composer install --no-dev` (the 1.9.0 incident). Expect some tests that relied on real `git rev-parse` calls to need explicit fakes; fix those.
- **Fakeable DNS for `BlacklistChecker`.** Once `DnsTxtResolver` exists, give Spamhaus an A-record equivalent so `BlacklistCheckerTest` stops doing real DNS. Do this only if it stays small.

---

## Open decisions for Aaron

- **D1. CrUX key.** Reuse the PSI key (requires enabling the Chrome UX Report API on that Google Cloud project), or add a separate key? The plan supports both, defaulting to reuse.
- **D2. EmailAuth on `/issues`.** Issues categories are hardcoded in three places (`IssueCounter`, `IssuesController`, `issues.blade.php`), and there is no module hook. Options:
  - (a) Module page and chat alerts only; not on Issues. Fully self-contained.
  - (b) Add a hardcoded Issues category gated on the module being enabled. This follows the precedent of core already referencing AiRemedy.
  - (c) Build a small `issueSources()` hook on `ModuleServiceProvider`. Cleanest, but it refactors all three Issues files.
  - **Recommendation:** (a) for 1.10.0, then (c) as its own later change.
- **D3. EmailAuth DNS default.** DoH (recommended) or native resolver.
- **D4. Auto-ignore Phase 3** client email drafts: yes or no for 1.10.0.
- **D6. Paid EmailAuth.** Control is MIT, so a module in the public repo is effectively free. Decide where paid modules live (the Phase 9 private overlay or a separate private repo), how they're licensed/gated, and whether v1 includes a client-facing email-health report (likely what makes it sellable).
- **D7. Copilot approvals.** Who can approve: admins only (matches today's `admin` middleware on execute) or a new "operator" permission? Should Tier 2 need a second confirmation?
- **D5. Accuracy report.** Should verdicts be admin-only or open to any operator?

## Suggested order

1. **G3 Auto-ignore release** (small; mostly verification).
2. **G2 Accuracy report** (outcome data only accumulates after it ships; builds on 1.9.1's recorded decisions). **G1 follow-ups** go in the same PR or right after it, since both touch the run screens.
3. **G4 CrUX field data.**
4. **G5 EmailAuth** (largest; blocked first on the paid-module decision, D6).
5. Hardening items alongside whichever PR touches the same files.

Version: **1.10.0** (new features, no breaking changes). Companion or Renegade bumps are needed only if the auto-ignore review finds plugin-side fixes.
