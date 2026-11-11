<?php

namespace Modules\Pressable;

use App\Models\Site;
use App\Support\SsrfGuard;
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
        if (! is_string($downloadUrl) || ! str_starts_with(strtolower($downloadUrl), 'https://')) {
            return null;
        }

        SsrfGuard::assertPublic($downloadUrl);

        // Do not auto-follow redirects with the Pressable Bearer token attached:
        // Pressable backup endpoints redirect to presigned S3/CDN URLs where
        // forwarding `Authorization: Bearer` leaks the API token cross-origin
        // and breaks S3 SigV4 query-authenticated requests.
        $currentUrl = $downloadUrl;
        $sendAuth = true;

        for ($hop = 0; $hop <= 5; $hop++) {
            $headers = ['User-Agent: ClockworkControl-BackupRelay/1.0'];
            if ($sendAuth) {
                $headers[] = 'Authorization: Bearer '.$this->client->getAccessToken();
            }

            $context = stream_context_create([
                'http' => [
                    'header' => implode("\r\n", $headers),
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'timeout' => 60,
                ],
            ]);

            $http_response_header = [];
            $stream = @fopen($currentUrl, 'r', false, $context);
            if ($stream === false) {
                return null;
            }

            $statusLine = $http_response_header[0] ?? '';
            $statusCode = preg_match('#^HTTP/\S+\s+(\d{3})#i', $statusLine, $m) ? (int) $m[1] : 0;

            if ($statusCode >= 200 && $statusCode < 300) {
                return $stream;
            }

            fclose($stream);

            if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                $location = null;
                foreach ($http_response_header as $hdr) {
                    if (stripos($hdr, 'Location:') === 0) {
                        $location = trim(substr($hdr, strlen('Location:')));
                        break;
                    }
                }

                if ($location === null || ! str_starts_with(strtolower($location), 'https://')) {
                    return null;
                }

                SsrfGuard::assertPublic($location);
                $sameHost = strtolower((string) parse_url($location, PHP_URL_HOST)) === strtolower((string) parse_url($downloadUrl, PHP_URL_HOST));
                $sendAuth = $sameHost;
                $currentUrl = $location;

                continue;
            }

            return null;
        }

        return null;
    }
}
