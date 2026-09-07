<?php

namespace Modules\SiteMaintenance;

use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class SiteMaintenanceServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'site-maintenance',
            name: 'Site Maintenance',
            description: 'Toggle WordPress maintenance mode on/off without SSH access, with a custom headline and message.',
            status: ModuleManifest::STATUS_VERIFIED,
        );
    }
}
