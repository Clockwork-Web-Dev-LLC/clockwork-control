<?php

namespace App\Jobs;

use App\Models\ActionLog;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;

class RunPluginUpdate extends AbstractRunUpdate
{
    protected function run(Site $site, PluginUpdateJob $row, ClockworkCompanionClient $client): array
    {
        return $client->updatePlugin((string) $row->target_slug);
    }

    protected function actionLogType(): string
    {
        return ActionLog::TYPE_PLUGIN_UPDATE;
    }

    protected function actionLogTarget(PluginUpdateJob $row): ?string
    {
        return $row->target_slug;
    }

    protected function actionLogSummary(PluginUpdateJob $row, array $result): string
    {
        $name = $row->target_name ?: $row->target_slug;
        if ($result['ok'] ?? false) {
            return sprintf(
                'Plugin updated: %s %s → %s',
                $name,
                $result['before_version'] ?? '?',
                $result['after_version'] ?? '?',
            );
        }

        return "Plugin update failed: {$name} — ".(string) ($result['error'] ?? 'unknown');
    }
}
