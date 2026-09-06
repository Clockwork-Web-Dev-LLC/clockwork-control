<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\CloudProvider\CloudProviderRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Core\Contracts\CloudProvider;

#[Signature('clockwork:reconcile-provider
    {--server= : Limit to one server (name, hostname, or ID)}
    {--dry-run : Report matches without writing}')]
#[Description('For every server with null provider_id, match the stored hostname/IP against DigitalOcean droplets, Hetzner servers, and Azure VMs and write provider, provider_id, size_slug, vcpus, memory_mb, disk_gb. Idempotent; safe to schedule.')]
class ReconcileProvider extends Command
{
    public function handle(CloudProviderRegistry $registry): int
    {
        $providers = $registry->all();

        if (array_filter($providers, fn (CloudProvider $p) => $p->isConfigured()) === []) {
            $this->error('No cloud provider tokens configured. Set CLOCKWORK_DIGITALOCEAN_TOKEN, CLOCKWORK_HETZNER_TOKEN, and/or CLOCKWORK_AZURE_* credentials.');

            return self::FAILURE;
        }

        $servers = $this->targetServers();

        if ($servers->isEmpty()) {
            $this->info('No unlinked servers to reconcile.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Reconciling %d server(s) with null provider_id…', $servers->count()));

        // Precedence order (Hetzner, Azure, DigitalOcean) comes from
        // CloudProviderRegistry::all() — matches this command's historical
        // match order exactly.
        $byIpPerProvider = [];
        foreach ($providers as $provider) {
            $byIpPerProvider[$provider->id()] = $provider->instancesByIp();
        }

        $matched = 0;
        $unmatched = 0;

        foreach ($servers as $server) {
            $ip = $server->hostname;

            $hit = null;
            $matchedProvider = null;
            foreach ($providers as $provider) {
                if (isset($byIpPerProvider[$provider->id()][$ip])) {
                    $hit = $byIpPerProvider[$provider->id()][$ip];
                    $matchedProvider = $provider;
                    break;
                }
            }

            if ($hit === null || $matchedProvider === null) {
                $this->line("  ✗ {$server->name} ({$ip}): no match in DO, Hetzner, or Azure");
                $unmatched++;

                continue;
            }

            $changes = [];
            if ($server->provider !== $matchedProvider->id()) {
                $changes[] = "provider {$server->provider} → {$matchedProvider->id()}";
            }
            $changes[] = "provider_id → {$hit['id']}";

            $this->info("  ✓ {$server->name} ({$ip}): ".implode(', ', $changes));

            if (! $this->option('dry-run')) {
                $server->update([
                    'provider' => $matchedProvider->id(),
                    'provider_id' => $hit['id'],
                    'size_slug' => $hit['size_slug'],
                    'vcpus' => $hit['vcpus'],
                    'memory_mb' => $hit['memory_mb'],
                    'disk_gb' => $hit['disk_gb'],
                ]);
            }

            $matched++;
        }

        $this->info(sprintf(
            'Done. matched=%d unmatched=%d%s',
            $matched,
            $unmatched,
            $this->option('dry-run') ? ' (dry-run — no writes)' : '',
        ));

        return self::SUCCESS;
    }

    protected function targetServers()
    {
        $q = Server::query()->whereNull('provider_id');

        if ($needle = $this->option('server')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', is_numeric($needle) ? (int) $needle : 0)
                    ->orWhere('name', $needle)
                    ->orWhere('hostname', $needle);
            });
        }

        return $q->orderBy('name')->get();
    }
}
