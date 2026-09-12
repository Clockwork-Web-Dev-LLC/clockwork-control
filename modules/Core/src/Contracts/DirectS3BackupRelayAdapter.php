<?php

namespace Modules\Core\Contracts;

use App\Models\Site;

/**
 * Backup adapters that stream from the origin directly to a Clockwork-minted
 * presigned S3 PUT URL (Companion / standalone sites). Distinct from
 * {@see BackupRelayAdapter::openBackupStream()} which Control pipes itself.
 */
interface DirectS3BackupRelayAdapter
{
    /**
     * @param  array{url: string, headers: array<string, string>}  $presigned
     * @return array{ok: bool, size_bytes?: int, sha256?: string, error?: string}
     */
    public function uploadDirectToS3(Site $site, string $destinationKey, array $presigned): array;
}
