---
title: AiRemedy (AI Incident Diagnostics, Shadow Mode & Self-Healing)
section: Features
order: 86
updated: 2026-09-28
author: Aaron Reimann
tags: [ai, openrouter, claude, diagnostics, self-healing, shadow-mode, remediation, ssh, modules, monitoring]
tracks: [modules/AiRemedy/**, app/Console/Commands/PollServers.php]
---

**AiRemedy** turns Clockwork Control into an intelligent reliability engineer that monitors your fleet 24/7. Powered by OpenRouter (Claude Sonnet 4.5 by default; Claude Haiku 4.5, GPT-4o, DeepSeek-V3 and others selectable), it automatically investigates server performance spikes and website downtime, identifies the exact root cause in seconds, and provides targeted remediation.

Best of all, you don't have to give AI blind access to execute commands on your servers. With **Shadow Mode** (Watch Mode), AiRemedy acts as a silent observer: it diagnoses issues, drafts the ideal fix, and logs **"What AiRemedy Would Have Done"** without touching a single file or running a single command on your servers.

---

## Continuous Server Spike Watchdog & Cooldown Engine

Beyond passive site downtime hooks, AiRemedy constantly monitors all servers in your fleet for abnormal performance spikes through a two-layer watchdog:

1. **Scheduled Fleet Watchdog (`clockwork:watch-server-spikes`)**:
   - Runs every 5 minutes across **all active servers**, including bare metal and custom VPS boxes unlinked from cloud providers.
   - For cloud servers, evaluates the newest recorded metrics from DigitalOcean, Hetzner, or Azure.
   - For custom VPS or servers without cloud provider metrics, gathers live telemetry via read-only SSH (`ServerTelemetryCollector`).
   - Dispatches automated triage when:
     - **CPU Utilization** $\ge 85\%$ (or your custom threshold).
     - **Load Average (1-minute)** $\ge 2\times$ the server's vCPU count.
     - **RAM Pressure** $\ge 92\%$.
2. **Cloud Provider Polling Hook (`clockwork:poll-servers`)**:
   - When 5-minute cloud provider metric polling detects a server transitioning to `STATUS_RED` or crossing the CPU threshold, it automatically notifies `AiRemedyTriager`.
3. **Intelligent Cooldown Protection**:
   - High-load incidents often take time to settle. To prevent runaway token spending and repeated duplicate LLM calls every 5 minutes, AiRemedy enforces a **cooldown window** (default: **30 minutes**, configurable from 5 to 1440 minutes).
   - If a server or site has been triaged within the window, subsequent watchdog ticks skip re-triaging until the cooldown expires. Operators can run `clockwork:watch-server-spikes --force` to bypass the cooldown.
4. **Configuration Controls**:
   - Available under **Settings → Integrations & Alerts → AiRemedy** (`/ai-remedy/settings`):
     - **Enable Automated Server Spike Monitoring**: Toggle automated background dispatch on/off.
     - **CPU Spike Trigger Threshold (%)**: Set custom trigger point (default: 85%).
     - **Spike Cooldown Window (Minutes)**: Set suppression duration (default: 30 minutes).

---

## Why AiRemedy Changes the Game

Most server control panels tell you when something is broken with a generic red alert. You get paged at 2:00 AM, log into SSH, poke around with `top` or check Nginx logs, and try to figure out which client site or PHP pool went rogue.

AiRemedy flips that workflow completely:
1. **Instant SSH Telemetry Bundle**: When a threshold is crossed (or on demand), Clockwork connects via SSH and snapshots load averages, memory distribution, top CPU processes, service states (`php*-fpm`, `nginx`, `mysql`, `redis`), and error logs in under 2 seconds.
2. **Senior Engineer Reasoning**: Instead of generic pattern matching, the selected model (Claude Sonnet 4.5 by default) analyzes the full telemetry context and provides an executive summary, pinpointing the specific script, worker, or pool responsible.
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
> **Recommended Rollout Plan**: Keep AiRemedy in **Shadow Mode** for 2–4 weeks. Review the incident log whenever an alert fires to see how accurately the model diagnosed the issue. Once you're confident in the recommendations, switch to **Interactive** or **Autonomous Self-Healing**.

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

## Command Safety Guard & Configurable Safety Tier Matrix

Every bash command suggested by the LLM is evaluated through `CommandSafetyGuard` before it can ever be executed. In `/ai-remedy/settings`, operators can interactively organize remediation actions across safety tiers via a **Kanban-style drag-and-drop board**:

* **Tier 1 (Safe — Permitted in Auto-Heal)**:
  * Non-destructive process deprioritization: `sudo renice -n 19 -p <PID>`, `sudo ionice -c 3 -p <PID>` (ensures heavy tasks yield CPU/disk cycles to web traffic without stopping).
  * Reloading service pools: `sudo systemctl reload nginx / php8.x-fpm`.
  * Restarting web daemons: `sudo systemctl restart nginx / php8.x-fpm`.
  * Purging transient application caches: `wp cache flush`, `wp transient delete --all`.
  * Removing stale WordPress maintenance markers: `rm -f .../public_html/.maintenance`.
  * Syntax configuration tests: `sudo nginx -t`.
  * Emergency log rotation: `sudo logrotate -f /etc/logrotate.d/nginx`.
* **Tier 2 (Cautious — Always Requires Human Review)**:
  * Graceful & forced worker process kills: `sudo kill -15 <PID>`, `sudo kill -9 <PID>`.
  * Database restarts: `sudo systemctl restart mysql`.
  * Disabling crashing plugins: `wp plugin deactivate <slug>`.
  * Service stop/start operations: `sudo systemctl (stop|start) <service>`.
* **Tier 3 (Prohibited — Strictly Blocked)**:
  * Any action moved to Tier 3 by the operator is blocked from execution.
  * **Permanent Security Floor (Unmovable)**: Hardcoded guardrails that can *never* be moved out of Tier 3:
    * Destructive filesystem wipes: `rm -rf /`, system directory deletion (`/etc`, `/boot`, `/bin`, `/var`).
    * Storage and partition formatting: `mkfs`, `fdisk`, `dd if=`.
    * Database destruction: `DROP DATABASE`, `DROP TABLE`, `TRUNCATE TABLE`.
    * Untrusted pipe execution: `curl ... | bash`, `wget ... | sh`, subshell pipelines.
    * Privilege compromise: `chmod 777 /`, direct edits to `/etc/sudoers` or `/etc/passwd`.

If an LLM suggests an unapproved or prohibited command, `CommandSafetyGuard` rejects it automatically with an explanatory audit entry.

---

## Allowed Maintenance Classification & Noise Filtering

Servers running SpinupWP regularly perform automated site backups to DigitalOcean Spaces or Amazon S3 using `/usr/bin/rclone` as root. On a 1 or 2 vCPU cloud server, archive compression and encryption naturally push CPU usage to 100%+ for several minutes.

In standard monitoring tools, this triggers false-alarm incident notifications. AiRemedy solves this with intelligent **Allowed Maintenance Classification**:

1. **Deterministic & AI Process Detection**:
   - The LLM and the triage engine inspect the server's top CPU processes against the configured **Allowed Maintenance Processes** (default: `rclone, mysqldump, logrotate, borgbackup, restic, duplicity`).
   - If a heavy process matches (e.g. `rclone` running backup uploads), the run is classified under status **`Allowed Maintenance`** rather than a failure incident.
2. **Smart Alert Muting**:
   - When **Mute Chat Notifications for Allowed Maintenance** is enabled in settings, Slack and Mattermost alerts are automatically silenced during maintenance spikes, eliminating alert fatigue.
   - The incident is still logged in the audit log and server modal with an `Allowed Maintenance` badge for complete forensic visibility.
3. **Gentle Priority Remediation**:
   - Rather than terminating the backup process (which corrupts the backup archive), AiRemedy recommends lowering its scheduling priority (`sudo renice -n 19 -p <PID>` and `sudo ionice -c 3 -p <PID>`).
   - This keeps the backup running safely in the background while immediately freeing CPU cycles for Nginx and PHP-FPM web traffic.

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

* **Claude Sonnet 4.5 (`anthropic/claude-sonnet-4.5`)**: *Recommended & Default.* Top-tier capability in Unix systems administration, stack trace diagnosis, and code-level root cause analysis. Cost is ~$0.015 per incident diagnosis.
* **Claude Haiku 4.5 (`anthropic/claude-haiku-4.5`)**: High-speed, budget-friendly option at ~$0.003 per incident.
* **OpenAI GPT-4o (`openai/gpt-4o`)**: Top-tier general intelligence model with high accuracy across varied Linux distributions.
* **DeepSeek-V3 (`deepseek/deepseek-chat`)**: Ultra-affordable, high-quality open-weights model.

---

## Navigation & Command Palette Quick Jump

AiRemedy is deeply integrated into the Clockwork Control global navigation:
* **Jump Code**: Press **⌘K** (or **Ctrl+K**) and type **`GA`** (or **`G A`**) to jump directly to the AiRemedy incident dashboard.
* **Universal Search Keywords**: Searching `airemedy`, `ai remedy`, `shadow mode`, `spike`, `triage`, `forensics`, or `auto-heal` in the Command Palette instantly surfaces the tool.
* **Settings Access**: Search `openrouter`, `claude`, or `api key` in the Command Palette to navigate straight to the AiRemedy Settings page.

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

---

## Server Header Modal & Live Telemetry Triage

Every server detail view includes a direct **"Diagnose with AiRemedy"** button in its header. Clicking it opens the interactive triage modal:
1. **Live 3-Step Telemetry Radar**: Visual progress indicators display the active probe phase in real time:
   - *Phase 1*: Read-only SSH telemetry probe (Top CPU, RAM, disk, load averages)
   - *Phase 2*: Process table and error log inspection
   - *Phase 3*: LLM root-cause reasoning (Claude Sonnet 4.5 by default) and remediation synthesis
2. **Executive Diagnosis & Safety Badges**: Displays a clear summary with an integrated safety tier pill (`Tier 1 · Safe`, `Tier 2 · Cautious`, or `Tier 3 · Prohibited`).
3. **Developer Terminal Window**: Inspect proposed commands with syntax formatting, inline editing, and a 1-click **Copy Commands** button.
4. **Execution & Root-Cause Sudo Guidance**: When running fixes via SSH, the executor validates `$session->getExitStatus()`. If a command exits with code 1 due to password-protected sudo, AiRemedy provides an actionable diagnostic alert explaining how to configure `/etc/sudoers` for non-interactive execution.

---

## Real-Time Chat Alerting & Observability

AiRemedy hooks directly into Clockwork's `ChatNotifier` system, fanning out incident updates to **Slack**, **Mattermost**, and webhooks:
* **`ai_remedy_triaged`**: Fires when automated or on-demand forensics conclude, delivering the identified root cause and proposed commands to ops channels.
* **`ai_remedy_executed`**: Broadcasts the execution outcome (Success vs. Failed), mode, safety tier, token cost, and executed commands.
* **`server_went_red` / `server_recovered`**: Pings channels immediately when a server spikes into alert status or recovers to healthy green.

---

## Security, Rate Limiting & Diagnostic Health Checks

* **Session Gating**: Protected with the `['web', 'auth', 'active']` middleware pipeline to ensure deactivated user sessions cannot access or trigger AI operations.
* **Abuse & Cost Rate Limiting**: AI and SSH routes are throttled (`throttle:10,1` on simulation/execute; `throttle:15,1` on diagnosis) to prevent rapid-click token waste.
* **Integration Diagnostics**: Contributes `AiRemedyCheck` to **Settings → Integrations**, allowing operators to test API latency, token balance, and key validity on demand.


