<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteIngestExclusion;
use App\Support\SiteIngestExclusionSet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\DigitalOcean\DigitalOceanClient;
use Modules\Hetzner\HetznerClient;
use Modules\SpinupWp\SpinupWpClient;
use Modules\Vultr\VultrClient;

#[Signature('clockwork:import-spinupwp {--dry-run : Report what would be imported without modifying the database}')]
#[Description('Import (or refresh) servers and sites from the SpinupWP API. Cross-references DigitalOcean, Hetzner, and Vultr servers by IP when the respective tokens are configured. Idempotent.')]
class ImportSpinupWp extends Command
{
    public function handle(SpinupWpClient $spinupwp, DigitalOceanClient $digitalocean, HetznerClient $hetzner, VultrClient $vultr): int
    {
        if (! $spinupwp->isConfigured()) {
            $this->error('CLOCKWORK_SPINUPWP_TOKEN is not set in .env.');

            return self::FAILURE;
        }

        if ($spinupwp->isViewOnly()) {
            $this->components->info('Running in View-Only Mode: No remote modifications or installations will be performed.');
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN: Simulating import without modifying database.');
        }

        $this->info('Fetching SpinupWP servers…');
        $spServers = $spinupwp->servers();
        $this->line('  '.count($spServers).' servers');

        $this->info('Fetching SpinupWP sites…');
        $spSites = $spinupwp->sites();
        $this->line('  '.count($spSites).' sites');

        $dropletsByIp = $this->fetchDropletsByIp($digitalocean);
        $hetznerByIp = $this->fetchHetznerByIp($hetzner);
        $vultrByIp = $this->fetchVultrByIp($vultr);

        $serverStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'spinupwp_id_nulled' => 0];
        $siteStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'wordpress' => 0, 'non_wordpress' => 0, 'skipped_no_server' => 0, 'skipped_excluded' => 0, 'spinupwp_id_nulled' => 0];

        try {
            DB::transaction(function () use ($spServers, $spSites, $dropletsByIp, $hetznerByIp, $vultrByIp, &$serverStats, &$siteStats, $dryRun) {
                $exclusions = SiteIngestExclusion::compile();
                foreach ($spServers as $row) {
                    $this->upsertServer($row, $dropletsByIp, $hetznerByIp, $vultrByIp, $serverStats);
                }

                $serverIdBySpinupId = Server::whereNotNull('spinupwp_id')->pluck('id', 'spinupwp_id');

                foreach ($spSites as $row) {
                    $this->upsertSite($row, $serverIdBySpinupId, $siteStats, $exclusions);
                }

                // Sweep: any local row whose spinupwp_id is NOT in the API's response
                // has been deleted on the SpinupWP side. Null its tracking id so
                // `clockwork:find-orphan-sites` (cron 03:35) classifies it on the
                // next tick. Without this sweep the deletion is invisible to the app.
                $liveSiteIds = collect($spSites)->pluck('id')->map(fn ($id) => (string) $id)->all();
                $siteStats['spinupwp_id_nulled'] = Site::query()
                    ->withoutGlobalScopes()
                    ->whereNotNull('spinupwp_id')
                    ->whereNotIn('spinupwp_id', $liveSiteIds)
                    ->update(['spinupwp_id' => null, 'updated_at' => now()]);

                $liveServerIds = collect($spServers)->pluck('id')->map(fn ($id) => (string) $id)->all();
                $serverStats['spinupwp_id_nulled'] = Server::query()
                    ->whereNotNull('spinupwp_id')
                    ->whereNotIn('spinupwp_id', $liveServerIds)
                    ->update(['spinupwp_id' => null, 'updated_at' => now()]);

                if ($dryRun) {
                    throw new \RuntimeException('DRY_RUN_ROLLBACK');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'DRY_RUN_ROLLBACK') {
                throw $e;
            }
        }

        $this->newLine();
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info($prefix.'Servers: '.json_encode($serverStats));
        $this->info($prefix.'Sites:   '.json_encode($siteStats));

        return self::SUCCESS;
    }

    protected function fetchDropletsByIp(DigitalOceanClient $client): array
    {
        if (! $client->isConfigured()) {
            $this->warn('DigitalOcean token not set — skipping droplet cross-reference. DO-provider servers will have provider_id null.');

            return [];
        }

        $this->info('Fetching DigitalOcean droplets…');
        $droplets = $client->droplets();
        $this->line('  '.count($droplets).' droplets');

        // Map public IPv4 -> compact droplet summary. We could store the full
        // payload but the consumer (upsertServer) only needs id + size info.
        $byIp = [];
        foreach ($droplets as $d) {
            $summary = [
                'id' => (string) $d['id'],
                'size_slug' => $d['size_slug'] ?? null,
                'vcpus' => isset($d['vcpus']) ? (int) $d['vcpus'] : null,
                'memory_mb' => isset($d['memory']) ? (int) $d['memory'] : null,
                'disk_gb' => isset($d['disk']) ? (int) $d['disk'] : null,
            ];
            foreach (($d['networks']['v4'] ?? []) as $iface) {
                if (($iface['type'] ?? null) === 'public' && ! empty($iface['ip_address'])) {
                    $byIp[$iface['ip_address']] = $summary;
                }
            }
        }

        return $byIp;
    }

    /**
     * Hetzner equivalent of fetchDropletsByIp(). Same IP-keyed shape so
     * upsertServer() can swap which lookup table it uses without further
     * branching. Hetzner exposes vcpus on `server_type.cores`, RAM on
     * `server_type.memory` (already in GB), disk on `server_type.disk` (GB).
     */
    protected function fetchHetznerByIp(HetznerClient $client): array
    {
        if (! $client->isConfigured()) {
            $this->warn('Hetzner token not set — skipping Hetzner cross-reference. Hetzner servers will have provider_id null.');

            return [];
        }

        $this->info('Fetching Hetzner servers…');
        $servers = $client->servers();
        $this->line('  '.count($servers).' Hetzner servers');

        $byIp = [];
        foreach ($servers as $s) {
            $type = $s['server_type'] ?? [];
            $memoryGb = isset($type['memory']) ? (float) $type['memory'] : null;
            $summary = [
                'id' => (string) $s['id'],
                'size_slug' => $type['name'] ?? null,
                'vcpus' => isset($type['cores']) ? (int) $type['cores'] : null,
                'memory_mb' => $memoryGb !== null ? (int) round($memoryGb * 1024) : null,
                'disk_gb' => isset($type['disk']) ? (int) $type['disk'] : null,
            ];
            $ip = $s['public_net']['ipv4']['ip'] ?? null;
            if ($ip) {
                $byIp[$ip] = $summary;
            }
        }

        return $byIp;
    }

    /**
     * Vultr equivalent of fetchDropletsByIp(). Maps IPv4 -> instance specs.
     */
    protected function fetchVultrByIp(VultrClient $client): array
    {
        if (! $client->isConfigured()) {
            return [];
        }

        $this->info('Fetching Vultr instances…');
        $instances = $client->instances();
        $this->line('  '.count($instances).' Vultr instances');

        $byIp = [];
        foreach ($instances as $inst) {
            $vcpus = $inst['vcpu_count'] ?? $inst['vcpus'] ?? null;
            $summary = [
                'id' => (string) $inst['id'],
                'size_slug' => $inst['plan'] ?? null,
                'vcpus' => $vcpus !== null ? (int) $vcpus : null,
                'memory_mb' => isset($inst['ram']) ? (int) $inst['ram'] : null,
                'disk_gb' => isset($inst['disk']) ? (int) $inst['disk'] : null,
            ];
            $ip = $inst['main_ip'] ?? null;
            if ($ip) {
                $byIp[$ip] = $summary;
            }
        }

        return $byIp;
    }

    protected function upsertServer(array $row, array $dropletsByIp, array $hetznerByIp, array $vultrByIp, array &$stats): void
    {
        $spinupId = (string) ($row['id'] ?? '');
        if ($spinupId === '') {
            return;
        }

        $name = $row['name'] ?? "spinupwp-{$spinupId}";
        $ip = $row['ip_address'] ?? null;

        // SpinupWP's server payload carries a `provider_name` field with
        // capitalized values like "DigitalOcean", "Hetzner", "Linode",
        // "Vultr", "Custom". We lowercase + branch to pick which API
        // we cross-reference for the droplet/server-type details.
        // Unknown/missing → assume DO so legacy imports keep working.
        $provider = strtolower((string) ($row['provider_name'] ?? Server::PROVIDER_DIGITALOCEAN));
        $providerSummary = match ($provider) {
            Server::PROVIDER_HETZNER => $ip ? ($hetznerByIp[$ip] ?? null) : null,
            Server::PROVIDER_DIGITALOCEAN => $ip ? ($dropletsByIp[$ip] ?? null) : null,
            Server::PROVIDER_VULTR => $ip ? ($vultrByIp[$ip] ?? null) : null,
            default => null,
        };

        $attributes = [
            'name' => $name,
            'hostname' => $ip ?: $name,
            'ssh_port' => (int) ($row['ssh_port'] ?? 22),
            'provider' => $provider,
            'provider_id' => $providerSummary['id'] ?? null,
            'size_slug' => $providerSummary['size_slug'] ?? null,
            'vcpus' => $providerSummary['vcpus'] ?? null,
            'memory_mb' => $providerSummary['memory_mb'] ?? null,
            'disk_gb' => $providerSummary['disk_gb'] ?? null,
            // Mirrored from SpinupWP — refreshed daily on import. Their dashboard refreshes
            // these on-demand, so values may be a few hours stale relative to their UI.
            'ubuntu_version' => $row['ubuntu_version'] ?? null,
            'upgrade_required' => (bool) ($row['upgrade_required'] ?? false),
        ];

        $server = Server::firstOrNew(['spinupwp_id' => $spinupId]);

        // SpinupWP's reboot_required can lag the truth by hours — their cron
        // re-probes /var/run/reboot-required on each server, but not always
        // immediately after a reboot. Result: their API still says YES for a
        // box we just rebooted, and a naive overwrite would un-do our local
        // optimistic-clear and leave the box stuck on /issues forever.
        //
        // Guard: skip the overwrite when scheduled_reboot_at is in the PAST
        // (the reboot has happened) AND within the last 24 hours (stale risk
        // is still real). For future-scheduled reboots — kernel update applied
        // but reboot postponed to off-hours — SpinupWP is correct: the box
        // genuinely needs a reboot, accept Y. AptUpdateProbe / probeReboot
        // will eventually converge the value from SSH truth either way.
        $rebootRequired = (bool) ($row['reboot_required'] ?? false);
        $recentlyRebooted = $server->exists
            && $server->scheduled_reboot_at !== null
            && $server->scheduled_reboot_at->isPast()
            && $server->scheduled_reboot_at->gt(now()->subHours(24));

        if (! $recentlyRebooted) {
            $attributes['reboot_required'] = $rebootRequired;
        }

        if (! $server->exists) {
            // A manually-added row (created via /servers/new — no spinupwp_id)
            // for the same (hostname, ssh_port) would collide on the unique
            // index on insert. Adopt this SpinupWP id onto the manual row
            // and treat the operation as an update.
            $manualMatch = Server::whereNull('spinupwp_id')
                ->where('hostname', $attributes['hostname'])
                ->where('ssh_port', $attributes['ssh_port'])
                ->first();

            if ($manualMatch) {
                $manualMatch->spinupwp_id = (int) $spinupId;
                foreach ($attributes as $key => $value) {
                    $manualMatch->{$key} = $value;
                }
                $manualMatch->save();
                $stats['updated']++;

                return;
            }

            $server->fill($attributes);
            $server->ssh_user = $server->ssh_user ?: (string) config('clockwork.ssh.default_user');
            $server->status = Server::STATUS_UNKNOWN;

            if ($this->shouldAutoIgnore($name)) {
                $server->is_ignored = true;
                $server->ignore_reason = (string) config('clockwork.monitoring.auto_ignore_reason');
            }

            $server->save();
            $stats['created']++;

            return;
        }

        $dirty = false;
        foreach ($attributes as $key => $value) {
            if ($server->{$key} !== $value) {
                $server->{$key} = $value;
                $dirty = true;
            }
        }

        if ($dirty) {
            $server->save();
            $stats['updated']++;
        } else {
            $stats['unchanged']++;
        }
    }

    protected function shouldAutoIgnore(string $name): bool
    {
        $patterns = (array) config('clockwork.monitoring.auto_ignore_name_patterns', []);

        foreach ($patterns as $pattern) {
            if ($pattern !== '' && stripos($name, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function upsertSite(array $row, $serverIdBySpinupId, array &$stats, SiteIngestExclusionSet $exclusions): void
    {
        $serverSpinupId = (string) ($row['server_id'] ?? '');
        $serverId = $serverIdBySpinupId->get($serverSpinupId);

        if (! $serverId) {
            $stats['skipped_no_server']++;
            $this->warn("Site {$row['domain']} references SpinupWP server {$serverSpinupId} not in our DB.");

            return;
        }

        $domain = $row['domain'] ?? null;
        if (! $domain) {
            return;
        }

        $spinupId = isset($row['id']) ? (string) $row['id'] : null;
        if ($exclusions->blocks(Site::HOSTING_PROVIDER_SPINUPWP, $spinupId, (string) $domain)) {
            $this->warn("  Skipping {$domain}: dropped from Clockwork ingest — not re-importing.");
            $stats['skipped_excluded']++;

            return;
        }

        $isWordpress = ($row['is_wordpress'] ?? false) === true;
        $stats[$isWordpress ? 'wordpress' : 'non_wordpress']++;

        $httpsEnabled = (bool) ($row['https']['enabled'] ?? false);
        $certExpires = $row['https']['certificate_expires'] ?? null;
        $certRenews = $row['https']['certificate_renews'] ?? null;

        $attributes = [
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'spinupwp_id' => $spinupId,
            'server_id' => $serverId,
            'site_user' => $row['site_user'] ?? null,
            'is_wordpress' => $isWordpress,
            'table_prefix' => $row['database']['table_prefix'] ?? 'wp_',
            'cert_expires_at' => $httpsEnabled && $certExpires ? $certExpires : null,
            'cert_renews_at' => $httpsEnabled && $certRenews ? $certRenews : null,
        ];

        // SpinupWP returns three booleans on every WP site indicating whether updates are
        // pending. They omit the keys for non-WP sites — keep ours nullable in that case.
        if ($isWordpress) {
            $attributes['wp_core_update'] = (bool) ($row['wp_core_update'] ?? false);
            $attributes['wp_theme_updates'] = (bool) ($row['wp_theme_updates'] ?? false);
            $attributes['wp_plugin_updates'] = (bool) ($row['wp_plugin_updates'] ?? false);
            $attributes['wp_updates_checked_at'] = now();
        }

        // Preserve user-set cert_source overrides (external, redirect_only). Only auto-fill
        // when the source was empty or set by us previously (none/spinupwp_le).
        // Bypass the notArchived global scope here — if the user archived a domain
        // and SpinupWP still reports it, we want to update the archived row in place
        // rather than fail the unique-domain constraint by inserting a duplicate.
        $existing = Site::withoutGlobalScopes()->where('domain', $domain)->value('cert_source');
        $userOverrides = [Site::CERT_SOURCE_EXTERNAL, Site::CERT_SOURCE_REDIRECT_ONLY];
        if (! in_array($existing, $userOverrides, true)) {
            $attributes['cert_source'] = $httpsEnabled ? Site::CERT_SOURCE_SPINUPWP_LE : Site::CERT_SOURCE_NONE;
        }

        $site = Site::withoutGlobalScopes()->firstOrNew(['domain' => $domain]);
        $existed = $site->exists;

        // New staging/dev sites should never fire uptime alerts.
        // Only set this on creation — don't override a manual re-enable on
        // an existing site.
        if (! $existed && $this->isStagingDomain($domain)) {
            $attributes['uptime_monitoring_enabled'] = false;
        }

        // auto_updates_paused defaults to true at the schema level (2026-05-09
        // opt-in flip) — a brand-new site would otherwise silently inherit
        // "paused" with no reason recorded. New sites start unpaused; only
        // set on creation — never overrides a deliberate manual pause.
        if (! $existed) {
            $attributes['auto_updates_paused'] = false;
        }

        $site->fill($attributes);
        $site->save();

        if (! $existed) {
            $stats['created']++;
        } elseif ($site->wasChanged()) {
            $stats['updated']++;
        } else {
            $stats['unchanged']++;
        }
    }

    private function isStagingDomain(string $domain): bool
    {
        return str_starts_with($domain, 'staging.')
            || str_starts_with($domain, 'dev.')
            || str_contains($domain, '.staging.')
            || (bool) preg_match('/-dev\./i', $domain);
    }
}
