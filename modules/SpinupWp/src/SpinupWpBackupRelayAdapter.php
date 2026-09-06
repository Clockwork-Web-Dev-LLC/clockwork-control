<?php

namespace Modules\SpinupWp;

use App\Models\Site;
use App\Services\DigitalOcean\SpacesClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Contracts\BackupRef;
use Modules\Core\Contracts\BackupRelayAdapter;

class SpinupWpBackupRelayAdapter implements BackupRelayAdapter
{
    public function __construct(
        protected SpacesClient $spacesClient
    ) {}

    /**
     * Identify the newest backup reference for a site without downloading it.
     */
    public function latestBackupRef(Site $site): ?BackupRef
    {
        if (! $this->spacesClient->isConfigured() || ! $site->domain) {
            return null;
        }

        $objects = $this->spacesClient->listSiteBackupObjects($site);
        if (empty($objects)) {
            return null;
        }

        // Sort descending by last_modified to find the newest backup object
        usort($objects, function ($a, $b) {
            return ($b['last_modified'] ?? 0) <=> ($a['last_modified'] ?? 0);
        });

        $latest = $objects[0];
        $lastModified = isset($latest['last_modified'])
            ? Carbon::createFromTimestamp($latest['last_modified'])
            : Carbon::now();

        return new BackupRef(
            provider: Site::HOSTING_PROVIDER_SPINUPWP,
            externalId: $latest['key'],
            createdAt: $lastModified,
            sizeBytes: $latest['size'] ?? null,
            metadata: [
                'key' => $latest['key'],
            ],
        );
    }

    /**
     * Open a readable stream resource for the specified backup.
     *
     * @return resource|null
     */
    public function openBackupStream(Site $site, BackupRef $ref)
    {
        $key = $ref->metadata['key'] ?? $ref->externalId;

        return Storage::disk('do_spaces')->readStream($key);
    }
}
