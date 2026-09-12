<?php

namespace Modules\BackupRelay\Services;

use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BackupArchiveEnumerator
{
    /** Presigned download URL lifetime used by getDownloadUrl(). */
    public const DOWNLOAD_URL_TTL_HOURS = 24;

    public function __construct(
        protected ?string $diskName = null
    ) {
        $this->diskName = $diskName ?? config('clockwork.backup_relay.disk', 's3-backup-relay');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName);
    }

    public function diskName(): string
    {
        return $this->diskName;
    }

    /**
     * Whether this disk can mint a real, directly-downloadable presigned URL.
     * getDownloadUrl() falls back to an operator-authenticated Clockwork
     * Control route when this isn't the case — fine for the internal
     * /settings/backup-relay UI (the operator is already logged in), but
     * useless if handed to a client-facing surface like the Companion
     * wp-admin page, which has no Clockwork Control session to satisfy
     * that route's auth middleware.
     *
     * Checking the configured driver rather than
     * method_exists($disk, 'temporaryUrl') deliberately — Laravel's base
     * FilesystemAdapter defines temporaryUrl() for every driver (including
     * 'local'), only throwing at call time if the underlying adapter
     * doesn't really support it, so a method_exists() check is always true
     * regardless of the driver and would never actually gate anything.
     */
    public function supportsPresignedUrls(): bool
    {
        return config("filesystems.disks.{$this->diskName}.driver") === 's3';
    }

    /**
     * Enumerate all off-site S3 Glacier archives for a site.
     *
     * @return array{
     *     configured: bool,
     *     disk: string,
     *     domain: string,
     *     archives: array<int, array<string, mixed>>,
     *     total_count: int,
     *     total_bytes: int,
     *     total_size_formatted: string,
     *     last_archived_at: ?string,
     *     last_archived_at_formatted: ?string,
     *     last_archived_at_diff: ?string,
     *     manifest_links: ?array<string, mixed>
     * }
     */
    public function forSite(Site $site): array
    {
        $domain = $site->domain;
        $archives = [];
        $totalBytes = 0;

        try {
            $disk = $this->disk();
        } catch (Throwable $e) {
            Log::warning("BackupArchiveEnumerator: Failed to get disk {$this->diskName}: {$e->getMessage()}");

            return $this->emptyPayload($domain, false);
        }

        $archivePrefix = rtrim((string) config('clockwork.backup_relay.archive_prefix', 'archives'), '/');

        // Search prefixes across both in-repo mode and external agent mode:
        // 1. archives/{domain}
        // 2. {domain}
        $searchPrefixes = [
            "{$archivePrefix}/{$domain}",
            $domain,
        ];

        $seenKeys = [];

        $manifestLinks = $this->loadManifestLinks($domain);

        foreach ($searchPrefixes as $prefix) {
            try {
                $items = $disk->listContents($prefix, true);
            } catch (Throwable $e) {
                Log::warning("BackupArchiveEnumerator: Failed to list contents under {$prefix}: {$e->getMessage()}");
                $items = [];
            }

            foreach ($items as $item) {
                if (! method_exists($item, 'isFile') || ! $item->isFile()) {
                    continue;
                }

                $file = $item->path();
                if (isset($seenKeys[$file])) {
                    continue;
                }
                $seenKeys[$file] = true;

                $filename = basename($file);
                // Skip hidden files, system files, or control json manifests
                if (str_starts_with($filename, '.') || str_ends_with($filename, '.json')) {
                    continue;
                }

                // Deliberately NOT falling back to $disk->size()/$disk->lastModified()
                // here — those trigger a HeadObject call, which needs s3:GetObject.
                // The whole point of using listContents() is that ListObjectsV2
                // already returns Size/LastModified for every object using only
                // s3:ListBucket; a HeadObject fallback would just re-fail with the
                // same AccessDenied this method exists to avoid.
                $size = method_exists($item, 'fileSize') ? (int) ($item->fileSize() ?? 0) : 0;
                $mtime = method_exists($item, 'lastModified') ? $item->lastModified() : null;
                $lastModified = $mtime ? Carbon::createFromTimestamp($mtime) : null;

                $totalBytes += $size;

                $typeInfo = $this->detectType($file);
                $archivedAt = $this->detectArchivedAt($file, $lastModified);

                // If external droplet manifest provided a verified presigned download URL for this file, use it
                $downloadUrl = null;
                if ($manifestLinks) {
                    $cleanFile = '/'.ltrim($file, '/');
                    if (! empty($manifestLinks['fs_download_url']) && str_contains($manifestLinks['fs_download_url'], $cleanFile)) {
                        $downloadUrl = $manifestLinks['fs_download_url'];
                    } elseif (! empty($manifestLinks['db_download_url']) && str_contains($manifestLinks['db_download_url'], $cleanFile)) {
                        $downloadUrl = $manifestLinks['db_download_url'];
                    }
                }

                if (! $downloadUrl) {
                    $downloadUrl = $this->getDownloadUrl($site, $file);
                }

                $archives[] = [
                    'id' => md5($file),
                    'key' => $file,
                    'filename' => $filename,
                    'type' => $typeInfo['type'],
                    'type_label' => $typeInfo['label'],
                    'type_icon' => $typeInfo['icon'],
                    'size_bytes' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'storage_class' => 'GLACIER_IR',
                    'archived_at' => $archivedAt?->toIso8601String(),
                    'archived_at_formatted' => $archivedAt ? $archivedAt->format('M j, Y H:i \U\T\C') : null,
                    'archived_at_diff' => $archivedAt?->diffForHumans(),
                    'download_url' => $downloadUrl,
                ];
            }
        }

        // Sort newest first
        usort($archives, function ($a, $b) {
            $timeA = isset($a['archived_at']) ? strtotime($a['archived_at']) : 0;
            $timeB = isset($b['archived_at']) ? strtotime($b['archived_at']) : 0;

            return $timeB <=> $timeA;
        });

        $lastArchivedAt = $archives[0]['archived_at'] ?? ($manifestLinks['last_archived_at'] ?? null);

        return [
            'configured' => true,
            'disk' => $this->diskName,
            'domain' => $domain,
            'archives' => $archives,
            'total_count' => count($archives),
            'total_bytes' => $totalBytes,
            'total_size_formatted' => $this->formatBytes($totalBytes),
            'last_archived_at' => $lastArchivedAt,
            'last_archived_at_formatted' => $lastArchivedAt ? Carbon::parse($lastArchivedAt)->format('M j, Y H:i \U\T\C') : null,
            'last_archived_at_diff' => $lastArchivedAt ? Carbon::parse($lastArchivedAt)->diffForHumans() : null,
            'manifest_links' => $manifestLinks,
        ];
    }

    /**
     * Generate a presigned S3 URL or fallback download route for an object.
     */
    public function getDownloadUrl(Site $site, string $key): string
    {
        try {
            $disk = $this->disk();
            if (method_exists($disk, 'temporaryUrl')) {
                return $disk->temporaryUrl($key, now()->addHours(self::DOWNLOAD_URL_TTL_HOURS));
            }
        } catch (Throwable) {
            // Fall back to direct controller download proxy
        }

        return route('settings.backup-relay.download', [
            'site' => $site->id,
            'key' => base64_encode($key),
        ]);
    }

    /**
     * Resolve the expected SHA-256 hash for a given archive key.
     * Order of resolution per Decision D1:
     * 1. Read the {key}.sha256.json sidecar object from S3.
     * 2. If {key} matches the site's newest archive and backup_relay_last_sha256 is set, use it.
     * 3. Return null (no hash on record -> refuse restore).
     */
    public function resolveArchiveSha256(Site $site, string $key): ?string
    {
        $disk = $this->disk();
        $sidecarKey = "{$key}.sha256.json";

        try {
            if ($disk->exists($sidecarKey)) {
                $raw = (string) $disk->get($sidecarKey);
                $data = json_decode($raw, true);
                if (is_array($data) && ! empty($data['sha256'])) {
                    return trim((string) $data['sha256']);
                }
            }
        } catch (Throwable $e) {
            Log::debug("BackupRelay: Sidecar lookup failed for {$sidecarKey}: {$e->getMessage()}");
        }

        if (! empty($site->backup_relay_last_sha256)) {
            $summary = $this->forSite($site);
            $archives = $summary['archives'] ?? [];
            if (! empty($archives) && ($archives[0]['key'] ?? '') === $key) {
                return trim((string) $site->backup_relay_last_sha256);
            }
        }

        return null;
    }

    /**
     * Load presigned download links from the external-agent manifest in S3 if available.
     *
     * @return ?array{fs_download_url: ?string, db_download_url: ?string, last_archived_at: ?string, expires_at: ?string}
     */
    public function loadManifestLinks(string $domain): ?array
    {
        try {
            $disk = $this->disk();
            $controlPrefix = rtrim((string) config('clockwork.backup_relay.s3_prefix', '_control/backup-relay'), '/');
            $manifestKey = "{$controlPrefix}/download-links.json";

            if ($disk->exists($manifestKey)) {
                $manifest = json_decode((string) $disk->get($manifestKey), true, flags: JSON_THROW_ON_ERROR);
                if (isset($manifest['sites'][$domain])) {
                    $siteLinks = $manifest['sites'][$domain];

                    return [
                        'fs_download_url' => $siteLinks['fs_download_url'] ?? null,
                        'db_download_url' => $siteLinks['db_download_url'] ?? null,
                        'last_archived_at' => $manifest['generated_at'] ?? null,
                        'expires_at' => $manifest['expires_at'] ?? null,
                    ];
                }
            }
        } catch (Throwable) {
            // Best-effort manifest read
        }

        return null;
    }

    /**
     * Detect backup component type from filename and path.
     *
     * @return array{type: string, label: string, icon: string}
     */
    protected function detectType(string $path): array
    {
        $lower = strtolower($path);

        if (str_contains($lower, '/db/') || str_contains($lower, '_db') || str_ends_with($lower, '.sql') || str_ends_with($lower, '.sql.gz')) {
            return [
                'type' => 'db',
                'label' => 'Database',
                'icon' => 'fa-solid fa-database',
            ];
        }

        if (str_contains($lower, '/fs/') || str_contains($lower, '_fs') || str_ends_with($lower, '.bz2') || str_ends_with($lower, 'files.tar.gz')) {
            return [
                'type' => 'fs',
                'label' => 'Filesystem',
                'icon' => 'fa-solid fa-folder-tree',
            ];
        }

        return [
            'type' => 'full',
            'label' => 'Full Snapshot',
            'icon' => 'fa-solid fa-box-archive',
        ];
    }

    /**
     * Detect the archive timestamp from filename or fallback to mtime.
     */
    protected function detectArchivedAt(string $path, ?Carbon $fallback): ?Carbon
    {
        $filename = basename($path);

        // Pattern 1: YYYY-MM-DD-HH-MM-SS or YYYY-MM-DD_HH-MM-SS
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})[-_](\d{2})[-_](\d{2})[-_](\d{2})/', $filename, $m)) {
            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]);
        }

        // Pattern 2: YYYY-MM-DD
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $filename, $m)) {
            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0);
        }

        return $fallback;
    }

    /**
     * Format byte integer into human-readable representation.
     */
    public function formatBytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = (int) min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPayload(string $domain, bool $configured): array
    {
        return [
            'configured' => $configured,
            'disk' => $this->diskName,
            'domain' => $domain,
            'archives' => [],
            'total_count' => 0,
            'total_bytes' => 0,
            'total_size_formatted' => '0 B',
            'last_archived_at' => null,
            'last_archived_at_formatted' => null,
            'last_archived_at_diff' => null,
            'manifest_links' => null,
        ];
    }
}
