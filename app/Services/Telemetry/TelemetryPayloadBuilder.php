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
 * a bucketed site count, hosting-provider mix, and enabled-module ids.
 * Nothing else. See plans/anonymous-telemetry.md for why the scope is
 * this narrow — do not add fields here without checking with the
 * maintainer first.
 */
class TelemetryPayloadBuilder
{
    /**
     * Bucket boundaries applied to every numeric value in the payload —
     * never send an exact count, which could fingerprint a small install.
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
        return [
            'schema_version' => 1,
            'install_id' => $this->installId(),
            'sent_at' => Carbon::now()->startOfHour()->toIso8601String(),
            'sites_count_bucket' => $this->bucket(Site::query()->hostMonitored()->count()),
            'hosting_provider_mix' => $this->hostingProviderMix(),
            'modules_enabled' => $this->enabledModuleIds(),
        ];
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
     * @return array<string, string>
     */
    private function hostingProviderMix(): array
    {
        $siteCounts = Site::query()
            ->hostMonitored()
            ->selectRaw('hosting_provider, COUNT(*) as total')
            ->groupBy('hosting_provider')
            ->pluck('total', 'hosting_provider')
            ->all();

        $serverCounts = Server::query()
            ->monitored()
            ->selectRaw('provider, COUNT(*) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider')
            ->all();

        $mix = [];
        foreach ($siteCounts as $provider => $count) {
            $mix["site:{$provider}"] = $this->bucket((int) $count);
        }
        foreach ($serverCounts as $provider => $count) {
            $mix["server:{$provider}"] = $this->bucket((int) $count);
        }

        return $mix;
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
