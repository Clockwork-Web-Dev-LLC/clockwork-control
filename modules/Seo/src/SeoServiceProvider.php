<?php

namespace Modules\Seo;

use App\Services\Diagnostics\DiagnosticCheck;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;
use Modules\Seo\Diagnostics\GoogleConnectionCheck;
use Modules\Seo\Google\GoogleApi;
use Modules\Seo\Google\GoogleTokenProvider;

/**
 * The indexability watchdog itself stays in app/Services/Seo (core
 * monitoring, wired into the uptime probe and the Issues page — see
 * plans/seo-indexability-watchdog.md). This module only reads the
 * seo_* columns that watchdog writes.
 */
class SeoServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(GoogleApi::class, fn ($app) => new GoogleApi(
            $app->make(GoogleTokenProvider::class),
            (int) config('clockwork.seo.google_timeout', 30),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
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
            description: 'Fleet-wide SEO overview: search engine indexability for every monitored site, plus a nightly Search Console and GA4 sync for SEO clients.',
            credentialFields: [
                'google_client_id' => ['label' => 'Google OAuth client ID (Search Console + GA4)', 'secret' => false],
                'google_client_secret' => ['label' => 'Google OAuth client secret', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Reads the indexability watchdog that already runs with every uptime probe.',
        );
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(GoogleConnectionCheck::class);
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
