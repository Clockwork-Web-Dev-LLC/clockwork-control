<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Pressable\PressableClient;
use Throwable;

/**
 * Pushes two Pressable-only security signals to each Companion-equipped
 * Pressable site's /security-summary-report endpoint: known plugin/theme
 * vulnerabilities (Pressable's own CVE feed) and Defensive Mode's current
 * status. Neither has a SpinupWP equivalent — this is pure Pressable-only
 * capability, not a parity fix.
 */
class PressableSecuritySummaryReport extends Command
{
    protected $signature = 'clockwork:pressable-security-summary-report
        {--site= : Limit to a single site (id or domain)}';

    protected $description = 'Push Pressable vulnerability alerts + Defensive Mode status to each Companion-equipped Pressable site.';

    public function handle(PressableClient $pressable): int
    {
        if (! $pressable->isConfigured()) {
            $this->error('Pressable API credentials not configured.');

            return self::FAILURE;
        }

        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->warn('No Companion-equipped Pressable sites matched.');

            return self::SUCCESS;
        }

        $stats = ['ok' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($sites as $site) {
            $caps = $site->companion_capabilities ?? [];
            if (! in_array('security-scans', $caps, true)) {
                $stats['skipped']++;
                $this->line("  [skip] {$site->domain} — Companion doesn't advertise security-scans");

                continue;
            }

            try {
                $plugins = $pressable->sitePluginSecurityAlerts($site->pressable_site_id);
                $themes = $pressable->siteThemeSecurityAlerts($site->pressable_site_id);
                $edgeCache = $pressable->siteEdgeCacheStatus($site->pressable_site_id);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.pressable_security_summary.fetch_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: Pressable fetch failed — {$e->getMessage()}");

                continue;
            }

            $defensive = is_array($edgeCache['defensive_mode'] ?? null) ? $edgeCache['defensive_mode'] : [];

            $report = [
                'fetched_at' => now()->toIso8601String(),
                'vulnerabilities' => [
                    'plugins' => array_map(fn (array $p) => [
                        'name' => $p['name'] ?? '',
                        'version' => $p['version'] ?? '',
                        'alerts' => $p['security_alerts'] ?? [],
                    ], $plugins),
                    'themes' => array_map(fn (array $t) => [
                        'name' => $t['name'] ?? '',
                        'version' => $t['version'] ?? '',
                        'alerts' => $t['security_alerts'] ?? [],
                    ], $themes),
                ],
                'defensive_mode' => [
                    'active' => (bool) ($defensive['active'] ?? false),
                    'active_until' => $defensive['active_until'] ?? null,
                ],
            ];

            try {
                (new ClockworkCompanionClient($site))->pushSecuritySummaryReport($report);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.pressable_security_summary.push_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: push failed — {$e->getMessage()}");

                continue;
            }

            $stats['ok']++;
            $vulnCount = count($plugins) + count($themes);
            $this->line("  [ok]   {$site->domain} — vulnerable={$vulnCount} defensive_mode=".($defensive['active'] ?? false ? 'on' : 'off'));
        }

        $msg = sprintf(
            'companion.pressable_security_summary.push complete: ok=%d failed=%d skipped=%d',
            $stats['ok'],
            $stats['failed'],
            $stats['skipped'],
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret')
            ->where('hosting_provider', Site::HOSTING_PROVIDER_PRESSABLE)
            ->whereNotNull('pressable_site_id');

        if ($needle = $this->option('site')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', $needle)->orWhere('domain', $needle);
            });
        }

        return $q->orderBy('domain')->get();
    }
}
