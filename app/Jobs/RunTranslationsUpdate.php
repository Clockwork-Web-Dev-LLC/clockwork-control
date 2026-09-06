<?php

namespace App\Jobs;

use App\Models\ActionLog;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;

class RunTranslationsUpdate extends AbstractRunUpdate
{
    protected function run(Site $site, PluginUpdateJob $row, ClockworkCompanionClient $client): array
    {
        return $client->updateTranslations();
    }

    protected function actionLogType(): string
    {
        return ActionLog::TYPE_TRANSLATIONS_UPDATE;
    }

    protected function actionLogTarget(PluginUpdateJob $row): ?string
    {
        return null;
    }

    protected function actionLogSummary(PluginUpdateJob $row, array $result): string
    {
        if ($result['ok'] ?? false) {
            $count = (int) ($result['updated_count'] ?? 0);

            return sprintf('Translations updated: %d package(s)', $count);
        }

        return 'Translations update failed: '.(string) ($result['error'] ?? 'unknown');
    }
}
