<?php

namespace Modules\BackupRelay\Console;

use App\Console\Commands\BackupRestoreCommand;
use App\Models\BackupRelayRun;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\BackupRelay\Jobs\ArchiveSiteBackupJob;
use Modules\BackupRelay\Services\GlacierUploader;
use Modules\Core\Contracts\DirectS3BackupRelayAdapter;
use Modules\Core\Contracts\HostingProvider;
use Throwable;

class RunBackupRelayNow extends Command
{
    protected $signature = 'clockwork:backup-relay-run
        {--site= : Limit to a single site (id or domain)}
        {--force : Ignore cadence / last-archive skips (Backup Now)}
        {--queue : Queue the backup jobs asynchronously}
        {--direct-only : Only sites that upload straight to S3 from Companion (the external agent cannot back these up)}';

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

        $directOnly = (bool) $this->option('direct-only');

        // Filter to providers supporting CAP_BACKUP_RELAY
        $sites = $allSites->filter(function (Site $site) use ($directOnly) {
            try {
                $host = $site->host();

                return $host->supports(HostingProvider::CAP_BACKUP_RELAY)
                    && (! $directOnly || $host->backupRelayAdapter() instanceof DirectS3BackupRelayAdapter);
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

        // A sweep (no --site) shares each site's Backup Now lock, so it never
        // backs up a site mid-restore or alongside a Backup Now already running,
        // and a restore can't be staged while the sweep is on that site.
        $isSweep = ! $this->option('site');

        foreach ($sites as $site) {
            $lockKey = 'backup_relay.run.site.'.$site->id;
            $locked = false;
            try {
                if ($isSweep) {
                    if ($this->restoreInFlight($site)) {
                        $sitesSkipped++;
                        $this->line("  [skip] {$site->domain} — a restore is in progress");

                        continue;
                    }
                    if (! ($locked = Cache::add($lockKey, now()->toIso8601String(), 3600))) {
                        $sitesSkipped++;
                        $this->line("  [skip] {$site->domain} — a backup is already running");

                        continue;
                    }
                }

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
                Log::error('backup_relay.site_failed', ['site_id' => $site->id, 'direct_only' => $directOnly, 'error' => $e->getMessage()]);
            } finally {
                if ($locked) {
                    Cache::forget($lockKey);
                }
            }
        }

        $finishedAt = now();

        // In external_agent mode the run history and last-run settings belong
        // to the droplet (they drive the relay dashboard and its stale alert),
        // so the direct-only pass leaves them alone. Each site still records
        // its own backup_relay_last_archived_at.
        if ($directOnly) {
            $this->info("Direct backups complete: archived={$sitesArchived} skipped={$sitesSkipped} failed={$sitesFailed}.");
            if ($failures !== []) {
                // No run history covers this pass, so say so in chat.
                app(ChatNotifier::class)->send(
                    'Direct (Companion → S3) backups failed for '.count($failures).' site(s): '
                    .implode('; ', array_map(fn (array $f) => $f['domain'].' — '.mb_substr($f['error'], 0, 200), array_slice($failures, 0, 10)))
                );
            }

            return $sitesFailed > 0 ? self::FAILURE : self::SUCCESS;
        }

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

    protected function restoreInFlight(Site $site): bool
    {
        $state = Cache::get("backup_restore.site.{$site->id}.state");

        return Cache::has('backup_restore.site.'.$site->id)
            || in_array(is_array($state) ? ($state['status'] ?? '') : '', BackupRestoreCommand::IN_FLIGHT_STATUSES, true);
    }
}
