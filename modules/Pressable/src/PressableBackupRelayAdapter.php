<?php

namespace Modules\Pressable;

use App\Models\Site;
use Carbon\Carbon;
use Modules\Core\Contracts\BackupRef;
use Modules\Core\Contracts\BackupRelayAdapter;
use Throwable;

class PressableBackupRelayAdapter implements BackupRelayAdapter
{
    public function __construct(
        protected PressableClient $client
    ) {}

    /**
     * Identify the newest backup reference for a site without downloading it.
     */
    public function latestBackupRef(Site $site): ?BackupRef
    {
        if (! $this->client->isConfigured() || ! $site->pressable_site_id) {
            return null;
        }

        try {
            $backups = $this->client->siteFilesystemBackups($site->pressable_site_id);
            if (empty($backups)) {
                $backups = $this->client->siteBackups($site->pressable_site_id);
            }
        } catch (Throwable) {
            return null;
        }

        if (empty($backups)) {
            return null;
        }

        $latest = $backups[0];
        $timestamp = $latest['backup_timestamp'] ?? now()->toIso8601String();
        $createdAt = Carbon::parse($timestamp);

        $sizeBytes = null;
        if (! empty($latest['title']) && preg_match('/([\d\.]+)\s*(MB|GB|KB|B)/i', $latest['title'], $matches)) {
            $val = (float) $matches[1];
            $unit = strtoupper($matches[2]);
            $sizeBytes = match ($unit) {
                'GB' => (int) ($val * 1024 * 1024 * 1024),
                'MB' => (int) ($val * 1024 * 1024),
                'KB' => (int) ($val * 1024),
                default => (int) $val,
            };
        }

        $id = (string) ($latest['id'] ?? $latest['backup_timestamp'] ?? $createdAt->format('YmdHis'));

        return new BackupRef(
            provider: Site::HOSTING_PROVIDER_PRESSABLE,
            externalId: $id,
            createdAt: $createdAt,
            sizeBytes: $sizeBytes,
            metadata: $latest,
        );
    }

    /**
     * Open a readable stream resource for the specified backup.
     *
     * @return resource|null
     */
    public function openBackupStream(Site $site, BackupRef $ref)
    {
        $downloadUrl = $ref->metadata['download_url'] ?? null;
        if ($downloadUrl) {
            $context = stream_context_create([
                'http' => [
                    'header' => 'Authorization: Bearer '.$this->client->getAccessToken(),
                    'follow_location' => 1,
                    'timeout' => 60,
                ],
            ]);
            $stream = @fopen($downloadUrl, 'r', false, $context);
            if ($stream) {
                return $stream;
            }
        }

        return fopen('php://temp', 'r+');
    }
}
