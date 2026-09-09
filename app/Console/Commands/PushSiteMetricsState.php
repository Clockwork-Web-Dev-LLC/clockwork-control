<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Push the current monitoring.site_metrics_enabled flag to Companion
 * sites that advertise resource-sampler-toggle. On-demand only — the
 * capacity settings toggle launches this in the background so the HTTP
 * request does not wait on a fleet-wide HTTP fan-out.
 */
class PushSiteMetricsState extends Command
{
    protected $signature = 'clockwork:push-site-metrics-state';

    protected $description = 'Push the site-metrics pause/resume flag to Companion sites.';

    public function handle(Settings $settings): int
    {
        $enabled = (bool) $settings->get('monitoring.site_metrics_enabled', true);
        $ok = 0;
        $skipped = 0;
        $failed = 0;

        $sites = Site::query()
            ->where('companion_installed', true)
            ->whereHas('server', function (Builder $q): void {
                $q->where('is_ignored', false)
                    ->whereDoesntHave('tags', fn (Builder $t) => $t->where('slug', 'staging'));
            })
            ->get();

        foreach ($sites as $site) {
            $caps = $site->companion_capabilities ?? [];
            if (! is_array($caps) || ! in_array('resource-sampler-toggle', $caps, true)) {
                $skipped++;

                continue;
            }

            try {
                $client = new ClockworkCompanionClient($site);
                $resp = $client->setResourceSamplerEnabled($enabled);
                if (! ($resp['ok'] ?? false)) {
                    throw new \RuntimeException('Companion returned ok=false');
                }
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('resource_sampler.toggle_push_failed', [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'enabled' => $enabled,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Pushed site-metrics state (enabled='.($enabled ? 'yes' : 'no')."): ok={$ok} skipped={$skipped} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
