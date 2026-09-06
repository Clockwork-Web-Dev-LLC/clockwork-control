<?php

namespace App\Jobs;

use App\Models\ActionLog;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;

class RunCoreUpdate extends AbstractRunUpdate
{
    public int $timeout = 300;

    /**
     * Whether the operator confirmed a major-version bump. Carried with the
     * job because the snapshot at run-time may have shifted (rare but
     * possible — operator confirms 6.x→7.0 on Monday, snapshot updates
     * Wednesday to show 7.0 already, etc.). The flag captured at enqueue
     * time is what governs.
     */
    public function __construct(int $jobRowId, public readonly bool $confirmMajor = false)
    {
        parent::__construct($jobRowId);
    }

    protected function run(Site $site, PluginUpdateJob $row, ClockworkCompanionClient $client): array
    {
        return $client->updateCore($this->confirmMajor);
    }

    protected function actionLogType(): string
    {
        return ActionLog::TYPE_CORE_UPDATE;
    }

    protected function actionLogTarget(PluginUpdateJob $row): ?string
    {
        return null;
    }

    protected function actionLogSummary(PluginUpdateJob $row, array $result): string
    {
        if ($result['ok'] ?? false) {
            return sprintf(
                'WordPress core updated: %s → %s',
                $result['before_version'] ?? '?',
                $result['after_version'] ?? '?',
            );
        }

        return 'WordPress core update failed: '.(string) ($result['error'] ?? 'unknown');
    }
}
