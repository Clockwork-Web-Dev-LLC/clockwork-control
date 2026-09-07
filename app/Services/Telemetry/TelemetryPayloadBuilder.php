<?php

namespace App\Services\Telemetry;

use App\Models\Server;
use App\Models\Site;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Core\InstalledModule;

/**
 * Builds the anonymous telemetry payload — deliberately narrow scope:
 * exact site & server counts, active modules, and per-module breakdowns.
 */
class TelemetryPayloadBuilder
{
    /**
     * Bucket boundaries applied to numeric values in the payload for backwards compatibility.
     *
     * @var array<int, string>
     */
    public const BUCKETS = ['0', '1-5', '6-25', '26-100', '101-500', '500+'];

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $siteCount = Site::query()->hostMonitored()->count();
        $serverCount = Server::query()->count();

        return [
            'schema_version' => 1,
            'install_id' => $this->installId(),
            'sent_at' => Carbon::now()->startOfHour()->toIso8601String(),
            'sites_count' => $siteCount,
            'servers_count' => $serverCount,
            'sites_count_bucket' => $this->bucket($siteCount),
            'servers_count_bucket' => $this->bucket($serverCount),
            'modules_enabled' => $this->enabledModuleIds(),
            'module_site_counts' => $this->moduleSiteCounts(),
            'module_server_counts' => $this->moduleServerCounts(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function moduleSiteCounts(): array
    {
        $counts = [];
        foreach ($this->enabledModuleIds() as $moduleId) {
            $count = $this->siteCountForModule($moduleId);
            if ($count !== null) {
                $counts[$moduleId] = $count;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function moduleServerCounts(): array
    {
        $counts = [];
        foreach ($this->enabledModuleIds() as $moduleId) {
            $count = $this->serverCountForModule($moduleId);
            if ($count !== null) {
                $counts[$moduleId] = $count;
            }
        }

        return $counts;
    }

    /**
     * Return exact site count for a given module, or null if panel-level/not site-specific.
     */
    public function siteCountForModule(string $moduleId): ?int
    {
        return match ($moduleId) {
            'spinupwp' => Site::query()->hostMonitored()->where('hosting_provider', 'spinupwp')->count(),
            'pressable' => Site::query()->hostMonitored()->where('hosting_provider', 'pressable')->count(),
            'wpengine' => Site::query()->hostMonitored()->where('hosting_provider', 'wpengine')->count(),
            'kinsta' => Site::query()->hostMonitored()->where('hosting_provider', 'kinsta')->count(),
            'cloudways' => Site::query()->hostMonitored()->where('hosting_provider', 'cloudways')->count(),
            'gridpane' => Site::query()->hostMonitored()->where('hosting_provider', 'gridpane')->count(),

            'digitalocean' => Site::query()->hostMonitored()->whereHas('server', fn ($q) => $q->where('provider', 'digitalocean'))->count(),
            'hetzner' => Site::query()->hostMonitored()->whereHas('server', fn ($q) => $q->where('provider', 'hetzner'))->count(),
            'azure' => Site::query()->hostMonitored()->whereHas('server', fn ($q) => $q->where('provider', 'like', 'azure%'))->count(),
            'linode' => Site::query()->hostMonitored()->whereHas('server', fn ($q) => $q->where('provider', 'linode'))->count(),
            'vultr' => Site::query()->hostMonitored()->whereHas('server', fn ($q) => $q->where('provider', 'vultr'))->count(),

            'backup-relay', 'backup_relay' => Site::query()->hostMonitored()->where('backup_relay_enabled', true)->count(),
            'contact-forms', 'contact_forms' => Site::query()->hostMonitored()->where('contact_form_test_enabled', true)->count(),
            'llar' => Site::query()->hostMonitored()->where('llar_enabled', true)->count(),
            'bill_com', 'bill-com' => Site::query()->hostMonitored()->whereNotNull('bill_com_customer_id')->count(),
            'client_reports', 'client-reports' => Site::query()->hostMonitored()->whereHas('client', fn ($q) => $q->whereNotNull('id'))->count(),
            'site_maintenance', 'site-maintenance' => Site::query()->hostMonitored()->where('care_plan_enabled', true)->count(),

            default => null,
        };
    }

    /**
     * Return exact server count for an infrastructure module, or null if panel-level/not infrastructure.
     */
    public function serverCountForModule(string $moduleId): ?int
    {
        return match ($moduleId) {
            'digitalocean' => Server::query()->where('provider', Server::PROVIDER_DIGITALOCEAN)->count(),
            'hetzner' => Server::query()->where('provider', Server::PROVIDER_HETZNER)->count(),
            'azure' => Server::query()->where('provider', 'like', 'azure%')->count(),
            'linode' => Server::query()->where('provider', Server::PROVIDER_LINODE)->count(),
            'vultr' => Server::query()->where('provider', Server::PROVIDER_VULTR)->count(),
            'cloudways' => Server::query()->where('provider', Server::PROVIDER_CLOUDWAYS)->count(),
            'gridpane' => Server::query()->where('provider', Server::PROVIDER_GRIDPANE)->count(),
            default => null,
        };
    }

    /**
     * Formatted module list with display name, servers, and site scope for the UI.
     *
     * @return list<array{id: string, name: string, servers: ?int, sites: ?int}>
     */
    public function moduleBreakdown(): array
    {
        $modules = InstalledModule::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        return $modules->map(fn (InstalledModule $m) => [
            'id' => $m->module_id,
            'name' => $m->name,
            'servers' => $this->serverCountForModule($m->module_id),
            'sites' => $this->siteCountForModule($m->module_id),
        ])->all();
    }

    /**
     * A random, permanent, non-reversible per-install identifier — lets the
     * receiving side dedupe "N unique installs" from "N pings," never
     * derived from anything install-specific. Generated once, lazily.
     */
    private function installId(): string
    {
        $existing = $this->settings->get('telemetry.install_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        $this->settings->put('telemetry.install_id', $id);

        return $id;
    }

    /**
     * @return list<string>
     */
    private function enabledModuleIds(): array
    {
        return InstalledModule::query()
            ->where('enabled', true)
            ->orderBy('module_id')
            ->pluck('module_id')
            ->all();
    }

    private function bucket(int $count): string
    {
        return match (true) {
            $count === 0 => '0',
            $count <= 5 => '1-5',
            $count <= 25 => '6-25',
            $count <= 100 => '26-100',
            $count <= 500 => '101-500',
            default => '500+',
        };
    }
}
