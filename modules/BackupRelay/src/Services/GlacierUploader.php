<?php

namespace Modules\BackupRelay\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
     * @param  string  $destinationKey  Destination object key in S3
     * @param  array<string, mixed>  $options  Additional PutObject options
     * @return string Destination key
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
}
