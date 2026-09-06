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
        // Moved from routes/console.php (Phase 7) — exact same cron/modifier
        // chain. clockwork:reconcile-provider (03:45, still in routes/console.php)
        // depends on this having run first each day; that's a wall-clock
        // ordering, not a code-registration one, so it's unaffected by the move.
        $schedule->command('clockwork:import-spinupwp')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Reclassify orphaned sites — Site rows whose SpinupWP linkage was
        // lost (likely consolidated as additional_domain on another site).
        // Daily at 03:35, right after the SpinupWP inventory import above.
        $schedule->command('clockwork:find-orphan-sites')
            ->dailyAt('03:35')
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
