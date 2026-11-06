<?php

namespace Modules\BackupRelay\Services;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\BackupRef;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\DirectS3BackupRelayAdapter;
use RuntimeException;
use Throwable;

/**
 * Backup relay adapter for standalone / Companion-equipped WordPress sites.
 *
 * Direct-to-S3 Glacier IR (Option A):
 *   Clockwork mints a presigned S3 PUT URL for the GLACIER_IR destination key.
 *   Companion creates the database dump + wp-content archive inside WordPress
 *   and streams it directly to AWS S3, then deletes the temporary local
 *   staging file. Clockwork Control never buffers the archive.
 */
class CompanionBackupRelayAdapter implements BackupRelayAdapter, DirectS3BackupRelayAdapter
{
    /** Plugin capability that exposes POST /backup/create (the direct-to-S3 dump). */
    public const CAPABILITY_BACKUP_CREATE = 'backup-create';

    /**
     * @param  array<int, string>|null  $capabilities  as reported by the plugin's /health
     */
    public static function supportsBackupCreate(?array $capabilities): bool
    {
        return in_array(self::CAPABILITY_BACKUP_CREATE, $capabilities ?? [], true);
    }

    /**
     * Operator-facing reason a site's plugin cannot take a direct backup.
     */
    public static function missingBackupCreateMessage(Site $site): string
    {
        $plugin = $site->isRenegade() ? 'Renegade' : 'Companion';
        $version = trim((string) $site->companion_version);
        $label = $version !== '' ? "{$plugin} {$version}" : $plugin;

        return $site->isRenegade()
            ? "{$label} lacks the backup-create capability; update Renegade."
            : "{$label} lacks the backup-create capability; update Companion or switch to Renegade.";
    }

    /**
     * Why backup relay must not be turned on for this site, or null when it
     * can be. Only sites this adapter archives (the plugin uploads straight to
     * S3) need backup-create; host-streamed providers are never blocked here.
     */
    public static function enableBlockedReason(Site $site): ?string
    {
        try {
            $adapter = $site->host()->backupRelayAdapter();
        } catch (Throwable) {
            return null;
        }

        if (! $adapter instanceof self) {
            return null;
        }

        return self::supportsBackupCreate($site->companion_capabilities)
            ? null
            : self::missingBackupCreateMessage($site);
    }

    public function latestBackupRef(Site $site): ?BackupRef
    {
        if (! $site->companion_installed || ! $site->companion_secret) {
            return null;
        }

        $day = now()->startOfDay();

        return new BackupRef(
            provider: $site->hosting_provider,
            externalId: 'cw_companion_'.$day->format('Ymd'),
            createdAt: $day,
            sizeBytes: null,
            metadata: [
                'type' => 'companion_direct_s3',
            ],
        );
    }

    /**
     * @param  array{url: string, headers: array<string, string>}  $presigned
     * @return array{ok: bool, size_bytes?: int, sha256?: string, manifest?: array<string, mixed>|null, error?: string}
     */
    public function uploadDirectToS3(Site $site, string $destinationKey, array $presigned): array
    {
        // Fail loudly rather than skip: relay is on, so a backup was promised,
        // and the run must report this site as failed with a fix the operator
        // can act on (an older plugin has no /backup/create endpoint).
        if (! self::supportsBackupCreate($site->companion_capabilities)) {
            throw new RuntimeException("BackupRelay: {$site->domain}: ".self::missingBackupCreateMessage($site));
        }

        $client = new ClockworkCompanionClient($site, timeout: 300);

        try {
            $response = $client->createBackup([
                'upload_url' => $presigned['url'],
                'storage_class' => 'GLACIER_IR',
                'headers' => $presigned['headers'],
                'destination_key' => $destinationKey,
            ]);
        } catch (Throwable $e) {
            Log::error("CompanionBackupRelayAdapter: Failed to trigger backup for {$site->domain}: {$e->getMessage()}");

            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }

        if (! ($response['ok'] ?? false)) {
            $error = (string) ($response['error'] ?? 'Companion backup generation failed.');
            Log::error("CompanionBackupRelayAdapter: Backup error on {$site->domain}: {$error}");

            return [
                'ok' => false,
                'error' => $error,
            ];
        }

        return [
            'ok' => true,
            'size_bytes' => (int) ($response['size_bytes'] ?? 0),
            'sha256' => (string) ($response['sha256'] ?? ''),
            'manifest' => is_array($response['manifest'] ?? null) ? $response['manifest'] : null,
        ];
    }

    /**
     * @return resource|null
     */
    public function openBackupStream(Site $site, BackupRef $ref)
    {
        throw new RuntimeException(
            "Companion backups for {$site->domain} upload directly to S3; there is no host-side download stream."
        );
    }
}
