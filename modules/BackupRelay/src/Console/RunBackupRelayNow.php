<?php

namespace Modules\BackupRelay\Console;

use App\Models\BackupRelayRun;
use App\Models\Site;
use App\Support\Settings;
use Illuminate\Console\Command;
use Modules\BackupRelay\Jobs\ArchiveSiteBackupJob;
use Modules\BackupRelay\Services\GlacierUploader;
use Modules\Core\Contracts\HostingProvider;
use Throwable;

class RunBackupRelayNow extends Command
{
    protected $signature = 'clockwork:backup-relay-run
        {--site= : Limit to a single site (id or domain)}
        {--force : Ignore cadence / last-archive skips (Backup Now)}
        {--queue : Queue the backup jobs asynchronously}';

    protected $description = 'Run in-repo backup relay archiving for enabled sites to S3 Glacier.';

    public function handle(GlacierUploader $uploader, Settings $settings): int
    {
        $startedAt = now();

        $query = Site::query()->backupRelayEnabled();

        if ($needle = $this->option('site')) {
            $query->where(function ($q) use ($needle) {
                $q->where('id', $needle)->orWhere('domain', $needle);
            });
        }

        $allSites = $query->orderBy('domain')->get();

        // Filter to providers supporting CAP_BACKUP_RELAY
        $sites = $allSites->filter(function (Site $site) {
            try {
                return $site->host()->supports(HostingProvider::CAP_BACKUP_RELAY);
            } catch (Throwable) {
                return false;
            }
        });

        if ($sites->isEmpty()) {
            $this->warn('No eligible backup-relay-enabled sites matched.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        if ($this->option('queue')) {
            foreach ($sites as $site) {
                ArchiveSiteBackupJob::dispatch($site, $force);
                $this->line("  [queued] {$site->domain}");
            }
            $this->info("Dispatched {$sites->count()} backup relay job(s) to the queue.");

            return self::SUCCESS;
        }

        $sitesTotal = $sites->count();
        $sitesArchived = 0;
        $sitesSkipped = 0;
        $sitesFailed = 0;
        $failures = [];

        foreach ($sites as $site) {
            try {
                $job = new ArchiveSiteBackupJob($site, $force);
                $result = $job->handle($uploader);

                if ($result === 'archived') {
                    $sitesArchived++;
                    $this->info("  [ok]   {$site->domain} — archived");
                } else {
                    $sitesSkipped++;
                    $this->line("  [skip] {$site->domain} — already up to date or nothing to archive");
                }
            } catch (Throwable $e) {
                $sitesFailed++;
                $failures[] = [
                    'domain' => $site->domain,
                    'error' => $e->getMessage(),
                ];
                $this->error("  [fail] {$site->domain} — {$e->getMessage()}");
            }
        }

        $finishedAt = now();

        $run = BackupRelayRun::query()->create([
            'sites_total' => $sitesTotal,
            'sites_archived' => $sitesArchived,
            'sites_skipped' => $sitesSkipped,
            'sites_failed' => $sitesFailed,
            'failures' => $failures,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        $settings->put('backup_relay.last_run_at', $finishedAt->toIso8601String());
        $settings->put('backup_relay.last_processed_finished_at', $finishedAt->toIso8601String());
        $settings->put('backup_relay.last_run_stats', json_encode([
            'sites_total' => $run->sites_total,
            'sites_archived' => $run->sites_archived,
            'sites_skipped' => $run->sites_skipped,
            'sites_failed' => $run->sites_failed,
            'failures' => $run->failures,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ]));

        $this->info("Backup relay complete: archived={$sitesArchived} skipped={$sitesSkipped} failed={$sitesFailed}.");

        return $sitesFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
