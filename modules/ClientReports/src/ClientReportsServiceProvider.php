<?php

namespace Modules\ClientReports;

use Modules\ClientReports\Console\Commands\SendScheduledClientReports;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

class ClientReportsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'client-reports');

        $this->commands([
            SendScheduledClientReports::class,
        ]);
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
                icon: 'fa-file-invoice',
                route: 'client-reports.index',
            ),
        ];
    }
}
