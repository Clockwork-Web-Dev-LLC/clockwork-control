<?php

namespace Modules\Seo;

use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

/**
 * The indexability watchdog itself stays in app/Services/Seo (core
 * monitoring, wired into the uptime probe and the Issues page — see
 * plans/seo-indexability-watchdog.md). This module only reads the
 * seo_* columns that watchdog writes.
 */
class SeoServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'seo');

        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'seo',
            name: 'SEO',
            description: 'Fleet-wide SEO overview: search engine indexability for every monitored site.',
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Reads the indexability watchdog that already runs with every uptime probe.',
        );
    }

    public function navItems(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return [
            new NavItem(
                label: 'SEO',
                icon: 'fa-solid fa-magnifying-glass-chart',
                route: 'seo.index',
            ),
        ];
    }
}
