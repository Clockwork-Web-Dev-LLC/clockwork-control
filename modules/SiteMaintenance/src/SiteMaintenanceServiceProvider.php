<?php

namespace Modules\SiteMaintenance;

use Illuminate\Support\ServiceProvider;

class SiteMaintenanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        // Views stay in resources/views (shared)
    }
}
