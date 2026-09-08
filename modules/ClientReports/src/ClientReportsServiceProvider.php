<?php

namespace Modules\ClientReports;

use Illuminate\Console\Scheduling\Schedule;
use Modules\ClientReports\Console\Commands\SendScheduledClientReports;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

class ClientReportsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'client-reports');

        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        $this->commands([
            SendScheduledClientReports::class,
        ]);
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        $schedule->command('clockwork:send-client-reports')
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->onOneServer();
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'client_reports',
            name: 'Client Reports',
            description: 'Automated executive client reporting aggregating updates, uptime, security, backups, performance, and forms into white-labeled reports.',
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and active for client monthly/weekly maintenance reporting.',
        );
    }

    public function navItems(): array
    {
        return [
            new NavItem(
                label: 'Client reports',
                icon: 'fa-solid fa-file-lines',
                route: 'client-reports.index',
            ),
        ];
    }
}
