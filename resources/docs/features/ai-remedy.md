---
title: AiRemedy (AI Incident Diagnostics & Self-Healing)
section: Features
order: 86
updated: 2026-09-28
author: Aaron Reimann
tags: [ai, openrouter, claude, diagnostics, self-healing, remediation, ssh, modules]
tracks: [modules/AiRemedy/**]
---

The **AiRemedy** module transforms Clockwork Control into an intelligent incident triage and self-healing reliability engine. Powered by OpenRouter (Claude 3.5 Sonnet, GPT-4o, and DeepSeek), it performs read-only forensic probes on spiking servers, pinpoints root causes, proposes targeted bash remediation scripts, and executes approved fixes over SSH with a permanent audit trail.

## Key Capabilities

1. **Multi-Model Intelligence via OpenRouter**:
   - A single OpenRouter API key unlocks access to Claude 3.5 Sonnet, GPT-4o, and DeepSeek through a unified OpenAI-compatible API.
   - Transparent cost calculation per incident (~$0.012 per diagnosis).
   - Keys are encrypted at rest using Laravel's `Crypt` service.

2. **On-Demand Server Triage (Human-in-the-Loop)**:
   - When a server spikes (CPU > 85%, high load, or memory exhaustion), operators can click **[Diagnose with AiRemedy]** directly on the server detail page (`/servers/{server}`) or dashboard.
   - Clockwork connects via SSH (`SshClient`) to gather a 2-second forensic bundle: `uptime`, `loadavg`, `free -m`, `df -h`, top CPU/memory processes, active services (`php*-fpm`, `nginx`, `mysql`, `redis`), and recent error logs.
   - Claude 3.5 Sonnet analyzes the snapshot and returns a structured breakdown: executive summary, root cause, safety tier, and non-destructive remediation bash commands.
   - Operators can review or edit the commands and click **[Execute Fix via SSH]** to immediately resolve the incident.

3. **Command Safety Guard & Policy Engine**:
   - Every proposed command is analyzed by `CommandSafetyGuard` before execution.
   - **Tier 1 (Safe)**: Service reloads/restarts (`systemctl reload php*-fpm`, `systemctl restart nginx`), cache purges (`wp cache flush`), and removing stale `.maintenance` files.
   - **Tier 2 (Cautious)**: Process killing (`kill -15`, `kill -9 <PID>`).
   - **Tier 3 (Prohibited)**: Destructive tokens (`rm -rf /`, piping curls to bash, raw SQL drops) are automatically blocked.

4. **Permanent Audit Trail & Forensics Dashboard (`/ai-remedy`)**:
   - Every diagnosis, suggested command, terminal stdout/stderr, and before/after metrics snapshot is recorded in `ai_remedy_runs` and mirrored to Clockwork's `action_logs`.
   - The dashboard tracks total diagnoses, healed count, unfixable incidents, and total token spend.

5. **Autonomous Downtime Self-Healing (Configurable)**:
   - When enabled in settings, recurring site outages caused by hung PHP-FPM pools or stale maintenance markers trigger automated Tier 1 recovery, immediate re-probing, and chat notifications.

## Configuration

Navigate to **AiRemedy → Configure Settings** (`/ai-remedy/settings`) or **Settings → Integrations & Alerts → AiRemedy**:
* **OpenRouter API Key**: Enter your key from `openrouter.ai/keys`. Use the **[Test Connection]** button to verify latency and connectivity.
* **Model Selection**: Choose between `anthropic/claude-3.5-sonnet` (default and recommended), `openai/gpt-4o`, or `deepseek/deepseek-chat`.
* **Automation Mode**: Toggle autonomous healing for non-destructive Tier 1 outages.
