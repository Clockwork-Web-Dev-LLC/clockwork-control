# AiRemedy: AI Incident Diagnostics, Shadow Mode & Self-Healing

The **AiRemedy** module equips Clockwork Control with intelligent site outage triage, server performance spike diagnostics, and safe self-healing automation powered by OpenRouter (Claude Sonnet 4.5 by default; GPT-4o, DeepSeek and others selectable).

---

## Key Highlights

- **Shadow Mode (Watch Mode — Default)**: Observe AI diagnostics with **zero server mutations**. Gathers read-only telemetry, diagnoses root causes, and logs **"What AiRemedy Would Have Done"** without running any mutating commands or modifying files.
- **Continuous Spike Watchdog (`clockwork:watch-server-spikes`)**: Evaluates all fleet servers every 5 minutes (including unlinked VPS via SSH telemetry) for CPU $\ge 85\%$, load averages $\ge 2\times$ vCPU count, or memory pressure $\ge 92\%$.
- **Intelligent Cooldown Protection**: Automatically suppresses duplicate automated AI triage within a configurable window (default: 30 minutes) to prevent token drain during prolonged load.
- **Human-in-the-Loop or Autonomous Modes**: Switch between passive **Shadow Mode**, **Interactive Copilot** (operator approval required), and **Autonomous Self-Healing** (auto-executes non-destructive Tier 1 fixes).
- **Multi-Model Support via OpenRouter**: Use Claude Sonnet 4.5 (default, recommended for systems engineering), Claude Haiku 4.5, Claude Sonnet 4, GPT-4o, GPT-4o Mini, or DeepSeek-V3 with transparent per-incident cost tracking.
- **Strict API Key Hygiene**: OpenRouter API key is stored exclusively in `.env` (`OPENROUTER_API_KEY`). **Never stored in the database**. Configurable via `/ai-remedy/settings`, the `/setup` checklist, or `/settings/integrations/ai-remedy/limits`.
- **Automated Uptime & Provider Hooks**: Automatically triggers non-destructive triage whenever a monitored site transitions to `down` in `UptimeStateUpdater`, or when cloud provider metrics in `clockwork:poll-servers` transition a server to RED status.
- **On-Demand Safe Simulation**: Test AiRemedy against any connected server right from the settings page or dashboard with zero risk.
- **Multi-Tier Command Safety Guard**: All commands pass through `CommandSafetyGuard` to prevent hallucinated or dangerous commands (blocking disk wipes, script piping, destructive SQL drops, etc.).

---

## Operating Modes

AiRemedy supports three modes configured via `clockwork.ai_remedy.mode`:

| Mode | Key | Behavior | Executed Commands |
|---|---|---|:---:|
| **Shadow Mode** | `watch` | Passively collects read-only diagnostics upon outage or spike, prompts LLM, and logs proposed commands under "What AiRemedy Would Have Done". | **0** |
| **Interactive Copilot** | `interactive` | Generates diagnostic analysis and stages proposed commands in the audit log for human operators to review and click **Approve & Execute**. | On Approval |
| **Autonomous Self-Healing** | `auto_heal` | Automatically executes safe, non-destructive **Tier 1** commands (e.g. reloading hung PHP-FPM pools or clearing stale `.maintenance` flags) and verifies health. | Tier 1 Only |

---

## Architecture & Core Services

```
┌─────────────────────────────────────────────────────────────┐
│                       Incident Trigger                      │
│   (Site Downtime Hook / Server CPU Spike / On-Demand Test)  │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               ▼
                ┌─────────────────────────────┐
                │      AiRemedyTriager        │
                │  (Checks Mode & Dispatches) │
                └──────────────┬──────────────┘
                               │
            ┌──────────────────┴──────────────────┐
            ▼                                     ▼
┌───────────────────────────────┐ ┌────────────────────────────────┐
│   ServerTelemetryCollector    │ │       OpenRouterClient         │
│   (Read-only SSH snapshot:    │ │   (Claude Sonnet 4.5 default;  │
│   loadavg, top CPU, FPM, etc.)│ │   keys stored only in .env)    │
└──────────────┬────────────────┘ └───────────────┬────────────────┘
               └──────────────────┬───────────────┘
                                  ▼
                ┌─────────────────────────────────┐
                │      CommandSafetyGuard         │
                │  (Tier 1 Safe / Tier 2 Cautious │
                │   / Tier 3 Prohibited)          │
                └─────────────────┬───────────────┘
                                  │
         ┌────────────────────────┴────────────────────────┐
         │                                                 │
[If Shadow Mode]                                  [If Auto-Heal & Tier 1 Safe]
         ▼                                                 ▼
┌─────────────────────────────────┐               ┌─────────────────────────────────┐
│       AiRemedyRun Audit         │               │         RemedyExecutor          │
│ ("What AiRemedy Would Have Done"│               │ (Executes over SSH, captures    │
│  with ZERO executed commands)   │               │  stdout/stderr, logs action_log)│
└─────────────────────────────────┘               └─────────────────────────────────┘
```

### 1. `AiRemedyTriager`
High-level orchestrator located at `src/Services/AiRemedyTriager.php`:
- `triageSiteDowntime(Site $site, UptimeProbeResult $probe, ?array $sshDiagnosis)`: Invoked automatically by `UptimeStateUpdater` on outage transitions.
- `triageServerSpike(Server $server, string $reason, string $actor, bool $isSimulation)`: Gathers server telemetry and performs AI diagnosis.

### 2. `OpenRouterClient`
Manages LLM completions via OpenRouter at `src/Services/OpenRouterClient.php`:
- Handles system and user prompt assembly with strict JSON schema response expectations.
- Calculates micro-dollar incident costs based on model token rates.
- `getApiKey()` and `setApiKey()` interface directly with `EnvCredentialManager` to ensure zero database persistence.

### 3. `ServerTelemetryCollector`
Fast, non-blocking telemetry collection at `src/Services/ServerTelemetryCollector.php`:
- Gathers `uptime`, `loadavg`, `free -m`, `df -h`, top CPU/memory processes, active services (`php*-fpm`, `nginx`, `mysql`, `redis`), and recent error logs in ~1.5s over SSH.

### 4. `CommandSafetyGuard`
Safety classification engine at `src/Services/CommandSafetyGuard.php`:
- **Tier 1 (Safe)**: Pool reloads (`systemctl reload php8.3-fpm`), nginx reload, cache flush (`wp cache flush`), removing stale `.maintenance` file.
- **Tier 2 (Cautious)**: Process termination (`kill -15`, `kill -9`), service restarts affecting shared state. Requires human approval.
- **Tier 3 (Prohibited)**: Destructive disk/database tokens (`rm -rf`, piped curl scripts, raw SQL drops). Automatically blocked.

### 5. `RemedyExecutor`
Remediation execution at `src/Services/RemedyExecutor.php`:
- Connects via SSH (`SshClient`), runs approved commands sequentially with timeouts, captures combined terminal stdout/stderr, runs post-remedy telemetry, and mirrors entries to Clockwork's `action_logs`.

---

## Database Schema (`ai_remedy_runs`)

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `trigger_type` | `VARCHAR` | `site_downtime`, `server_spike`, or `manual_audit` |
| `status` | `VARCHAR` | `analyzed`, `executing`, `resolved`, `unfixable`, `rejected`, `failed` |
| `server_id` | `BIGINT UNSIGNED NULL` | Foreign key to `servers` |
| `site_id` | `BIGINT UNSIGNED NULL` | Foreign key to `sites` |
| `user_id` | `BIGINT UNSIGNED NULL` | Foreign key to `users` |
| `actor` | `VARCHAR` | `watch_mode`, `simulation`, `autonomous`, `interactive`, or `manual` |
| `model_used` | `VARCHAR` | e.g. `anthropic/claude-sonnet-4.5` |
| `prompt_tokens` | `INT` | Prompt token count |
| `completion_tokens` | `INT` | Completion token count |
| `total_cost_usd` | `DECIMAL(8,4)` | Estimated cost in USD (~$0.012) |
| `trigger_reason` | `VARCHAR NULL` | Context string (e.g. `Site example.com returned HTTP 502`) |
| `telemetry_snapshot` | `JSON NULL` | Pre-remedy system telemetry |
| `diagnosis_summary` | `TEXT NULL` | Executive overview of root cause |
| `root_cause` | `VARCHAR NULL` | Specific culprit |
| `safety_tier` | `VARCHAR` | `tier_1_safe`, `tier_2_cautious`, `unfixable` |
| `proposed_commands` | `JSON NULL` | Commands proposed by AI |
| `approved_commands` | `JSON NULL` | Commands approved/executed |
| `execution_output` | `LONGTEXT NULL` | Terminal output from SSH |
| `before_metrics` | `JSON NULL` | Metrics before execution |
| `after_metrics` | `JSON NULL` | Metrics after execution |
| `started_at` | `TIMESTAMP` | Start timestamp |
| `completed_at` | `TIMESTAMP NULL` | Completion timestamp |

---

## Routes

```php
Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/ai-remedy', [AiRemedyController::class, 'index'])->name('ai-remedy.index');
    Route::get('/ai-remedy/accuracy', [AiRemedyAccuracyController::class, 'index'])->name('ai-remedy.accuracy');
    Route::get('/ai-remedy/runs/{run}', [AiRemedyController::class, 'show'])->name('ai-remedy.show');
    Route::post('/ai-remedy/runs/{run}/verdict', [AiRemedyController::class, 'setVerdict'])->name('ai-remedy.verdict');

    Route::middleware('admin')->group(function () {
        Route::get('/ai-remedy/settings', [AiRemedySettingsController::class, 'index'])->name('ai-remedy.settings');
        Route::post('/ai-remedy/settings', [AiRemedySettingsController::class, 'update'])->name('ai-remedy.settings.update');
        Route::post('/ai-remedy/test-connection', [AiRemedyController::class, 'testConnection'])->name('ai-remedy.test-connection');
        Route::post('/ai-remedy/simulate', [AiRemedyController::class, 'simulateServer'])->name('ai-remedy.simulate');
        Route::post('/ai-remedy/servers/{server}/diagnose', [AiRemedyController::class, 'diagnoseServer'])->name('ai-remedy.server.diagnose');
        Route::post('/ai-remedy/runs/{run}/execute', [AiRemedyController::class, 'execute'])->name('ai-remedy.execute');
        Route::delete('/ai-remedy/runs', [AiRemedyController::class, 'destroyMany'])->name('ai-remedy.runs.destroy');
        Route::post('/ai-remedy/runs/hide', [AiRemedyController::class, 'hideMany'])->name('ai-remedy.runs.hide');
        Route::post('/ai-remedy/runs/unhide', [AiRemedyController::class, 'unhideMany'])->name('ai-remedy.runs.unhide');
    });
});
```

AiRemedy also hooks into Clockwork's centralized integration credentials and connection tunables at `/settings/integrations/ai-remedy/limits` and within the `/setup` onboarding checklist.

---

## Testing

Run the dedicated test suite:

```bash
php artisan test tests/Feature/AiRemedy/AiRemedyModuleTest.php
```

All code adheres strictly to Clockwork Control's code quality and design standards:
```bash
composer check   # Runs Pint, PHPStan Level 8, Biome, and TypeScript checks
```
