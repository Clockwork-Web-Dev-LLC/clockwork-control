<?php

namespace Modules\GridPane;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\GridPane\Commands\GridPaneTest;
use Modules\GridPane\Commands\ImportGridPane;

class GridPaneServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(GridPaneClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            $viewOnlyVal = $r->get('gridpane.view_only');
            $viewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.gridpane.view_only', true);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit GridPaneClient's
            // own Settings-driven operator override.
            return new GridPaneClient(
                apiKey: $r->get('gridpane.api_key'),
                baseUrl: $r->get('gridpane.base_url'),
                viewOnly: $viewOnly,
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportGridPane::class,
                GridPaneTest::class,
            ]);
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'gridpane',
            name: 'GridPane',
            description: 'Self-managed WordPress hosting on cloud VPS (DigitalOcean, Vultr, Linode, AWS, Hetzner, UpCloud, Custom) with SSH and full server management.',
            credentialFields: [
                'api_key' => ['label' => 'API Key / Token', 'secret' => true],
                'base_url' => ['label' => 'API Base URL', 'secret' => false],
                'view_only' => ['label' => 'View-Only Mode (Read-Only)', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'GridPane API v1 client, server/site import, rate limiting, and SSH execution verified in active production.',
        );
    }

    public function hostingProvider(): ?HostingProvider
    {
        return $this->app->make(GridPaneHostingProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(GridPaneCheck::class);
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        $schedule->command('clockwork:import-gridpane')
            ->dailyAt('03:40')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
