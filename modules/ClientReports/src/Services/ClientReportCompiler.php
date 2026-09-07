<?php

namespace Modules\ClientReports\Services;

use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\ContactFormTestRun;
use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Models\SiteSecurityScan;
use App\Models\SiteTrafficDaily;
use App\Models\SiteUptimeEvent;
use App\Support\Settings;
use Illuminate\Support\Carbon;

class ClientReportCompiler
{
    public function __construct(
        protected Settings $settings
    ) {}

    /**
     * Compile all report sections for a site over the specified date window.
     *
     * @return array<string, mixed>
     */
    public function compile(Site $site, Carbon $periodStart, Carbon $periodEnd, ?string $customNotes = null): array
    {
        $start = $periodStart->copy()->startOfDay();
        $end = $periodEnd->copy()->endOfDay();

        return [
            'meta' => [
                'compiled_at' => now()->toIso8601String(),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'period_days' => $start->diffInDays($end) + 1,
                'custom_notes' => $customNotes,
            ],
            'branding' => $this->compileBranding(),
            'site' => [
                'id' => $site->id,
                'domain' => $site->domain,
                'is_wordpress' => (bool) $site->is_wordpress,
                'wp_version' => $site->companion_snapshot['wp_core']['version'] ?? null,
                'php_version' => $site->companion_snapshot['environment']['php_version'] ?? null,
                'care_plan_enabled' => (bool) $site->care_plan_enabled,
            ],
            'updates' => $this->compileUpdates($site, $start, $end),
            'uptime' => $this->compileUptime($site, $start, $end),
            'security' => $this->compileSecurity($site, $start, $end),
            'performance' => $this->compilePerformance($site, $start, $end),
            'forms' => $this->compileForms($site, $start, $end),
            'traffic' => $this->compileTraffic($site, $start, $end),
            'backups' => $this->compileBackups($site, $start, $end),
        ];
    }

    protected function compileBranding(): array
    {
        return [
            'company_name' => $this->settings->get('companion.company_name') ?: config('app.name', 'Clockwork Control'),
            'support_url' => $this->settings->get('companion.support_url') ?: config('app.url'),
            'support_email' => $this->settings->get('companion.support_email') ?: config('mail.from.address'),
            'logo_url' => $this->settings->get('companion.logo_url'),
        ];
    }

    protected function compileUpdates(Site $site, Carbon $start, Carbon $end): array
    {
        $logs = ActionLog::query()
            ->where('site_id', $site->id)
            ->whereIn('action_type', ActionLog::UPDATE_TYPES)
            ->where('ok', true)
            ->whereBetween('ran_at', [$start, $end])
            ->orderByDesc('ran_at')
            ->get();

        $pluginUpdates = [];
        $themeUpdates = [];
        $coreUpdates = [];
        $translationUpdates = [];

        foreach ($logs as $log) {
            $item = [
                'target' => $log->target,
                'summary' => $log->summary,
                'date' => $log->ran_at?->toDateString(),
            ];

            match ($log->action_type) {
                ActionLog::TYPE_PLUGIN_UPDATE => $pluginUpdates[] = $item,
                ActionLog::TYPE_THEME_UPDATE => $themeUpdates[] = $item,
                ActionLog::TYPE_CORE_UPDATE => $coreUpdates[] = $item,
                ActionLog::TYPE_TRANSLATIONS_UPDATE => $translationUpdates[] = $item,
                default => null,
            };
        }

        return [
            'total' => $logs->count(),
            'plugins_count' => count($pluginUpdates),
            'themes_count' => count($themeUpdates),
            'core_count' => count($coreUpdates),
            'translations_count' => count($translationUpdates),
            'items' => [
                'plugins' => $pluginUpdates,
                'themes' => $themeUpdates,
                'core' => $coreUpdates,
            ],
        ];
    }

    protected function compileUptime(Site $site, Carbon $start, Carbon $end): array
    {
        $totalMinutes = max(1, $start->diffInMinutes($end));

        $downEvents = SiteUptimeEvent::query()
            ->where('site_id', $site->id)
            ->whereBetween('event_at', [$start, $end])
            ->where('event_type', 'down')
            ->orderBy('event_at')
            ->get();

        $totalDowntimeMinutes = 0;
        foreach ($downEvents as $event) {
            // If resolved, calculate duration; otherwise estimate 5 minutes per check outage
            $totalDowntimeMinutes += ($event->duration_seconds ?? 300) / 60;
        }

        $downtimeMinutes = min($totalMinutes, (int) round($totalDowntimeMinutes));
        $uptimePercentage = max(0.0, min(100.0, round(100 - (($downtimeMinutes / $totalMinutes) * 100), 2)));

        return [
            'monitored' => (bool) $site->uptime_monitoring_enabled,
            'uptime_percentage' => $uptimePercentage,
            'outages_count' => $downEvents->count(),
            'downtime_minutes' => $downtimeMinutes,
            'total_period_minutes' => $totalMinutes,
        ];
    }

    protected function compileSecurity(Site $site, Carbon $start, Carbon $end): array
    {
        $scans = SiteSecurityScan::query()
            ->where('site_id', $site->id)
            ->whereBetween('scanned_at', [$start, $end])
            ->orderByDesc('scanned_at')
            ->get();

        $cleanScans = $scans->where('status', 'clean')->count();
        $warnScans = $scans->where('status', 'warn')->count();
        $cveCount = 0;
        if (is_array($site->companion_snapshot['vulnerabilities'] ?? null)) {
            $cveCount = count($site->companion_snapshot['vulnerabilities']);
        }

        $blockedIpsCount = BlockedIp::query()
            ->where('site_id', $site->id)
            ->whereBetween('banned_at', [$start, $end])
            ->count();

        return [
            'total_scans' => $scans->count(),
            'clean_scans' => $cleanScans,
            'warn_scans' => $warnScans,
            'active_vulnerabilities' => $cveCount,
            'blocked_threats_count' => $blockedIpsCount,
            'checksum_status' => $site->latestChecksumScan?->status ?? 'ok',
        ];
    }

    protected function compilePerformance(Site $site, Carbon $start, Carbon $end): array
    {
        $latestMobile = SitePerformanceScan::query()
            ->where('site_id', $site->id)
            ->where('strategy', SitePerformanceScan::STRATEGY_MOBILE)
            ->where('status', SitePerformanceScan::STATUS_OK)
            ->orderByDesc('scanned_at')
            ->first();

        $latestDesktop = SitePerformanceScan::query()
            ->where('site_id', $site->id)
            ->where('strategy', SitePerformanceScan::STRATEGY_DESKTOP)
            ->where('status', SitePerformanceScan::STATUS_OK)
            ->orderByDesc('scanned_at')
            ->first();

        return [
            'has_performance' => $latestMobile !== null || $latestDesktop !== null,
            'desktop' => $latestDesktop ? [
                'score' => $latestDesktop->performance_score,
                'lcp_ms' => $latestDesktop->lcp_ms,
                'scanned_at' => $latestDesktop->scanned_at?->toDateString(),
            ] : null,
            'mobile' => $latestMobile ? [
                'score' => $latestMobile->performance_score,
                'lcp_ms' => $latestMobile->lcp_ms,
                'scanned_at' => $latestMobile->scanned_at?->toDateString(),
            ] : null,
        ];
    }

    protected function compileForms(Site $site, Carbon $start, Carbon $end): array
    {
        $runs = ContactFormTestRun::query()
            ->where('site_id', $site->id)
            ->whereBetween('ran_at', [$start, $end])
            ->get();

        $totalRuns = $runs->count();
        $passedRuns = $runs->where('status', 'ok')->count();
        $passRate = $totalRuns > 0 ? round(($passedRuns / $totalRuns) * 100, 1) : 100.0;

        return [
            'total_synthetic_tests' => $totalRuns,
            'successful_tests' => $passedRuns,
            'pass_rate' => $passRate,
        ];
    }

    protected function compileTraffic(Site $site, Carbon $start, Carbon $end): array
    {
        $traffic = SiteTrafficDaily::query()
            ->where('site_id', $site->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $visits = (int) $traffic->sum('visits');
        $requests = (int) $traffic->sum('requests');
        $bytes = (int) $traffic->sum('bytes_sent');

        return [
            'has_traffic' => $traffic->isNotEmpty(),
            'total_visits' => $visits,
            'total_requests' => $requests,
            'bandwidth_mb' => round($bytes / (1024 * 1024), 1),
        ];
    }

    protected function compileBackups(Site $site, Carbon $start, Carbon $end): array
    {
        // Estimate frequency based on care plan and backup relay settings
        $hasBackupRelay = (bool) ($site->backup_relay_enabled ?? false);
        $lastArchived = $site->backup_relay_last_archived_at;

        return [
            'enabled' => $hasBackupRelay || $site->care_plan_enabled,
            'last_backup_at' => $lastArchived?->toDateString(),
            'destination' => $hasBackupRelay ? 'Independent AWS S3 / Glacier' : 'Host Automated Snapshots',
        ];
    }
}
