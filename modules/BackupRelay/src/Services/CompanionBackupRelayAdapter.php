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
     * @return array{ok: bool, size_bytes?: int, sha256?: string, error?: string}
     */
    public function uploadDirectToS3(Site $site, string $destinationKey, array $presigned): array
    {
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
