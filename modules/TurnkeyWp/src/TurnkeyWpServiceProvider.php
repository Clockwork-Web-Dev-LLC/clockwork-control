<?php

namespace Modules\TurnkeyWp;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\TurnkeyWp\Commands\ImportTurnkeyWp;
use Modules\TurnkeyWp\Commands\TurnkeyWpAuthorizeSsh;
use Modules\TurnkeyWp\Commands\TurnkeyWpTest;

class TurnkeyWpServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(TurnkeyWpClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            $viewOnlyVal = $r->get('turnkeywp.view_only');
            $viewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.turnkeywp.view_only', false);

            // timeout intentionally NOT resolved here, so TurnkeyWpClient's
            // own Settings-driven operator override still applies.
            return new TurnkeyWpClient(
                token: $r->get('turnkeywp.token'),
                baseUrl: $r->get('turnkeywp.base_url'),
                viewOnly: $viewOnly,
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportTurnkeyWp::class,
                TurnkeyWpTest::class,
                TurnkeyWpAuthorizeSsh::class,
            ]);
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'turnkeywp',
            name: 'TurnkeyWP',
            description: "Clockwork's self-hosted WordPress hosting control plane. Imports its servers and sites over the TurnkeyWP API; servers are reached over SSH as the turnkey sudo user.",
            credentialFields: [
                'token' => ['label' => 'API Token', 'secret' => true],
                'base_url' => ['label' => 'API Base URL', 'secret' => false],
                'view_only' => ['label' => 'View-Only Mode (Read-Only)', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'New: API client and server/site import, not yet run against the production fleet.',
        );
    }

    public function hostingProvider(): ?HostingProvider
    {
        return $this->app->make(TurnkeyWpHostingProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(TurnkeyWpCheck::class);
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        // After clockwork:import-spinupwp (:30), which nulls spinupwp_id on
        // sites deleted from SpinupWP, so a site cut over that hour is
        // adopted here before clockwork:find-orphan-sites (:35) runs.
        // Skipped until a token is configured, so an install that doesn't use
        // TurnkeyWP doesn't log a failed run every hour.
        $schedule->command('clockwork:import-turnkeywp')
            ->hourlyAt(32)
            ->when(fn () => $this->app->make(TurnkeyWpClient::class)->isConfigured())
            ->withoutOverlapping(30)
            ->onOneServer();
    }
}
