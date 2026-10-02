<?php

namespace Modules\AiRemedy\Services;

use App\Models\Server;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
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
        protected RunApprovalPolicy $policy,
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

            // In Auto-Heal mode ONLY: if Tier 1 safe verified by backend guard and every
            // command passes the review panel's checks (real site paths), auto-execute;
            // otherwise it falls through to the triaged notification for a human.
            if ($mode === self::MODE_AUTO_HEAL && $analysis['is_fixable'] && $effectiveTier === CommandSafetyGuard::TIER_1_SAFE && $safety['allowed'] && $this->policy->autoExecuteBlockedReason($run) === null) {
                $this->executor->execute($run);
            } else {
                try {
                    if (app()->has(ChatNotifier::class)) {
                        app(ChatNotifier::class)->aiRemedyTriaged($run->fresh());
                    }
                } catch (Throwable $e) {
                    Log::warning('ai_remedy.triaged_notification_failed', [
                        'run_id' => $run->id,
                        'error' => $e->getMessage(),
                    ]);
                }
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
     * Get the configured allowed maintenance process patterns.
     *
     * @return array<int, string>
     */
    public function getAllowedMaintenanceProcesses(): array
    {
        $raw = $this->settings->get('clockwork.ai_remedy.allowed_maintenance_processes');

        if (is_array($raw)) {
            return array_values(array_filter(array_map('trim', $raw)));
        }

        if (is_string($raw) && trim($raw) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', strtolower($raw)))));
        }

        return ['rclone', 'mysqldump', 'logrotate', 'borgbackup', 'borg', 'gpbup', 'restic', 'duplicity'];
    }

    /**
     * Check if telemetry or AI analysis indicates recognized background maintenance.
     *
     * @param  array<string, mixed>  $telemetry
     * @param  array<string, mixed>  $analysis
     * @return array{is_maintenance: bool, type: ?string}
     */
    public function detectMaintenance(array $telemetry, array $analysis): array
    {
        // 1. Check AI analysis flag
        if (! empty($analysis['is_maintenance'])) {
            return [
                'is_maintenance' => true,
                'type' => $analysis['maintenance_type'] ?? 'Scheduled Maintenance',
            ];
        }

        // 2. Check top CPU processes against configured allowed maintenance processes
        $allowedList = $this->getAllowedMaintenanceProcesses();
        $topProcesses = $telemetry['top_cpu'] ?? [];

        foreach ($topProcesses as $proc) {
            $cmd = strtolower($proc['command'] ?? '');
            $cpu = (float) ($proc['cpu_pct'] ?? 0);

            if ($cpu < 40.0) {
                continue;
            }

            foreach ($allowedList as $allowed) {
                if ($allowed !== '' && str_contains($cmd, $allowed)) {
                    $friendlyType = match ($allowed) {
                        'rclone' => 'SpinupWP Backup',
                        'mysqldump' => 'Database Backup',
                        'logrotate' => 'System Log Rotation',
                        'borg', 'borgbackup', 'gpbup' => 'GridPane Borg Backup',
                        'restic' => 'Restic Backup',
                        'duplicity' => 'Duplicity Backup',
                        default => ucfirst($allowed).' Maintenance',
                    };

                    return [
                        'is_maintenance' => true,
                        'type' => $friendlyType,
                    ];
                }
            }
        }

        return [
            'is_maintenance' => false,
            'type' => null,
        ];
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
        $telemetry['wordpress_sites'] = app(ServerSiteContext::class)->sites($server);
        $analysis = $this->client->diagnoseServerSpike($telemetry, $triggerReason);

        // Step 3: Check for recognized benign maintenance activity
        $maintenance = $this->detectMaintenance($telemetry, $analysis);

        if ($maintenance['is_maintenance']) {
            $status = AiRemedyRun::STATUS_ALLOWED_MAINTENANCE;
            $mType = $maintenance['type'] ?: 'Allowed Maintenance';
            if (! str_contains($analysis['root_cause'], $mType)) {
                $analysis['root_cause'] = "{$mType}: {$analysis['root_cause']}";
            }
        } else {
            $status = $analysis['is_fixable'] ? AiRemedyRun::STATUS_ANALYZED : AiRemedyRun::STATUS_UNFIXABLE;
        }

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
        if (! $isSimulation && $mode === self::MODE_AUTO_HEAL && $actor === 'autonomous' && $analysis['is_fixable'] && $effectiveTier === CommandSafetyGuard::TIER_1_SAFE && $safety['allowed'] && $this->policy->autoExecuteBlockedReason($run) === null) {
            $this->executor->execute($run);
        } else {
            $autoMuteMaintenance = (bool) $this->settings->get('clockwork.ai_remedy.auto_mute_maintenance_alerts', true);
            $shouldNotify = ! ($maintenance['is_maintenance'] && $autoMuteMaintenance);

            if ($shouldNotify) {
                try {
                    if (app()->has(ChatNotifier::class)) {
                        app(ChatNotifier::class)->aiRemedyTriaged($run->fresh());
                    }
                } catch (Throwable $e) {
                    Log::warning('ai_remedy.triaged_notification_failed', [
                        'run_id' => $run->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::info('ai_remedy.maintenance_spike_muted', [
                    'run_id' => $run->id,
                    'server_id' => $server->id,
                    'maintenance_type' => $maintenance['type'],
                ]);
            }
        }

        return [
            'ok' => true,
            'run' => $run->fresh(),
            'analysis' => $analysis,
            'telemetry' => $telemetry,
        ];
    }
}
