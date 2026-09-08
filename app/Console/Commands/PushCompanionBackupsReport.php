<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Backups\BackupHistoryRetention;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\DigitalOcean\SpacesClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Modules\SpinupWp\SpinupWpClient;
use Throwable;

/**
 * Pulls per-site backup config from SpinupWP and POSTs it to each Companion-equipped
 * site's /backups-report endpoint, populating the Backups admin page that clients see.
 *
 * Why a push (not a pull): clients can only reach their own WP site, not Clockwork.
 * The data has to flow into the WP database so they can render it in wp-admin.
 *
 * SpinupWP does not currently expose backup history — only configuration. We push
 * what's available; the page surfaces this constraint to users so they don't expect
 * a list of past runs.
 */
class PushCompanionBackupsReport extends Command
{
    protected $signature = 'clockwork:push-companion-backups
        {--site= : Limit to a single site (id or domain)}
        {--server= : Limit to sites on one server (id, name, or hostname)}';

    protected $description = 'Push SpinupWP backup configuration to each Companion-equipped site for client visibility.';

    public function handle(SpinupWpClient $spinup, SpacesClient $spaces): int
    {
        if (! $spinup->isConfigured()) {
            $this->error('SpinupWP token not configured (CLOCKWORK_SPINUPWP_TOKEN).');

            return self::FAILURE;
        }

        $spacesEnabled = $spaces->isConfigured();
        if (! $spacesEnabled) {
            $this->warn('CLOCKWORK_DO_SPACES_KEY not set — pushing config only (no run history).');
        }

        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->warn('No Companion-equipped sites with a spinupwp_id matched.');

            return self::SUCCESS;
        }

        $stats = ['ok' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($sites as $site) {
            $caps = $site->companion_capabilities ?? [];
            if (! in_array('backups-report', $caps, true)) {
                $stats['skipped']++;
                $this->line("  [skip] {$site->domain} — Companion v1.3.0+ not installed");

                continue;
            }

            try {
                $config = $spinup->siteBackupConfig($site->spinupwp_id);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.backups_report.spinupwp_fetch_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: SpinupWP fetch failed — {$e->getMessage()}");

                continue;
            }

            $history = [];
            $schedules = [];
            if ($spacesEnabled) {
                try {
                    $objects = $spaces->listSiteBackupObjects($site);
                    $history = $spaces->toHistoryRows($objects);
                    $schedules = $spaces->inferSchedules($history);
                } catch (Throwable $e) {
                    Log::warning('companion.backups_report.spaces_fetch_failed', [
                        'site' => $site->domain,
                        'error' => $e->getMessage(),
                    ]);
                    $this->warn("  [warn] {$site->domain}: Spaces fetch failed — {$e->getMessage()} (pushing config only)");
                }
            }

            // Care-plan-aware retention window: 90 days included with the
            // care plan, 30 days for everyone else. Filter before pushing so
            // off-plan sites don't ever have the longer history sitting in
            // their wp_options table — keeps the "you only get 30 days unless
            // you're on a care plan" promise honest at rest, not just in UI.
            $onCarePlan = (bool) $site->care_plan_enabled;
            $retentionDays = $onCarePlan ? 90 : 30;
            $history = BackupHistoryRetention::filter($history, $retentionDays);

            $report = [
                'source' => 'spinupwp+spaces',
                'fetched_at' => now()->toIso8601String(),
                'config' => $config,
                'schedules' => $schedules,
                'history' => $history,
                'care_plan_enabled' => $onCarePlan,
                'retention_days' => $retentionDays,
                'offsite_archive' => $this->offsiteArchiveFor($site),
            ];

            try {
                (new ClockworkCompanionClient($site))->pushBackupsReport($report);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.backups_report.push_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: push failed — {$e->getMessage()}");

                continue;
            }

            $stats['ok']++;
            $files = ! empty($config['files']) ? 'files' : '—';
            $db = ! empty($config['database']) ? 'db' : '—';
            $next = $config['next_run_time'] ?? 'never';
            $runs = count($history);
            $sched = count($schedules);
            $this->line("  [ok]   {$site->domain} — {$files}+{$db}, next run {$next}, history runs={$runs}, schedules detected={$sched}");
        }

        $msg = sprintf(
            'companion.backups_report.push complete: ok=%d failed=%d skipped=%d',
            $stats['ok'],
            $stats['failed'],
            $stats['skipped'],
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * @return ?array{active: bool, last_archived_at: ?string, fs_download_url: ?string, db_download_url: ?string, download_expires_at: ?string}
     */
    private function offsiteArchiveFor(Site $site): ?array
    {
        if (! $site->backup_relay_enabled) {
            return null;
        }

        try {
            $enumerator = app(BackupArchiveEnumerator::class);

            // This payload is pushed to the Companion plugin's client-facing
            // wp-admin backups page — a link only works there if it's a real
            // presigned S3 URL. Without one, getDownloadUrl() falls back to
            // an operator-authenticated Clockwork Control route, which would
            // just bounce a client to our login screen. Skip enrichment
            // entirely rather than hand a client a dead-end link.
            if (! $enumerator->supportsPresignedUrls()) {
                return null;
            }

            $siteData = $enumerator->forSite($site);
            if (! empty($siteData['archives'])) {
                $fsUrl = null;
                $dbUrl = null;
                foreach ($siteData['archives'] as $arch) {
                    if ($arch['type'] === 'fs' && ! $fsUrl) {
                        $fsUrl = $arch['download_url'];
                    } elseif ($arch['type'] === 'db' && ! $dbUrl) {
                        $dbUrl = $arch['download_url'];
                    } elseif (($arch['type'] === 'full' || $arch['type'] === 'archive') && ! $fsUrl) {
                        $fsUrl = $arch['download_url'];
                    }
                }

                return [
                    'active' => true,
                    'last_archived_at' => $siteData['last_archived_at'],
                    'fs_download_url' => $fsUrl,
                    'db_download_url' => $dbUrl,
                    'download_expires_at' => now()->addHours(BackupArchiveEnumerator::DOWNLOAD_URL_TTL_HOURS)->toIso8601String(),
                ];
            }
        } catch (Throwable $e) {
            Log::debug("PushCompanionBackupsReport: Failed to enumerate archives for {$site->domain}: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret')
            ->whereNotNull('spinupwp_id')
            ->whereHas('server', fn ($qq) => $qq->monitored());

        if ($needle = $this->option('site')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', $needle)->orWhere('domain', $needle);
            });
        }

        if ($needle = $this->option('server')) {
            $q->whereHas('server', function ($q) use ($needle) {
                $q->where('id', $needle)
                    ->orWhere('name', $needle)
                    ->orWhere('hostname', $needle);
            });
        }

        return $q->orderBy('domain')->get();
    }
}
