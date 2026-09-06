<?php

namespace App\Console\Commands;

use App\Services\Updates\SystemUpdateService;
use Illuminate\Console\Command;

class ApplySystemUpdate extends Command
{
    protected $signature = 'clockwork:self-update
        {--force : Bypass confirmation prompt}';

    protected $description = 'Operator-triggered command to apply the latest updates to Clockwork Control Core.';

    public function handle(SystemUpdateService $updateService): int
    {
        $this->info('Clockwork Control Self-Update');
        $this->line('Checking preflight requirements...');

        $gitInfo = $updateService->getGitInfo();
        if ($gitInfo['is_git'] && ! $gitInfo['is_clean']) {
            $this->error('Aborted: Working copy has uncommitted changes. Please commit or stash your changes before updating.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if (! $this->confirm('Are you sure you want to update Clockwork Control? This will pull the latest release, run database migrations, and optimize caches.', true)) {
                $this->line('Update cancelled by operator.');

                return self::SUCCESS;
            }
        }

        $this->newLine();
        $this->info('Starting update sequence...');

        $result = $updateService->applyUpdate();

        foreach ($result['steps'] ?? [] as $step) {
            $status = $step['success'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$status} {$step['step']}");
            if (! empty($step['output']) && ($this->output->isVerbose() || ! $step['success'])) {
                $this->line("    <fg=gray>{$step['output']}</>");
            }
        }

        $this->newLine();

        if ($result['success']) {
            $this->info('✓ Clockwork Control updated successfully!');

            return self::SUCCESS;
        }

        $this->error('Update failed: '.($result['error'] ?? 'Unknown error occurred.'));

        return self::FAILURE;
    }
}
