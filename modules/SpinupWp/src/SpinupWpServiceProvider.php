<?php

namespace Modules\SpinupWp;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class SpinupWpServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(SpinupWpClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);
            $viewOnlyVal = $r->get('spinupwp.view_only');
            $viewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.spinupwp.view_only', false);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit SpinupWpClient's
            // own Settings-driven operator override.
            return new SpinupWpClient(
                token: $r->get('spinupwp.token'),
                baseUrl: $r->get('spinupwp.base_url'),
                viewOnly: $viewOnly,
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'spinupwp',
            name: 'SpinupWP',
            description: 'Self-managed WordPress hosting on DigitalOcean/Hetzner droplets, with SSH access on every site.',
            credentialFields: [
                'token' => ['label' => 'API Token', 'secret' => true],
                'view_only' => ['label' => 'View-Only Mode', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and in active daily use in Clockwork production.',
        );
    }

    public function hostingProvider(): ?HostingProvider
    {
        return $this->app->make(SpinupWpHostingProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(SpinupWpCheck::class);
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        // Scheduled hourly at minute 30 so new sites, deleted sites, and site moves
        // between servers are detected automatically throughout the day rather than
        // waiting 24 hours. Still hits 03:30 for downstream daily jobs.
        $schedule->command('clockwork:import-spinupwp')
            ->hourlyAt(30)
            ->withoutOverlapping()
            ->onOneServer();

        // Reclassify orphaned sites — Site rows whose SpinupWP linkage was
        // lost (likely consolidated as additional_domain on another site).
        // Runs 5 minutes after import-spinupwp.
        $schedule->command('clockwork:find-orphan-sites')
            ->hourlyAt(35)
            ->withoutOverlapping(30)
            ->onOneServer();

        // Push per-site backup config from SpinupWP to each Companion-equipped
        // site so clients can see their backup status in wp-admin. Daily at
        // 06:30 — after the 03:30 inventory import above has refreshed our
        // local copy.
        $schedule->command('clockwork:push-companion-backups')
            ->dailyAt('06:30')
            ->withoutOverlapping(60)
            ->onOneServer();
    }
}
