---
title: OpenRouter (AiRemedy Cloud LLM Engine)
section: Integrations
order: 112
updated: 2026-09-29
author: Aaron Reimann
tags: [integrations, ai, openrouter, llm, claude, airemedy, self-healing, diagnostics]
tracks: [modules/AiRemedy/**, config/clockwork.php]
---

OpenRouter serves as the unified cloud LLM gateway powering [AiRemedy](/docs/features/ai-remedy), Clockwork Control's autonomous server diagnostic and self-healing engine. Through OpenRouter, Clockwork leverages frontier reasoning models—by default **Anthropic Claude Sonnet 4.5**, with Claude Haiku 4.5, Claude Sonnet 4, GPT-4o, GPT-4o Mini, and DeepSeek-V3 selectable in AiRemedy Settings—to analyze raw SSH telemetry snapshots, determine root causes of performance spikes, and synthesize safe bash remediation commands.

## Why We Use OpenRouter

Rather than pinning Clockwork Control to a single proprietary SDK or provider account:
1. **Model Flexibility**: Switch between Claude Sonnet 4.5 (the default), Claude Haiku 4.5, GPT-4o, and cost-effective open-weights models without changing application code.
2. **Unified Billing & Rate Limits**: One prepaid balance covers all upstream AI providers with no individual enterprise contracts or tier minimums.
3. **Sub-Penny Precision Tracking**: Every diagnostic run tracks actual input and output tokens consumed, recording the exact cost (typically $0.004 to $0.012 per incident) directly in the audit log.
4. **Fallback & Reliability**: OpenRouter provides automated routing and failover across model providers if upstream APIs experience degraded performance.

---

## Configuration & Setup

### 1. Obtain an API Key
Generate an API key in your [OpenRouter Dashboard](https://openrouter.ai/keys).

### 2. Configure Credentials
The API key lives only in `.env` — never in the database. Either set it directly:

```dotenv
OPENROUTER_API_KEY=sk-or-v1-...
```

or paste it into **AiRemedy Settings** (`/ai-remedy/settings`), which writes it to `.env` for you (`OpenRouterClient::storeApiKey()`) and wipes any legacy database copy.

### 3. Choose a Model
The model is picked in **AiRemedy Settings** and stored as the `clockwork.ai_remedy.model` setting; it defaults to `anthropic/claude-sonnet-4.5` (`OpenRouterClient::DEFAULT_MODEL`). There is no env var for the model. The API endpoint (`https://openrouter.ai/api/v1/chat/completions`) and timeouts (30s for diagnosis, 10s for the connection test) are fixed in `OpenRouterClient`.

---

## Diagnostic Check & Health Verification

AiRemedy registers an official diagnostic check (`AiRemedyCheck`) with Clockwork's system diagnostics suite:
- Open **Diagnostics** (`/diagnostics`) to see whether the OpenRouter key is configured; the check is contributed via `AiRemedyServiceProvider::diagnosticCheck()`.
- On **AiRemedy Settings**, click **[Test Connection]** (`POST /ai-remedy/test-connection`) to send a tiny completion request (`meta-llama/llama-3.2-1b-instruct`) through OpenRouter and confirm the key works.

---

## Security, Rate Limiting & Safety Guardrails

Because AiRemedy issues SSH commands and interacts with external AI APIs, strict security guardrails are enforced:

1. **Authentication & Session Revocation**:
   All AiRemedy routes are protected by the `['web', 'auth', 'active']` middleware pipeline. If an operator's account is revoked, their access to AI triage and execution is terminated immediately.
2. **Abuse & Cost Rate Limiting**:
   Costly endpoints are throttled via Laravel's rate limiter:
   - `POST /ai-remedy/test-connection`: 10 requests / minute
   - `POST /ai-remedy/simulate`: 10 requests / minute
   - `POST /ai-remedy/servers/{server}/diagnose`: 15 requests / minute
   - `POST /ai-remedy/runs/{run}/execute`: 10 requests / minute
3. **Command Safety Guard**:
   Every command proposed by OpenRouter is validated against `CommandSafetyGuard`. Destructive operations (e.g. `rm -rf`, raw disk formatting, dropping databases, altering firewall rules) are categorized as **Tier 3 (Prohibited)** or **Unfixable** and are permanently blocked from execution.
4. **SSH Non-Zero Exit Code Handling**:
   The execution engine checks `$session->getExitStatus()`. If any bash command exits with code 1 or fails (such as an unmet `sudo` password prompt), the incident transitions to `STATUS_FAILED` rather than generating false-positive resolutions.
5. **Real-Time Chat Alerts**:
   Outcomes from OpenRouter diagnostics are instantly broadcast to Slack and Mattermost channels via `ai_remedy_triaged` and `ai_remedy_executed` chat webhook events.

---

## Files & Implementation

- `modules/AiRemedy/src/Services/OpenRouterClient.php` — HTTP client handling OpenRouter payload formatting, token tracking, and error handling.
- `modules/AiRemedy/src/AiRemedyCheck.php` — Diagnostics check implementation registered in `AiRemedyServiceProvider`.
- `modules/AiRemedy/src/Services/AiRemedyTriager.php` — Orchestrates read-only SSH telemetry probes and LLM prompting.
- `modules/AiRemedy/src/Services/RemedyExecutor.php` — SSH execution engine with exit-status validation.
- `config/clockwork.php` → `ai_remedy.openrouter_api_key` (reads `OPENROUTER_API_KEY`).
