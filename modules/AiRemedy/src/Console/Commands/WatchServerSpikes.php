<?php

namespace Modules\AiRemedy\Console\Commands;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\AiRemedy\Services\AiRemedyTriager;
use Modules\AiRemedy\Services\OpenRouterClient;
use Modules\AiRemedy\Services\ServerTelemetryCollector;
use Modules\Core\ModuleStateResolver;

#[Signature('clockwork:watch-server-spikes {--force : Bypass cooldown}')]
#[Description('Audit servers for CPU, load, and memory spikes and trigger automated AiRemedy triage (Shadow Mode or Auto-Heal).')]
class WatchServerSpikes extends Command
{
    public function handle(
        ModuleStateResolver $resolver,
        Settings $settings,
        OpenRouterClient $client,
        AiRemedyTriager $triager,
        ServerTelemetryCollector $collector
    ): int {
        if (! $resolver->isEnabled('ai-remedy')) {
            $this->warn('AiRemedy module is disabled. Skipping spike watchdog.');

            return self::SUCCESS;
        }

        if (empty($client->getApiKey())) {
            $this->warn('OpenRouter API key is unconfigured. Skipping spike watchdog.');

            return self::SUCCESS;
        }

        $autoWatch = (bool) $settings->get('clockwork.ai_remedy.auto_triage_spikes', true);
        if (! $autoWatch) {
            $this->info('Automated spike triage is paused in settings. Skipping.');

            return self::SUCCESS;
        }

        $cpuThreshold = (int) $settings->get('clockwork.ai_remedy.cpu_spike_threshold', 85);
        $cooldownMin = $triager->getCooldownMinutes();
        $isForce = (bool) $this->option('force');

        $servers = Server::query()
            ->where('is_ignored', false)
            ->orderBy('name')
            ->get();

        $this->info(sprintf(
            'Auditing %d server(s) for spikes [Mode: %s | CPU Threshold: %d%% | Cooldown: %d min]',
            $servers->count(),
            strtoupper($triager->getMode()),
            $cpuThreshold,
            $cooldownMin,
        ));

        $spikesDetected = 0;
        $cooldownSkipped = 0;

        foreach ($servers as $server) {
            if (! $isForce && $triager->isServerInCooldown($server)) {
                $cooldownSkipped++;
                $this->line("  ↷ {$server->name}: in cooldown window (< {$cooldownMin}m ago)");

                continue;
            }

            $spikeReason = $this->detectSpike($server, $collector, $cpuThreshold);

            if ($spikeReason !== null) {
                $spikesDetected++;
                $this->warn("  ⚡ SPIKE DETECTED on {$server->name}: {$spikeReason}");

                try {
                    $result = $triager->triageServerSpike($server, $spikeReason);
                    if ($result['ok']) {
                        $this->info("    ✓ Triage completed [Run #{$result['run']?->id}]");
                    } else {
                        $this->warn("    ✗ Triage error: {$result['error']}");
                    }
                } catch (\Throwable $e) {
                    Log::warning('ai_remedy.watchdog_triage_failed', [
                        'server_id' => $server->id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error("    ✗ Exception: {$e->getMessage()}");
                }
            } else {
                $this->line("  ✓ {$server->name}: normal");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Watchdog sweep complete. servers=%d spikes=%d in_cooldown=%d',
            $servers->count(),
            $spikesDetected,
            $cooldownSkipped,
        ));

        return self::SUCCESS;
    }

    /**
     * Determine if a server is currently experiencing a CPU, load, or memory spike.
     */
    protected function detectSpike(Server $server, ServerTelemetryCollector $collector, int $cpuThreshold): ?string
    {
        // 1. Check latest cloud provider metric recorded within the last 15 minutes
        $latestMetric = ServerMetric::query()
            ->where('server_id', $server->id)
            ->where('recorded_at', '>=', now()->subMinutes(15))
            ->latest('recorded_at')
            ->first();

        if ($latestMetric) {
            $cpuVal = $latestMetric->cpu_pct !== null ? (float) $latestMetric->cpu_pct : null;
            if ($cpuVal !== null && $cpuVal >= $cpuThreshold) {
                return "CPU spike to {$cpuVal}% (threshold: {$cpuThreshold}%)";
            }

            if ($server->status === Server::STATUS_RED) {
                return 'Server status is RED in cloud metrics';
            }

            $cores = $server->vcpus ?: 1;
            $loadVal = $latestMetric->load_1 !== null ? (float) $latestMetric->load_1 : null;
            if ($loadVal !== null && $loadVal >= ($cores * 2)) {
                return "High 1-min load average ({$loadVal}) on {$cores} vCPU server";
            }

            $memVal = $latestMetric->memory_pct !== null ? (float) $latestMetric->memory_pct : null;
            if ($memVal !== null && $memVal >= 92) {
                return "Critical memory exhaustion ({$memVal}% used)";
            }

            return null;
        }

        // 2. Unlinked or non-cloud server: take a quick read-only SSH probe
        try {
            $telemetry = $collector->collect($server);
            if (! $telemetry['ok']) {
                return null;
            }

            $cores = $telemetry['cores'] ?? ($server->vcpus ?: 1);
            $load1 = $telemetry['loadavg'][0] ?? null;
            if ($load1 !== null && $load1 >= ($cores * 2)) {
                return "Live SSH probe detected load spike ({$load1} on {$cores} vCPUs)";
            }

            $memPct = $telemetry['memory']['used_percent'] ?? null;
            if ($memPct !== null && $memPct >= 92) {
                return "Live SSH probe detected critical memory usage ({$memPct}%)";
            }

            $topProcCpu = (float) ($telemetry['top_cpu'][0]['cpu_pct'] ?? 0);
            if ($topProcCpu >= $cpuThreshold) {
                $procCmd = $telemetry['top_cpu'][0]['command'] ?? 'unknown';

                return "Live SSH probe detected rogue process pegging CPU: {$procCmd} ({$topProcCpu}%)";
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
