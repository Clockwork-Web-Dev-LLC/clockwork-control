# LLM-Powered Auto-Heal — Research & Architecture (2026-09-05)

## Executive summary

**Recommendation: build this, but as a tiered-autonomy system where the LLM classifies and a human
approves by default — never as a system where the model freely executes.** Every piece of research
below (this codebase's own existing patterns, the RMM/infrastructure industry, and the most advanced
funded "AI-SRE" competitors) converges on the same shape independently. That convergence is the
strongest signal in this whole document: this isn't a cautious guess, it's what everyone doing this for
real has already landed on.

The good news: this app already has more of the needed infrastructure sitting dormant than expected —
an unused LM Studio config, unused `llm_verdict` columns on the review-queue model, and a genuinely
mature (if undocumented) auto-repair mechanism already running today. The bad news, also good to know
early: "Gemma 4" is real (released April 2026) but isn't the strongest tool-calling model available —
Qwen3.6 and IBM Granite 4.1 currently score higher on agentic benchmarks and are worth benchmarking
head-to-head before committing.

---

## Part 1 — What already exists (read this before building anything)

This codebase already has more self-healing than it looks like at first glance — but all of it either
heals *Clockwork's own local process state* (`EnsureQueueWorker`, `ReapStaleServerUpdates`,
`ReapStaleUpdateJobs`), or is a narrow, already-proven, deterministic pattern worth extending rather
than replacing:

- **`ServerUpdater`'s nginx recovery** (`app/Services/Servers/ServerUpdater.php:76-102,148-172`) already
  does `systemctl reset-failed nginx && systemctl start nginx` after a failed post-upgrade restart, and
  reports `active`/`recovered`/`failed`/`absent` — a real, working diagnose-then-act-then-report loop.
  `failed` only ever surfaces today if that automatic retry didn't work.
- **Post-update plugin/theme reactivation already runs automatically today** via Companion's
  `verifyAndRepair()` (`app/Services/Companion/ClockworkCompanionClient.php:433`,
  `app/Jobs/AbstractRunUpdate.php:176-212`) — if an update deactivates a plugin/theme it shouldn't have,
  Companion reactivates it and logs the repair; only a failed reactivation becomes a hard failure. This
  is the most mature auto-heal already in the codebase, and it isn't documented as such anywhere.
- **Fail2ban false positives are already prevented at ban-time**, not corrected after: `Fail2banClient::banIp`
  refuses to ban any IP matching `IgnoreIpMatcher`, and `PullLlarLockouts` filters protected IPs before
  ever queuing a ban. What's genuinely missing is a *reconciliation sweep* — nothing periodically checks
  already-banned IPs against an updated ignore-list (e.g., after a server's IP changes or Cloudflare's
  range list updates) and un-bans stale matches. `SweepCfBans` + `Fail2banClient::unbanIps` already have
  the primitives for this; it's just never scheduled.
- **`ReviewQueueEntry` already has unused `llm_verdict`/`llm_reasoning`/`llm_score` columns** — scaffolded,
  always null. This is the natural, half-built home for an LLM diagnosis verdict, not a new table.
- **`config('clockwork.lm_studio.*')` is fully configured with zero consumers anywhere** — genuine
  scaffolding for a documented-but-never-built feature (`resources/docs/integrations/lm-studio.md`
  already frames it as "experimental," loopback-only, for nginx-log threat analysis that was never built).
- **Nothing today reaches into a customer server and takes a corrective action with zero human
  involvement**, except two narrow, pre-approved cases: the nginx-recovery retry above (unattended, but
  only as one step inside an operator-queued update), and the `AutoApproveRepeats` → `ProcessPendingBans`
  pipeline (autonomously bans an IP once a human-configured repeat-offense threshold is crossed, gated
  by a settings toggle). Auto-healing a customer server for an *arbitrary detected problem* is genuinely
  new territory — there's no precedent in this codebase to accidentally duplicate.

---

## Part 2 — Technical architecture: LM Studio, tool-calling, and MCP

**Use a bespoke direct tool-calling loop against LM Studio's OpenAI-compatible API. Do not use MCP for
the auto-heal loop itself.**

LM Studio has supported OpenAI-style function calling since v0.3.6 (`tools` array in, `tool_calls` back,
identical shape to OpenAI's API) — reliability is model-dependent, not just API-dependent; LM Studio
flags genuinely tool-use-trained models with a hammer badge in its catalog (Qwen2.5/3, Llama-3.1/3.3,
Mistral/Ministral, Hermes-tuned). LM Studio also added real MCP support recently — a desktop-chat-only
MCP host since v0.3.17, and a genuine programmatic "MCP via API" path since v0.4.0 (weeks-to-months old
as of this research). But MCP's entire design goal is solving the M-clients×N-tools interoperability
problem — it buys nothing here, since Clockwork Control is one app that already owns both the model
call and the fixed toolset. A bespoke loop (send the tool schema, parse `tool_calls`, execute the
matching internal PHP method, feed the result back, repeat) is less code, has no new listening process
to secure, and is the same pattern OpenAI's own function-calling docs describe. Enforce reliability via
LM Studio's `response_format` JSON-schema / grammar-constrained decoding, plus your own validation layer
(whitelist tool names, schema-validate arguments) before anything executes — never trust model output
by construction.

**Separately — build a `laravel/mcp` server anyway, but as a different, lower-risk feature.** An
official, Laravel-core-team-maintained MCP package (`laravel/mcp`, v1.0.0-beta.1, Aug 2026) now exists,
alongside an official PHP SDK built with the PHP Foundation and Symfony. This makes a **read-mostly MCP
server exposing fleet status/diagnostics/alert history** a genuinely cheap, same-framework addition —
letting an operator point Claude Code or Claude Desktop at their own fleet conversationally ("what's
wrong with client X's site right now?"). This is a real, distinct, much lower-risk feature than
auto-heal (read-only, no remediation authority) and shouldn't be conflated with or blocked on the
auto-heal decision.

---

## Part 3 — Model choice

"Gemma 4" is real — Google DeepMind released it April 2026 (Apache 2.0), with an MoE **26B-A4B** variant
(only ~3.8B active parameters) that's the standout fit for ordinary agency desktop hardware: reports of
40+ tok/s on an M4 Max/M3 Ultra, fitting in 16GB at Q4_K_M with a large context window. Gemma 4 also
fixed Gemma 3's biggest weakness for this use case — native function-call tokens instead of
prompt-engineered JSON, meaningfully more reliable structured output.

**However, it isn't the strongest option on agentic tool-use benchmarks.** Qwen3.6-30B-A3B and IBM
Granite 4.1 (purpose-built with agentic RL on software/terminal workflows, arguably the closest
philosophical match to "diagnose then pick one safe action") both currently score higher on realistic
tool-use benchmarks (BFCL v4, TAU2) than Gemma 4. **Recommendation: benchmark Gemma 4 26B-A4B against
Qwen3.6-30B-A3B and Granite 4.1 8B/30B empirically against this app's own fixed playbook menu before
committing** — generic leaderboards don't capture the specific narrow-classification task this feature
needs, and quantization matters more here than for prose: multiple independent reports found Q4_K_M
introduces intermittent tool-call failures in long-running agent sessions that Q6/Q8 doesn't, even
though Q4 "benchmarks fine" on single-shot scoring. **Use Q6_K_M minimum, Q8_0 preferred, for whichever
model actually issues remediation calls** — this is not a place to economize on quantization.

---

## Part 4 — Safety architecture: tiered autonomy

This is the first feature where a decision loop can end in a write action against a customer's
production server with no human click. Every existing autonomous pattern in this codebase stays inside
either Clockwork's own local state or a pre-approved deterministic workflow — auto-heal breaks that
boundary, so the architecture has to earn every increment of autonomy rather than assume it.

**Tier A — rule-based, no LLM, fully automatic.** For signals where diagnosis is unambiguous and the
fix is idempotent/safe to retry, a deterministic detector-action pair runs with no model involved at
all — the same shape as `EnsureQueueWorker`, aimed at a remote server via `SshClient` instead of
`launchctl`. Requires no AI-safety argument; it's just automation, and should ship first.

**Tier B — LLM-assisted diagnosis, human approval required.** For ambiguous root causes, the LLM's only
job is picking one candidate from a **fixed, code-defined playbook menu** (never inventing a
remediation) and filling in that playbook's declared, typed parameter slots. Output lands in a review
queue — literally the same shape `ReviewQueueEntry`'s already-scaffolded `llm_verdict`/`llm_reasoning`
columns imply. A human clicks "Approve & Run"; only then does SSH get touched.

**Tier C — trusted auto-execute, explicit opt-in per (playbook, server) pair.** After a playbook has a
track record of clean human approvals for a specific server, an operator can flip a per-pair whitelist
toggle — but rate limits, the loop-breaker, and the kill switch still apply. "Trusted" removes the
click, never the guardrails.

**Why the LLM must be classification + parameter selection, never shell-command generation:** if the
model's output space is "pick one of N pre-vetted, individually-tested playbooks, with typed/range-checked
parameters," the worst-case failure is *the wrong safe thing happens* — cheap, logged, bounded, because
every playbook was authored and tested by a human specifically because it's known-safe. If the model
generates freeform shell commands instead, the worst case is unbounded — a hallucinated destructive
command, a syntax slip, a prompt-injected string from log output landing in a command line. This
constraint is the entire safety argument for the feature and should be treated as non-negotiable from
day one, not a v2 hardening step.

**Guardrails, regardless of tier:** extend `ActionLogger` (new actor values, full playbook/parameter/signal
audit trail — no new table needed); per-playbook-per-server AND global-per-server-per-day rate limits
defined in the playbook registry; a loop-breaker (if the triggering signal recurs shortly after an
attempt, escalate to `ChatNotifier` instead of retrying — generalizing the pattern `ProcessServerUpdates`
already uses for failed nginx recovery); a single global kill switch (a `Settings` flag, mirroring
`auto_approve_repeats_enabled`'s existing pattern) that disables all Tier A/C execution instantly; and a
dry-run mode (logs "would have run: X" without executing) that should be the default state for weeks
after each new playbook ships, to build trust before real execution is ever enabled.

---

## Part 5 — Concrete playbook candidates, ranked safest → riskiest

All grounded in signals this app already collects — none require new data collection to start:

1. **Fail2ban stale-ban reconciliation** (safest) — periodically check already-banned IPs against the
   current `IgnoreIpMatcher` policy; auto-unban any stale match via the already-built `unbanIp()`. Purely
   corrective, idempotent, reuses existing safe primitives, zero new SSH surface.
2. **nginx post-upgrade failure, second-stage retry** — if the existing automatic
   `reset-failed && start` retry still leaves nginx `failed`, run `nginx -t` and surface its output;
   only auto-restore a `.dpkg-old` config backup if the test output is unambiguous.
3. **PHP-FPM restart on uptime-down** (FPM-failed/inactive case only, per `UptimeDiagnostician`'s existing
   diagnosis) — narrow, reversible, matches the exact command the diagnostic text already tells a human
   to run. The maintenance-mode-detected case must NOT auto-clear — could be an intentional deploy.
4. **Disk-pressure log/cache vacuum** — safe action (`journalctl --vacuum-size`, old `.gz` log rotation)
   but real wrong-diagnosis risk, since the actual disk hog (uploads, backups, DB growth) is unknown
   without inspection first — a genuine Tier-B candidate, not Tier A.
5. **Companion stuck-install single retry** — one automatic re-run of a failed Companion install; unsafe
   to loop, since repeated failures usually mean a site-specific problem that will just fail again.
6. **Backup relay staleness** — NOT automatable today without a new infrastructure change: the relay
   droplet has no connection back to Clockwork in either direction (report lands in S3, one-way). Flag
   as "needs a control channel before any auto-heal is possible," not a judgment call.

---

## Part 6 — Competitive landscape (why the tiered design isn't overcautious)

Every comparable system reviewed converges on the same shape:

- **RMM tools for MSPs** (NinjaOne, Atera, ConnectWise) — automation is pure rule-based
  condition-triggers-pre-written-script, never a model deciding what to do. Safety mechanisms:
  maintenance windows, staged rollout + rollback, escalate-to-human only on failure.
- **Deterministic infra self-healing** (Kubernetes probes, systemd `Restart=on-failure`, PM2, AWS ASG) —
  the proven "80% of the value" pattern: detect via health check → one deterministic action → capped
  retries → escalate. No diagnosis, no ambiguity — this is Tier A's model.
- **No incumbent WordPress fleet tool** (WP Umbrella, ManageWP, InfiniteWP, GoDaddy Pro) does genuine
  ambiguous-fault remediation — malware "auto-clean" is signature-match-and-strip, not diagnostic
  reasoning. Real gap, but also no WordPress-specific safety template to borrow from.
- **AI-SRE startups** (the actual comparable prior art) — Cleric and Traversal are **read-only,
  investigate-and-recommend only**; PagerDuty's SRE Agent executes but **only after per-action human
  approval**, and builds a runbook library over time rather than re-deciding freely. Notably, Cleric — a
  Gartner Cool Vendor and arguably the most credible player in this exact space — explicitly ships
  without auto-remediation and calls it a roadmap item, not a current capability. That's a strong signal
  about where the industry currently judges the trust line to sit.

---

## Part 7 — Business case (honest assessment)

The market currently prices **AI-assisted diagnosis** as a premium add-on (e.g., Atera's separate
per-technician "AI Copilot" SKU), not autonomous remediation — automation/scripting itself is bundled
into base RMM pricing everywhere. No WordPress-specific tool (WP Umbrella, ManageWP, InfiniteWP) sells a
distinct AI/automation tier today — real white space, but also no pricing anchor to match, meaning this
would be setting the market rather than following it. Every funded AI-SRE competitor with public
positioning (Cleric, Resolve AI, Causely) sells enterprise contracts with no public per-seat pricing,
and the most credible one deliberately avoids auto-remediation entirely.

Since this is self-hosted (the vendor doesn't run inference or absorb incident liability — the
customer's own local LM Studio does), SaaS per-incident/enterprise-contract pricing models don't
transfer. A recurring license-key subscription still makes sense despite self-hosting (you're selling
maintained playbooks/prompts/safety rails and update cadence as models and WordPress itself change, not
compute) — but should price well under SaaS comparables given the vendor bears none of the compute cost.

**The honest framing that matches both the safety research and the market**: sell this as "AI triage &
guided fix, one-click apply" (Tier B as the default, marketed configuration), not "fully autonomous
auto-heal." A botched auto-heal on a client's site is the agency's liability, not the tool vendor's —
agencies will recognize and value that the default behavior asks before it acts. True unattended
execution (Tier C) is the right *opt-in*, not the right *headline*.

---

## Recommended build order

1. **Phase 1 (no LLM, ships value immediately):** the execution/audit/rate-limit/kill-switch/dry-run
   infrastructure this whole feature depends on, plus the 2-3 safest Tier A playbooks (fail2ban stale-ban
   reconciliation, FPM restart on diagnosed-down). Independently valuable and sellable even if the LLM
   tier never ships.
2. **Phase 2:** wire the LLM diagnosis step into the existing `ReviewQueueEntry` pattern, benchmarking
   Gemma 4 26B-A4B against Qwen3.6/Granite 4.1 empirically against the real playbook menu before picking
   a default model.
3. **Phase 3 (last, deliberately):** opt-in trusted auto-execute per (playbook, server) pair — only once
   Phase 1-2 have produced real operational data (approval rates, false-positive rates, playbook
   reliability) to justify which pairs are actually safe to trust.
4. **Separately, any time:** a read-only `laravel/mcp` server for conversational fleet Q&A via Claude
   Code/Desktop — genuinely lower-risk, cheap given the mature official tooling, not blocked on any of
   the above.
