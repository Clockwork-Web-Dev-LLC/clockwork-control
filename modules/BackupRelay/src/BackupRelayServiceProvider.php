<?php

namespace Modules\BackupRelay;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Modules\BackupRelay\Console\RunBackupRelayNow;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Modules\BackupRelay\Services\GlacierUploader;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class BackupRelayServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(GlacierUploader::class, function () {
            return new GlacierUploader;
        });

        $this->app->singleton(BackupArchiveEnumerator::class, function () {
            return new BackupArchiveEnumerator;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RunBackupRelayNow::class,
            ]);
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'backup-relay',
            name: 'Backup Relay',
            description: 'Scheduled off-site backup streaming to S3 Glacier Instant Retrieval across supported hosting providers.',
            credentialFields: [
                'mode' => ['label' => 'Relay Mode (in_repo or external_agent)', 'secret' => false],
                'bucket' => ['label' => 'S3 Bucket', 'secret' => false],
                'region' => ['label' => 'S3 Region', 'secret' => false],
                'prefix' => ['label' => 'S3 Prefix', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified multi-provider backup relay and S3 Glacier archival pipeline.',
        );
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(BackupRelayCheck::class);
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        $mode = (string) config('clockwork.backup_relay.mode', 'in_repo');
        try {
            if (app()->bound(Settings::class)) {
                $mode = (string) app(Settings::class)->get('backup_relay.mode', $mode);
            }
        } catch (\Throwable) {
            // Keep default config mode
        }

        if ($mode === 'external_agent') {
            $schedule->command('clockwork:push-backup-relay-targets')
                ->dailyAt('04:58')
                ->withoutOverlapping(60)
                ->onOneServer();

            $schedule->command('clockwork:pull-backup-relay-report')
                ->dailyAt('06:40')
                ->withoutOverlapping(60)
                ->onOneServer();
        } else {
            $schedule->command('clockwork:backup-relay-run')
                ->dailyAt('04:58')
                ->withoutOverlapping(60)
                ->onOneServer();
        }
    }

    public function navItems(): array
    {
        return [];
    }
}
