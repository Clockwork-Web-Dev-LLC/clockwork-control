<?php

namespace Modules\BackupRelay\Jobs;

use App\Models\Site;
use App\Support\Settings;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BackupRelay\Services\GlacierUploader;
use Modules\Core\Contracts\HostingProvider;
use RuntimeException;

class ArchiveSiteBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Site|int $site
    ) {}

    /**
     * Execute the archival job for the site.
     *
     * @return string 'archived' | 'skipped'
     */
    public function handle(GlacierUploader $uploader): string
    {
        $site = $this->site instanceof Site ? $this->site : Site::query()->findOrFail($this->site);

        $host = $site->host();
        if (! $host->supports(HostingProvider::CAP_BACKUP_RELAY)) {
            Log::info("BackupRelay: Provider {$host->id()} does not support CAP_BACKUP_RELAY for {$site->domain}.");

            return 'skipped';
        }

        $adapter = $host->backupRelayAdapter();
        if ($adapter === null) {
            Log::warning("BackupRelay: No backup relay adapter for {$host->id()} on {$site->domain}.");

            return 'skipped';
        }

        $ref = $adapter->latestBackupRef($site);
        if ($ref === null) {
            Log::info("BackupRelay: No backup found to archive for {$site->domain}.");

            return 'skipped';
        }

        // Frequency check: respect 1 a week (weekly), 2 a week (twice_weekly), or daily setting
        $settings = app(Settings::class);
        $frequency = (string) $settings->get('backup_relay.frequency', config('clockwork.backup_relay.frequency', 'weekly'));
        $minIntervalHours = match ($frequency) {
            'weekly' => 6 * 24,
            'twice_weekly' => 3 * 24,
            'daily' => 20,
            default => 6 * 24,
        };

        if ($site->backup_relay_last_archived_at !== null) {
            $hoursSinceLast = (int) now()->diffInHours($site->backup_relay_last_archived_at);
            if ($hoursSinceLast < $minIntervalHours) {
                Log::info("BackupRelay: Site {$site->domain} archived {$hoursSinceLast}h ago (frequency={$frequency}, min={$minIntervalHours}h), skipping.");

                return 'skipped';
            }
        }

        // Deduplication: check if already archived by timestamp or existing key in S3
        $cleanId = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $ref->externalId);
        $dateStr = $ref->createdAt->format('Y-m-d');
        $archivePrefix = rtrim((string) config('clockwork.backup_relay.archive_prefix', 'archives'), '/');
        $destinationKey = "{$archivePrefix}/{$site->domain}/{$dateStr}_{$cleanId}.archive";

        $disk = Storage::disk($uploader->diskName());
        if ($disk->exists($destinationKey)) {
            Log::info("BackupRelay: Backup {$destinationKey} already exists in S3 for {$site->domain}, skipping.");

            return 'skipped';
        }

        if ($site->backup_relay_last_archived_at !== null && $ref->createdAt->getTimestamp() <= $site->backup_relay_last_archived_at->getTimestamp()) {
            $refDateStr = $ref->createdAt->format(DateTimeInterface::ATOM);
            $lastArchivedStr = $site->backup_relay_last_archived_at->toIso8601String();
            Log::info("BackupRelay: Backup from {$refDateStr} is not newer than last archived ({$lastArchivedStr}) for {$site->domain}, skipping.");

            return 'skipped';
        }

        $stream = $adapter->openBackupStream($site, $ref);
        if ($stream === null) {
            throw new RuntimeException("BackupRelay: Failed to open backup stream for {$site->domain} ({$ref->externalId}).");
        }

        try {
            $uploader->uploadStream($stream, $destinationKey, [
                'Metadata' => [
                    'site_id' => (string) $site->id,
                    'domain' => (string) $site->domain,
                    'provider' => (string) $ref->provider,
                    'external_id' => (string) $ref->externalId,
                    'created_at' => $ref->createdAt->format(DateTimeInterface::ATOM),
                ],
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $site->update([
            'backup_relay_last_archived_at' => now(),
        ]);

        Log::info("BackupRelay: Successfully archived {$site->domain} ({$ref->externalId}) to {$destinationKey}.");

        return 'archived';
    }
}
