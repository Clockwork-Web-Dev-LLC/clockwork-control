<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteFieldMetric;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\PageSpeedInsights\ChromeUxReportClient;

class CollectFieldMetrics extends Command
{
    protected $signature = 'clockwork:collect-field-metrics
                            {--site= : ID or domain of a specific site to scan}
                            {--all : Scan all active sites, not just care-plan sites}
                            {--force : Run even if field data collection is disabled in settings}';

    protected $description = 'Collect real-user Core Web Vitals (CrUX) field metrics from Google Chrome UX Report API';

    public function handle(ChromeUxReportClient $client, Settings $settings): int
    {
        $enabled = $settings->get('performance_scans.field_data_enabled', config('clockwork.crux.enabled', true));
        if (! $enabled && ! $this->option('force')) {
            $this->warn('CrUX field data collection is disabled in settings. Use --force to run anyway.');

            return self::SUCCESS;
        }

        if (! $client->isConfigured()) {
            $this->error('CrUX / PSI API key is not configured.');

            return self::FAILURE;
        }

        $query = Site::query()->where('is_inactive', false)->whereNotNull('domain');

        if ($target = $this->option('site')) {
            $query->where(function ($q) use ($target) {
                if (is_numeric($target)) {
                    $q->where('id', (int) $target);
                } else {
                    $q->where('domain', $target);
                }
            });
        } elseif (! $this->option('all')) {
            $query->where('care_plan_enabled', true);
        }

        $sites = $query->orderBy('domain')->get();

        if ($sites->isEmpty()) {
            $this->info('No matching sites found for CrUX collection.');

            return self::SUCCESS;
        }

        $this->info("Collecting CrUX field metrics for {$sites->count()} site(s)...");

        $stats = ['ok' => 0, 'no_data' => 0, 'failed' => 0];

        foreach ($sites as $site) {
            $this->line("Processing: {$site->domain}");

            foreach ([SiteFieldMetric::FORM_FACTOR_PHONE, SiteFieldMetric::FORM_FACTOR_DESKTOP] as $formFactor) {
                $result = $client->queryRecord($site->domain, strtoupper($formFactor));
                $status = $result['status'] ?? SiteFieldMetric::STATUS_FAILED;

                if (isset($stats[$status])) {
                    $stats[$status]++;
                }

                $periodEnd = $result['period_end'] ?? Carbon::today()->toDateString();

                SiteFieldMetric::updateOrCreate(
                    [
                        'site_id' => $site->id,
                        'form_factor' => $formFactor,
                        'scope' => 'origin',
                        'period_end' => $periodEnd,
                    ],
                    [
                        'status' => $status,
                        'lcp_p75_ms' => $result['lcp_p75_ms'] ?? null,
                        'inp_p75_ms' => $result['inp_p75_ms'] ?? null,
                        'fcp_p75_ms' => $result['fcp_p75_ms'] ?? null,
                        'ttfb_p75_ms' => $result['ttfb_p75_ms'] ?? null,
                        'cls_p75_x1000' => $result['cls_p75_x1000'] ?? null,
                        'good_pct' => $result['good_pct'] ?? null,
                        'cwv_pass' => $result['cwv_pass'] ?? null,
                        'period_start' => $result['period_start'] ?? null,
                        'collected_at' => Carbon::now(),
                        'error' => $result['error'] ?? null,
                    ]
                );

                // Small pause to stay well within Google's rate limits
                usleep(150_000);
            }
        }

        $this->info("Completed. OK: {$stats['ok']}, Insufficient Traffic (No Data): {$stats['no_data']}, Failed: {$stats['failed']}.");

        return self::SUCCESS;
    }
}
