---
title: AiRemedy (AI Incident Diagnostics, Shadow Mode & Self-Healing)
section: Features
order: 86
updated: 2026-09-28
author: Aaron Reimann
tags: [ai, openrouter, claude, diagnostics, self-healing, shadow-mode, remediation, ssh, modules]
tracks: [modules/AiRemedy/**]
---

**AiRemedy** turns Clockwork Control into an intelligent reliability engineer that monitors your fleet 24/7. Powered by OpenRouter (Claude 3.5 Sonnet, GPT-4o, and DeepSeek), it automatically investigates server performance spikes and website downtime, identifies the exact root cause in seconds, and provides targeted remediation.

Best of all, you don't have to give AI blind access to execute commands on your servers. With **Shadow Mode** (Watch Mode), AiRemedy acts as a silent observer: it diagnoses issues, drafts the ideal fix, and logs **"What AiRemedy Would Have Done"** without touching a single file or running a single command on your servers.

---

## Why AiRemedy Changes the Game

Most server control panels tell you when something is broken with a generic red alert. You get paged at 2:00 AM, log into SSH, poke around with `top` or check Nginx logs, and try to figure out which client site or PHP pool went rogue.

AiRemedy flips that workflow completely:
1. **Instant SSH Telemetry Bundle**: When a threshold is crossed (or on demand), Clockwork connects via SSH and snapshots load averages, memory distribution, top CPU processes, service states (`php*-fpm`, `nginx`, `mysql`, `redis`), and error logs in under 2 seconds.
2. **Senior Engineer Reasoning**: Instead of generic pattern matching, Claude 3.5 Sonnet analyzes the full telemetry context and provides an executive summary, pinpointing the specific script, worker, or pool responsible.
3. **Structured Remediation**: Proposes concise, non-destructive bash commands to resolve the issue.
4. **Guaranteed Zero-Risk Shadow Mode**: Run it for weeks in Shadow Mode to verify diagnosis accuracy before ever enabling autonomous fixes.

---

## Operating Modes

You can switch modes anytime under **Settings → Integrations & Alerts → AiRemedy** (`/ai-remedy/settings`):

| Operating Mode | Behavior | Server Mutations | Best For |
|---|---|:---:|---|
| **Shadow Mode** *(Watch Mode)* | Silently monitors site downtime and server spikes. Analyzes telemetry, diagnoses root causes, and logs **"What AiRemedy Would Have Done"** into the audit trail. | **0% (Zero)** | Recommended starting mode. Test reliability over weeks with zero risk. |
| **Interactive Copilot** | Proactively diagnoses incidents and stages proposed remediation commands in the audit log. Operators review the findings and click **[Approve & Execute]**. | Manual Only | Teams that want AI analysis but require human sign-off on every terminal command. |
| **Autonomous Self-Healing** | Safely auto-executes non-destructive **Tier 1** fixes (e.g., reloading a hung PHP-FPM pool, clearing stale `.maintenance` files) and verifies recovery immediately. | Tier 1 Only | Hands-free recovery for common transient WordPress & PHP-FPM glitches. |

> [!TIP]
> **Recommended Rollout Plan**: Keep AiRemedy in **Shadow Mode** for 2–4 weeks. Review the incident log whenever an alert fires to see how accurately Claude 3.5 diagnosed the issue. Once you're confident in the recommendations, switch to **Interactive** or **Autonomous Self-Healing**.

---

## How Shadow Mode (Watch Mode) Works

In Shadow Mode, AiRemedy operates in purely passive observability:
1. When a monitored site fails 2 consecutive checks and transitions to `down` (e.g., HTTP 502 Bad Gateway), the uptime engine notifies `AiRemedyTriager`.
2. Telemetry is gathered via SSH (HTTP response codes, response times, FPM socket status, server memory/load).
3. The prompt asks the LLM to identify the culprit and design the exact bash remediation steps.
4. AiRemedy creates an audit run with `actor: watch_mode` and status `analyzed`.
5. **No SSH commands are executed**.
6. The audit log (`/ai-remedy`) and incident forensics drawer highlight:
   * **Executive Summary & Identified Root Cause**
   * **What AiRemedy Would Have Done** (syntax-highlighted terminal preview)
   * **Safety Tier Classification**
   * **Token Cost** (typically ~$0.005 to $0.012)

---

## Live Test Simulation Engine

You don't have to wait for an actual server spike or outage to test AiRemedy. Built directly into the settings page is the **Run Safe Test Simulation** tool:

1. Select any connected server in your fleet.
2. Enter or customize a scenario (e.g., *"Simulated PHP-FPM worker pool exhaustion and 95% CPU spike"*).
3. Click **[Run Simulation]**.
4. Clockwork gathers real read-only SSH telemetry from that server, passes the snapshot to your selected OpenRouter LLM, and displays the diagnosis and proposed fixes in real time.
5. The run is logged under `[SIMULATION / WATCH MODE]` with an ID link to view full forensics. Zero commands are run on the target server.

---

## Command Safety Guard & Tier System

Every bash command suggested by the LLM is evaluated through `CommandSafetyGuard` before it can ever be executed:

* **Tier 1 (Safe — Permitted in Auto-Heal)**:
  * Reloading or restarting service pools: `systemctl reload php8.3-fpm`, `systemctl restart nginx`.
  * Purging transient application caches: `wp cache flush`.
  * Removing stale WordPress maintenance markers: `rm -f .../public_html/.maintenance`.
* **Tier 2 (Cautious — Always Requires Human Review)**:
  * Terminating runaway worker PIDs: `kill -15 <PID>`, `kill -9 <PID>`.
  * Service reloads affecting shared state (e.g., MySQL or Redis restarts).
* **Tier 3 (Prohibited — Strictly Blocked)**:
  * Destructive disk commands: `rm -rf`, disk wipes, format commands.
  * Untrusted pipe execution: `curl | bash`, `wget | sh`.
  * Database destruction: `DROP DATABASE`, `TRUNCATE`.
  * Network manipulation or modifying SSH authorized keys.

If an LLM hallucinates or suggests an unapproved command, `CommandSafetyGuard` rejects it automatically with an explanatory audit entry.

---

## Security & Privacy: Zero Database Storage

We take API key hygiene seriously:
* Your OpenRouter API key is **never saved in the database**.
* When you save your key in the UI, Clockwork writes it directly into your local `.env` file as `OPENROUTER_API_KEY`.
* Any legacy database setting rows for the key are explicitly wiped.
* The settings view only displays a masked indicator (`Saved in .env`).

---

## Economics & Model Selection

AiRemedy connects to [OpenRouter](https://openrouter.ai), giving you access to the world's leading reasoning models through a single API key:

* **Claude 3.5 Sonnet (`anthropic/claude-3.5-sonnet`)**: *Recommended & Default.* Unmatched capability in Unix systems administration, stack trace diagnosis, and code-level root cause analysis. Cost is ~$0.012 per incident diagnosis.
* **Claude 3.5 Haiku (`anthropic/claude-3.5-haiku`)**: High-speed, budget-friendly option at ~$0.002 per incident.
* **OpenAI GPT-4o (`openai/gpt-4o`)**: Top-tier general intelligence model with high accuracy across varied Linux distributions.
* **DeepSeek-V3 (`deepseek/deepseek-chat`)**: Ultra-affordable, high-quality open-weights model.

---

## Navigating the Audit Log (`/ai-remedy`)

The **AiRemedy Audit Log** gives you total visibility over automated and simulated operations:
* **Status Badges**: Filter by `Resolved`, `Analyzed (Watch/Pending)`, `Unfixable`, or `Failed`.
* **Mode Badges**: Clearly shows `Watch Mode`, `Simulation`, `Interactive`, or `Auto-Heal` on every row.
* **Slide-Over Forensics Drawer**: Click **[Forensics]** on any incident to inspect:
  * Pre-remediation system load, core count, memory usage, and top CPU processes.
  * Executive diagnosis and culprit summary.
  * Terminal command breakdown and full SSH standard output logs.
  * Token usage and micro-dollar incident cost.
