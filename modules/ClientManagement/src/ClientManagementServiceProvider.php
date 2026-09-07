<?php

namespace Modules\ClientManagement;

use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

class ClientManagementServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'client-management');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'client-management',
            name: 'Client Management',
            description: 'Organize WordPress sites by client, with a client directory and per-client site assignment.',
            status: ModuleManifest::STATUS_VERIFIED,
        );
    }

    public function navItems(): array
    {
        return [
            new NavItem(
                label: 'Clients',
                icon: 'fa-solid fa-users',
                route: 'clients.index',
            ),
        ];
    }
}
