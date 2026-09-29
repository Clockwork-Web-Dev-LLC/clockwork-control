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
    ) {}

    /**
     * Get the active operating mode (default: watch).
     */
    public function getMode(): string
    {
        return (string) $this->settings->get('clockwork.ai_remedy.mode', self::MODE_WATCH);
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
                'safety_tier' => $analysis['safety_tier'],
                'proposed_commands' => $analysis['commands'],
                'before_metrics' => $telemetry,
                'started_at' => now(),
            ]);

            // In Auto-Heal mode ONLY: if Tier 1 safe, auto-execute
            if ($mode === self::MODE_AUTO_HEAL && $analysis['is_fixable'] && $analysis['safety_tier'] === 'tier_1_safe') {
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
            'safety_tier' => $analysis['safety_tier'],
            'proposed_commands' => $analysis['commands'],
            'before_metrics' => $telemetry,
            'started_at' => now(),
        ]);

        // Auto-heal only if explicitly set and not simulation
        if (! $isSimulation && $mode === self::MODE_AUTO_HEAL && $actor === 'autonomous' && $analysis['is_fixable'] && $analysis['safety_tier'] === 'tier_1_safe') {
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
