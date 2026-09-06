<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\CloudProvider\CloudProviderRegistry;
use App\Services\Monitoring\CpuStatusClassifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Core\Contracts\CloudProvider;

#[Signature('clockwork:poll-servers')]
#[Description('Poll cloud-provider metrics (DigitalOcean, Hetzner, and Azure) for every linked server and update its health status (green/yellow/red).')]
class PollServers extends Command
{
    public function handle(CloudProviderRegistry $registry, CpuStatusClassifier $classifier): int
    {
        $providers = $registry->all();

        // We poll any server that has a provider_id (regardless of which
        // cloud). If no provider client is configured at all, there's
        // nothing to do — but a partial config is fine: each server uses
        // its own provider's adapter.
        if (array_filter($providers, fn (CloudProvider $p) => $p->isConfigured()) === []) {
            $this->error('No cloud provider tokens are configured. Set CLOCKWORK_DIGITALOCEAN_TOKEN, CLOCKWORK_HETZNER_TOKEN, and/or CLOCKWORK_AZURE_* credentials.');

            return self::FAILURE;
        }

        $windowMinutes = (int) config('clockwork.monitoring.metrics_window_minutes', 15);
        $now = Carbon::now();
        $end = $now->getTimestamp();
        $start = $end - ($windowMinutes * 60);

        $linked = Server::query()
            ->whereNotNull('provider_id')
            ->monitored()
            ->get();
        $unlinked = Server::query()
            ->whereNull('provider_id')
            ->monitored()
            ->count();
        $ignored = Server::query()->where('is_ignored', true)->count();

        $this->info(sprintf(
            'Polling %d linked server(s) over the last %d min%s%s',
            $linked->count(),
            $windowMinutes,
            $unlinked ? " ({$unlinked} unlinked → unknown)" : '',
            $ignored ? " ({$ignored} ignored, skipped)" : '',
        ));

        // One list call per provider beats N existence checks. We use these
        // to detect droplets/servers deleted at the provider — DO's metrics
        // endpoint keeps returning zero-valued samples for ~10-30 min after
        // destruction, so without this check a deleted droplet keeps showing
        // green until someone notices. Null = "couldn't fetch live list (or
        // this provider doesn't support the check, e.g. Azure), can't judge
        // deletion this run" — we skip the deletion check rather than
        // mass-flag everything as gone.
        $aliveIdsByProvider = [];
        foreach ($providers as $provider) {
            $aliveIdsByProvider[$provider->id()] = $provider->aliveProviderIds();
        }

        $stats = [
            Server::STATUS_GREEN => 0,
            Server::STATUS_YELLOW => 0,
            Server::STATUS_RED => 0,
            Server::STATUS_UNKNOWN => 0,
            'errors' => 0,
            'deleted' => 0,
        ];

        foreach ($linked as $server) {
            $provider = $registry->resolve($server->provider);
            // Null when $provider is NullCloudProvider (its id() isn't in
            // $aliveIdsByProvider, which is only seeded from $registry->all())
            // — same "can't judge deletion this run" meaning as a provider
            // whose own aliveProviderIds() call failed.
            $aliveIds = $aliveIdsByProvider[$provider->id()] ?? null;

            if ($provider->isDeletedAtProvider($server, $aliveIds)) {
                $server->status = Server::STATUS_UNKNOWN;
                $server->last_polled_at = $now;
                $server->save();
                $stats['deleted']++;
                $stats[Server::STATUS_UNKNOWN]++;
                $this->warn(sprintf(
                    '  ✗ %s [%s]: provider_id %s not found at %s — deleted? status=unknown',
                    $server->name,
                    $server->provider,
                    $server->provider_id,
                    $server->provider,
                ));

                continue;
            }

            try {
                $sample = $provider->metrics($server, $start, $end);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->warn("  ✗ {$server->name}: {$e->getMessage()}");

                continue;
            }

            $status = $classifier->statusForCpu($sample['cpu_pct']);

            $previous = $server->status;
            $server->status = $status;
            $server->last_polled_at = $now;

            if ($status === Server::STATUS_RED && $previous !== Server::STATUS_RED) {
                $server->last_alert_at = $now;
            }

            $server->save();
            $stats[$status]++;

            ServerMetric::create([
                'server_id' => $server->id,
                'recorded_at' => $now,
                'cpu_pct' => $sample['cpu_pct'],
                'memory_pct' => $sample['memory_pct'],
                'disk_pct' => $sample['disk_pct'],
                'load_1' => $sample['load_1'],
            ]);

            $this->line(sprintf(
                '  %s %s [%s]: cpu %s mem %s disk %s load1 %s → %s',
                $this->statusGlyph($status),
                $server->name,
                $server->provider,
                $sample['cpu_pct'] === null ? '—' : number_format($sample['cpu_pct'], 1).'%',
                $sample['memory_pct'] === null ? '—' : number_format($sample['memory_pct'], 1).'%',
                $sample['disk_pct'] === null ? '—' : number_format($sample['disk_pct'], 1).'%',
                $sample['load_1'] === null ? '—' : number_format($sample['load_1'], 2),
                $status,
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. green=%d yellow=%d red=%d unknown=%d errors=%d deleted=%d',
            $stats[Server::STATUS_GREEN],
            $stats[Server::STATUS_YELLOW],
            $stats[Server::STATUS_RED],
            $stats[Server::STATUS_UNKNOWN],
            $stats['errors'],
            $stats['deleted'],
        ));

        return self::SUCCESS;
    }

    protected function statusGlyph(string $status): string
    {
        return match ($status) {
            Server::STATUS_GREEN => '✓',
            Server::STATUS_YELLOW => '!',
            Server::STATUS_RED => '✗',
            default => '?',
        };
    }
}
