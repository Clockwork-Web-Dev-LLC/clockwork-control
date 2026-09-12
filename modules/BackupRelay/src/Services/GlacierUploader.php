<?php

namespace Modules\BackupRelay\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GlacierUploader
{
    public function __construct(
        protected ?string $diskName = null
    ) {
        $this->diskName = $diskName ?? config('clockwork.backup_relay.disk', 's3-backup-relay');
    }

    /**
     * Upload a stream or content to S3 with GLACIER_IR storage class.
     *
     * @param  resource|string  $contents  Stream resource or string content
     * @param  array<string, mixed>  $options  Additional PutObject options
     */
    public function uploadStream(mixed $contents, string $destinationKey, array $options = []): string
    {
        $disk = Storage::disk($this->diskName);

        $writeOptions = array_merge([
            'StorageClass' => 'GLACIER_IR',
        ], $options);

        $success = $disk->put($destinationKey, $contents, $writeOptions);

        if (! $success) {
            throw new RuntimeException("Failed to upload backup stream to {$this->diskName}://{$destinationKey}");
        }

        return $destinationKey;
    }

    public function diskName(): string
    {
        return $this->diskName;
    }

    /**
     * Generate a presigned S3 PUT URL for direct-to-S3 uploads with GLACIER_IR.
     *
     * Companion must send every returned header (especially
     * `x-amz-storage-class`) or SigV4 rejects the PUT with 403.
     *
     * @param  array<string, mixed>  $options
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignedUploadUrl(string $destinationKey, int $ttlMinutes = 120, array $options = []): array
    {
        $disk = Storage::disk($this->diskName);

        $writeOptions = array_merge([
            'StorageClass' => 'GLACIER_IR',
        ], $options);

        try {
            $presigned = $disk->temporaryUploadUrl(
                $destinationKey,
                now()->addMinutes($ttlMinutes),
                $writeOptions,
            );

            return [
                'url' => (string) ($presigned['url'] ?? ''),
                'headers' => $this->normalizePutHeaders($presigned['headers'] ?? []),
            ];
        } catch (Throwable) {
            // Local/fake disks do not support S3 upload URLs.
            return [
                'url' => "https://s3.amazonaws.com/{$this->diskName}/{$destinationKey}?mock-presigned=true",
                'headers' => ['x-amz-storage-class' => 'GLACIER_IR'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private function normalizePutHeaders(array $raw): array
    {
        $headers = [];

        foreach ($raw as $name => $value) {
            $key = strtolower((string) $name);
            if (in_array($key, ['host', 'authorization', 'content-length'], true)) {
                continue;
            }

            $headers[(string) $name] = is_array($value)
                ? implode(',', array_map(strval(...), $value))
                : (string) $value;
        }

        $hasStorageClass = false;
        foreach (array_keys($headers) as $name) {
            if (strtolower((string) $name) === 'x-amz-storage-class') {
                $hasStorageClass = true;
                break;
            }
        }

        if (! $hasStorageClass) {
            $headers['x-amz-storage-class'] = 'GLACIER_IR';
        }

        return $headers;
    }
}
