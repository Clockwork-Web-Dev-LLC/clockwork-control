<?php

namespace Modules\BackupRelay\Jobs;

use App\Models\Site;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BackupRelay\Services\GlacierUploader;
use Modules\Core\Contracts\BackupRef;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\DirectS3BackupRelayAdapter;
use Modules\Core\Contracts\HostingProvider;
use RuntimeException;
use Throwable;

class ArchiveSiteBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 360;

    public int $tries = 1;

    public function __construct(
        public Site|int $site,
        public bool $force = false,
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

        $frequency = $site->backupRelayFrequency();

        if (! $this->force && $site->backup_relay_last_archived_at !== null) {
            if ($frequency === 'daily') {
                if ($site->backup_relay_last_archived_at->isSameDay(now())) {
                    Log::info("BackupRelay: Site {$site->domain} already archived today, skipping.");

                    return 'skipped';
                }
            } else {
                $minIntervalHours = $site->backupRelayMinIntervalHours();
                $hoursSinceLast = (int) now()->diffInHours($site->backup_relay_last_archived_at);
                if ($hoursSinceLast < $minIntervalHours) {
                    Log::info("BackupRelay: Site {$site->domain} archived {$hoursSinceLast}h ago (frequency={$frequency}, min={$minIntervalHours}h), skipping.");

                    return 'skipped';
                }
            }
        }

        $destinationKey = $this->destinationKey($adapter, $site, $ref);

        $disk = Storage::disk($uploader->diskName());
        if ($this->objectExists($disk, $destinationKey)) {
            // Direct-to-S3: a leftover object from a timed-out HMAC request is
            // success — mark archived so retries do not mint a second key.
            // Host-stream adapters keep the previous skip (already uploaded).
            if ($adapter instanceof DirectS3BackupRelayAdapter) {
                $this->markArchived($site, [
                    'size_bytes' => $this->objectSize($disk, $destinationKey),
                ]);
                Log::info("BackupRelay: {$destinationKey} already in S3 for {$site->domain}; treating as archived.");

                return 'archived';
            }

            Log::info("BackupRelay: Backup {$destinationKey} already exists in S3 for {$site->domain}, skipping.");

            return 'skipped';
        }

        if (
            ! $this->force
            && $site->backup_relay_last_archived_at !== null
            && $ref->createdAt->getTimestamp() <= $site->backup_relay_last_archived_at->getTimestamp()
        ) {
            $refDateStr = $ref->createdAt->format(DateTimeInterface::ATOM);
            $lastArchivedStr = $site->backup_relay_last_archived_at->toIso8601String();
            Log::info("BackupRelay: Backup from {$refDateStr} is not newer than last archived ({$lastArchivedStr}) for {$site->domain}, skipping.");

            return 'skipped';
        }

        if ($adapter instanceof DirectS3BackupRelayAdapter) {
            $presigned = $uploader->presignedUploadUrl($destinationKey, ttlMinutes: 120);
            $directResult = $adapter->uploadDirectToS3($site, $destinationKey, $presigned);
            if (! ($directResult['ok'] ?? false)) {
                // Timed-out request may still have completed the PUT. If the
                // object is there, treat as success rather than minting a new key.
                if ($this->objectExists($disk, $destinationKey)) {
                    $this->markArchived($site, [
                        'size_bytes' => $this->objectSize($disk, $destinationKey),
                        'sha256' => $directResult['sha256'] ?? null,
                    ]);
                    Log::warning("BackupRelay: Companion reported failure for {$site->domain} but {$destinationKey} exists; treating as archived.");

                    return 'archived';
                }

                $errorMsg = $directResult['error'] ?? 'Direct S3 upload failed';
                throw new RuntimeException("BackupRelay: Failed direct backup for {$site->domain}: {$errorMsg}");
            }

            $this->markArchived($site, [
                'size_bytes' => $directResult['size_bytes'] ?? null,
                'sha256' => $directResult['sha256'] ?? null,
            ]);

            if (! empty($directResult['sha256'])) {
                $this->writeSha256Sidecar($disk, $destinationKey, (string) $directResult['sha256'], (int) ($directResult['size_bytes'] ?? 0));
            }
        } else {
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

            $this->markArchived($site, [
                'size_bytes' => $this->objectSize($disk, $destinationKey),
            ]);
        }

        Log::info("BackupRelay: Successfully archived {$site->domain} ({$ref->externalId}) to {$destinationKey}.");

        return 'archived';
    }

    private function destinationKey(BackupRelayAdapter $adapter, Site $site, BackupRef $ref): string
    {
        $archivePrefix = rtrim((string) config('clockwork.backup_relay.archive_prefix', 'archives'), '/');
        $dateStr = $ref->createdAt->format('Y-m-d');

        if ($adapter instanceof DirectS3BackupRelayAdapter) {
            if ($this->force) {
                return "{$archivePrefix}/{$site->domain}/".now()->format('Y-m-d_H-i-s').'.zip';
            }

            return "{$archivePrefix}/{$site->domain}/".now()->format('Y-m-d').'.zip';
        }

        $cleanId = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $ref->externalId);
        if ($this->force) {
            return "{$archivePrefix}/{$site->domain}/{$dateStr}_{$cleanId}_".now()->format('His').'.archive';
        }

        return "{$archivePrefix}/{$site->domain}/{$dateStr}_{$cleanId}.archive";
    }

    /**
     * @param  array{size_bytes?: int|null, sha256?: string|null}  $meta
     */
    private function markArchived(Site $site, array $meta = []): void
    {
        $updates = [
            'backup_relay_last_archived_at' => now(),
        ];

        if (array_key_exists('size_bytes', $meta) && $meta['size_bytes'] !== null) {
            $updates['backup_relay_last_size_bytes'] = (int) $meta['size_bytes'];
        }

        $sha = isset($meta['sha256']) ? trim((string) $meta['sha256']) : '';
        if ($sha !== '') {
            $updates['backup_relay_last_sha256'] = $sha;
        }

        $site->update($updates);
    }

    /**
     * S3 object presence can change between calls (Companion PUT during this
     * request, leftover from a timed-out HMAC). Not a pure lookup.
     *
     * @phpstan-impure
     */
    private function objectExists(mixed $disk, string $destinationKey): bool
    {
        try {
            return $disk->exists($destinationKey);
        } catch (Throwable) {
            return false;
        }
    }

    private function objectSize(mixed $disk, string $destinationKey): ?int
    {
        try {
            return $disk->size($destinationKey);
        } catch (Throwable) {
            return null;
        }
    }

    private function writeSha256Sidecar(mixed $disk, string $destinationKey, string $sha256, int $sizeBytes): void
    {
        try {
            $sidecarKey = "{$destinationKey}.sha256.json";
            $payload = json_encode([
                'sha256' => $sha256,
                'size_bytes' => $sizeBytes,
                'created_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            $disk->put($sidecarKey, $payload);
        } catch (Throwable $e) {
            Log::warning("BackupRelay: Failed writing sha256 sidecar for {$destinationKey}: {$e->getMessage()}");
        }
    }
}
