<?php

namespace Modules\Core\Contracts;

use App\Models\Site;

interface BackupRelayAdapter
{
    /**
     * Identify the newest backup reference for a site without downloading it.
     */
    public function latestBackupRef(Site $site): ?BackupRef;

    /**
     * Open a readable stream resource for the specified backup.
     *
     * @return resource|null A readable PHP stream resource handle
     */
    public function openBackupStream(Site $site, BackupRef $ref);
}
