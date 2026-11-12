<?php

namespace Modules\Seo;

use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;
use Modules\Seo\Console\SyncGoogleData;
use Modules\Seo\Diagnostics\GoogleConnectionCheck;
use Modules\Seo\Google\GoogleApi;
use Modules\Seo\Google\GoogleTokenProvider;
use Modules\Seo\Mcp\Tools\SeoAiImpressionsRecord;
use Modules\Seo\Mcp\Tools\SeoDailySeries;
use Modules\Seo\Mcp\Tools\SeoListSites;
use Modules\Seo\Mcp\Tools\SeoMonthNumbers;
use Modules\Seo\Mcp\Tools\SeoSearchRows;
use Modules\Seo\Mcp\Tools\SeoSiteProfileUpdate;
use Modules\Seo\Models\GoogleConnection;

/**
 * SEO tooling. Two halves:
 *
 * - The fleet indexability overview (/seo) only reads the seo_* columns on
 *   sites that the core watchdog in app/Services/Seo writes. That watchdog
 *   stays in core: the uptime prober and the Issues page depend on it.
 * - The Search Console + GA4 sync (plans/seo-semrush-replacement.md) owns its
 *   own Google connection, the seo_* tables and the nightly command.
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

        $this->commands([SyncGoogleData::class]);
    }

    /**
     * 08:10 UTC is after midnight Pacific, so yesterday's Search Console day
     * has closed, and it sits in the quiet 07:15 to 09:00 window. The guard
     * checks a connection was ever made, not that it is active: a revoked
     * connection still runs and exits failed so the Scheduled Jobs page shows
     * it, while a deliberate disconnect (which deletes the row) stops it.
     */
    public function scheduledTasks(Schedule $schedule): void
    {
        $schedule->command('clockwork:seo-sync-google')
            ->dailyAt('08:10')
            ->withoutOverlapping(120)
            ->onOneServer()
            ->runInBackground()
            ->when(fn () => GoogleConnection::query()->exists());
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
                'dataforseo_login' => ['label' => 'DataForSEO API login', 'secret' => false],
                'dataforseo_password' => ['label' => 'DataForSEO API password', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Reads the indexability watchdog that already runs with every uptime probe.',
        );
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(GoogleConnectionCheck::class);
    }

    /**
     * The tools Claude reaches SEO data through, on the server modules/Mcp
     * hosts. All read stored rows only; none calls Google.
     */
    public function mcpTools(): array
    {
        return [
            SeoListSites::class,
            SeoMonthNumbers::class,
            SeoDailySeries::class,
            SeoSearchRows::class,
            SeoAiImpressionsRecord::class,
            SeoSiteProfileUpdate::class,
        ];
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
