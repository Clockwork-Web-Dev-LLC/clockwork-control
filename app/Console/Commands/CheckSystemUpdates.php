<?php

namespace App\Console\Commands;

use App\Services\Updates\SystemUpdateService;
use Illuminate\Console\Command;

class CheckSystemUpdates extends Command
{
    protected $signature = 'clockwork:check-updates
        {--force : Force a fresh check against the upstream release channel}';

    protected $description = 'Check for new releases of Clockwork Control Core and companion plugin fleet status.';

    public function handle(SystemUpdateService $updateService): int
    {
        $this->info('Checking for Clockwork Control updates...');

        $info = $updateService->checkForUpdates((bool) $this->option('force'));

        $this->newLine();
        $this->line("  Installed Version : <fg=cyan>v{$info['current_version']}</>");

        if ($info['git']['is_git']) {
            $branch = $info['git']['branch'] ?? 'unknown';
            $commit = $info['git']['commit'] ?? 'unknown';
            $clean = $info['git']['is_clean'] ? 'clean' : '<fg=yellow>dirty</>';
            $this->line("  Git Environment   : {$branch}@{$commit} ({$clean})");
        }

        $this->line("  Release Channel   : {$info['channel']}");
        $this->line("  Latest Available  : v{$info['latest_version']}");

        if (! empty($info['error'])) {
            $this->newLine();
            $this->warn("  Notice: {$info['error']}");
        }

        $this->newLine();

        if ($info['has_update']) {
            $this->alert("An update is available: v{$info['latest_version']}");
            if (! empty($info['release_name'])) {
                $this->line("  <options=bold>{$info['release_name']}</>");
            }
            if (! empty($info['html_url'])) {
                $this->line("  Release Page: {$info['html_url']}");
            }
            $this->newLine();
            $this->info('  To update, visit Settings → Updates in the control panel or run:');
            $this->line('  php artisan clockwork:self-update');
        } else {
            $this->info("✓ Clockwork Control is up to date (v{$info['current_version']}).");
        }

        $this->newLine();
        $companion = $updateService->getCompanionFleetStatus();
        $this->line("Companion Plugin Fleet: <fg=cyan>v{$companion['bundled_version']}</> bundled");
        $this->line("  Total sites monitored : {$companion['total_sites']}");
        $this->line("  Sites with Companion  : {$companion['installed_sites']}");
        $this->line("  Up to date            : {$companion['up_to_date']}");
        if ($companion['needs_update'] > 0) {
            $this->line("  Needs update          : <fg=yellow>{$companion['needs_update']}</>");
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
