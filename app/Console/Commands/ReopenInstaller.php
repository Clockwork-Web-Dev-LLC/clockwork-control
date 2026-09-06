<?php

namespace App\Console\Commands;

use App\Http\Middleware\EnforceInstallerGate;
use App\Models\ActionLog;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Console\Command;

class ReopenInstaller extends Command
{
    protected $signature = 'clockwork:installer:reopen
                            {--force : Force the operation without an interactive prompt}';

    protected $description = 'Reopen the web-based installer by removing the storage/installed sentinel.';

    public function handle(ActionLogger $logger): int
    {
        $sentinel = EnforceInstallerGate::sentinelPath();

        if (! file_exists($sentinel)) {
            $this->warn('The installer is already open (storage/installed does not exist).');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('The --force option is required when running in non-interactive mode.');

                return self::FAILURE;
            }

            $confirmed = $this->confirm(
                'Are you sure you want to reopen the web installer? This exposes /install until re-locked.',
                false
            );

            if (! $confirmed) {
                $this->line('Aborted. Sentinel remains intact.');

                return self::SUCCESS;
            }
        }

        if (@unlink($sentinel)) {
            @touch(storage_path('installer_reopened'));

            $logger->record(
                actionType: ActionLog::TYPE_INSTALLER_REOPENED,
                summary: 'Web installer reopened via artisan (storage/installed removed).',
                ok: true,
                actor: 'cli',
            );

            $this->info('Web installer has been reopened.');
            $this->line('Navigate to /install in your browser to run the wizard.');

            return self::SUCCESS;
        }

        $this->error("Failed to remove sentinel at [{$sentinel}]. Check file permissions.");

        return self::FAILURE;
    }
}
