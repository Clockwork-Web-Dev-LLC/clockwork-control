<?php

namespace Modules\AiRemedy\Services;

use App\Models\Server;
use App\Models\Site;
use App\Services\Uptime\UptimeProbeResult;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Modules\AiRemedy\Models\AiRemedyRun;
use Throwable;

class AiRemedyTriager
{
    public const MODE_WATCH = 'watch';

    public const MODE_INTERACTIVE = 'interactive';

    public const MODE_AUTO_HEAL = 'auto_heal';

    public function __construct(
        protected Settings $settings,
        protected OpenRouterClient $client,
        protected ServerTelemetryCollector $collector,
        protected RemedyExecutor $executor,
        protected CommandSafetyGuard $guard,
    ) {}

    /**
     * Get the active operating mode (default: watch).
     */
    public function getMode(): string
    {
        return (string) $this->settings->get('clockwork.ai_remedy.mode', self::MODE_WATCH);
    }

    /**
     * Get the configured cooldown window in minutes (default: 30).
     */
    public function getCooldownMinutes(): int
    {
        return (int) $this->settings->get('clockwork.ai_remedy.cooldown_minutes', 30);
    }

    /**
     * Determine if a server has already been triaged within the cooldown window.
     */
    public function isServerInCooldown(Server $server, ?int $cooldownMinutes = null): bool
    {
        $cooldown = $cooldownMinutes ?? $this->getCooldownMinutes();

        return AiRemedyRun::where('server_id', $server->id)
            ->where('started_at', '>=', now()->subMinutes($cooldown))
            ->exists();
    }

    /**
     * Determine if a site has already been triaged within the cooldown window.
     */
    public function isSiteInCooldown(Site $site, ?int $cooldownMinutes = null): bool
    {
        $cooldown = $cooldownMinutes ?? $this->getCooldownMinutes();

        return AiRemedyRun::where('site_id', $site->id)
            ->where('started_at', '>=', now()->subMinutes($cooldown))
            ->exists();
    }

    /**
     * Triage a site outage automatically. In watch mode, purely analyzes and logs without executing commands.
     *
     * @param  array<string, mixed>|null  $sshDiagnosis
     */
    public function triageSiteDowntime(Site $site, UptimeProbeResult $probe, ?array $sshDiagnosis = null): ?AiRemedyRun
    {
        if (empty($this->client->getApiKey())) {
            return null;
        }

        if ($this->isSiteInCooldown($site)) {
            return null;
        }

        $mode = $this->getMode();
        $server = $site->server;

        $telemetry = [
            'domain' => $site->domain,
            'status_code' => $probe->statusCode,
            'response_time_ms' => $probe->responseTimeMs,
            'error_message' => $probe->error,
            'site_user' => $site->site_user,
            'wp_path' => $site->wp_path,
            'server' => $server ? [
                'name' => $server->name,
                'hostname' => $server->hostname,
                'provider' => $server->provider,
                'vcpus' => $server->vcpus,
                'memory_mb' => $server->memory_mb,
            ] : null,
            'ssh_diagnostics' => $sshDiagnosis,
        ];

        try {
            $analysis = $this->client->diagnoseSiteDowntime(
                $site->domain,
                $probe->statusCode ?? 0,
                $telemetry,
                $probe->error
            );

            $actor = match ($mode) {
                self::MODE_WATCH => 'watch_mode',
                self::MODE_INTERACTIVE => 'interactive',
                self::MODE_AUTO_HEAL => 'autonomous',
                default => 'watch_mode',
            };
            $status = $analysis['is_fixable'] ? AiRemedyRun::STATUS_ANALYZED : AiRemedyRun::STATUS_UNFIXABLE;

            $safety = $this->guard->evaluateBatch($analysis['commands'] ?? []);
            $effectiveTier = $safety['highest_tier'];

            $run = AiRemedyRun::create([
                'trigger_type' => AiRemedyRun::TRIGGER_SITE_DOWNTIME,
                'status' => $status,
                'site_id' => $site->id,
                'server_id' => $server?->id,
                'actor' => $actor,
                'model_used' => $this->client->getModel(),
                'prompt_tokens' => $analysis['prompt_tokens'],
                'completion_tokens' => $analysis['completion_tokens'],
                'total_cost_usd' => $analysis['cost_usd'],
                'trigger_reason' => "Site {$site->domain} returned HTTP {$probe->statusCode}",
                'telemetry_snapshot' => $telemetry,
                'diagnosis_summary' => $analysis['summary'],
                'root_cause' => $analysis['root_cause'],
                'safety_tier' => $effectiveTier,
                'proposed_commands' => $analysis['commands'],
                'before_metrics' => $telemetry,
                'started_at' => now(),
            ]);

            // In Auto-Heal mode ONLY: if Tier 1 safe verified by backend guard, auto-execute
            if ($mode === self::MODE_AUTO_HEAL && $analysis['is_fixable'] && $effectiveTier === CommandSafetyGuard::TIER_1_SAFE && $safety['allowed']) {
                $this->executor->execute($run);
            }

            return $run;
        } catch (Throwable $e) {
            Log::warning('ai_remedy.site_triage_exception', [
                'site_id' => $site->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Triage a server spike or run a safe simulation. In watch/simulation mode, executes NO commands.
     *
     * @return array{ok: bool, run: ?AiRemedyRun, analysis: array<string, mixed>, telemetry: array<string, mixed>, error?: string}
     */
    public function triageServerSpike(Server $server, string $reason, string $actor = 'watch_mode', bool $isSimulation = false): array
    {
        if (! $isSimulation && $this->isServerInCooldown($server)) {
            return [
                'ok' => false,
                'run' => null,
                'analysis' => [],
                'telemetry' => [],
                'error' => "Server {$server->name} was recently triaged within the last {$this->getCooldownMinutes()} minutes (cooldown active).",
            ];
        }

        $mode = $this->getMode();

        if ($isSimulation) {
            $actor = 'simulation';
        } elseif ($actor === 'watch_mode') {
            $actor = match ($mode) {
                self::MODE_WATCH => 'watch_mode',
                self::MODE_INTERACTIVE => 'interactive',
                self::MODE_AUTO_HEAL => 'autonomous',
                default => 'watch_mode',
            };
        }

        // Step 1: Collect read-only SSH telemetry
        $telemetry = $this->collector->collect($server);
        if (! $telemetry['ok']) {
            return [
                'ok' => false,
                'run' => null,
                'analysis' => [],
                'telemetry' => $telemetry,
                'error' => $telemetry['error'] ?? 'Failed to collect server telemetry.',
            ];
        }

        // Step 2: OpenRouter AI Analysis
        $triggerReason = $isSimulation ? "[SIMULATION / WATCH MODE] {$reason}" : $reason;
        $analysis = $this->client->diagnoseServerSpike($telemetry, $triggerReason);

        // Step 3: Create audit run
        $status = $analysis['is_fixable'] ? AiRemedyRun::STATUS_ANALYZED : AiRemedyRun::STATUS_UNFIXABLE;

        $safety = $this->guard->evaluateBatch($analysis['commands'] ?? []);
        $effectiveTier = $safety['highest_tier'];

        $run = AiRemedyRun::create([
            'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
            'status' => $status,
            'server_id' => $server->id,
            'actor' => $actor,
            'model_used' => $this->client->getModel(),
            'prompt_tokens' => $analysis['prompt_tokens'],
            'completion_tokens' => $analysis['completion_tokens'],
            'total_cost_usd' => $analysis['cost_usd'],
            'trigger_reason' => $triggerReason,
            'telemetry_snapshot' => $telemetry,
            'diagnosis_summary' => $analysis['summary'],
            'root_cause' => $analysis['root_cause'],
            'safety_tier' => $effectiveTier,
            'proposed_commands' => $analysis['commands'],
            'before_metrics' => $telemetry,
            'started_at' => now(),
        ]);

        // Auto-heal only if explicitly set, not simulation, and verified Tier 1 Safe by backend guard
        if (! $isSimulation && $mode === self::MODE_AUTO_HEAL && $actor === 'autonomous' && $analysis['is_fixable'] && $effectiveTier === CommandSafetyGuard::TIER_1_SAFE && $safety['allowed']) {
            $this->executor->execute($run);
        }

        return [
            'ok' => true,
            'run' => $run->fresh(),
            'analysis' => $analysis,
            'telemetry' => $telemetry,
        ];
    }
}
