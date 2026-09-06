<?php

namespace App\Jobs;

use App\Models\ActionLog;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;

class RunThemeUpdate extends AbstractRunUpdate
{
    protected function run(Site $site, PluginUpdateJob $row, ClockworkCompanionClient $client): array
    {
        return $client->updateTheme((string) $row->target_slug);
    }

    protected function actionLogType(): string
    {
        return ActionLog::TYPE_THEME_UPDATE;
    }

    protected function actionLogTarget(PluginUpdateJob $row): ?string
    {
        return $row->target_slug;
    }

    protected function actionLogSummary(PluginUpdateJob $row, array $result): string
    {
        $name = $row->target_name ?: $row->target_slug;
        if ($result['ok'] ?? false) {
            $summary = sprintf(
                'Theme updated: %s %s → %s',
                $name,
                $result['before_version'] ?? '?',
                $result['after_version'] ?? '?',
            );
            $repaired = (int) ($result['subsites_repaired'] ?? 0);
            if ($repaired > 0) {
                $summary .= sprintf(' (%d sub-site%s repaired after theme switch)', $repaired, $repaired === 1 ? '' : 's');
            }

            return $summary;
        }

        return "Theme update failed: {$name} — ".(string) ($result['error'] ?? 'unknown');
    }
}
