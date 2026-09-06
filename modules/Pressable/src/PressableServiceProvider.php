<?php

namespace Modules\Pressable;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class PressableServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(PressableClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);
            $viewOnlyVal = $r->get('pressable.view_only');
            $viewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.pressable.view_only', false);

            // timeout intentionally NOT resolved here — see
            // DigitalOceanServiceProvider's comment on why a concrete value
            // passed via the constructor would short-circuit PressableClient's
            // own Settings-driven operator override.
            return new PressableClient(
                clientId: $r->get('pressable.client_id'),
                clientSecret: $r->get('pressable.client_secret'),
                authUrl: $r->get('pressable.auth_url'),
                baseUrl: $r->get('pressable.base_url'),
                viewOnly: $viewOnly,
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'pressable',
            name: 'Pressable',
            description: 'Managed WordPress hosting with no server/SSH concept — sites are addressed by site id via Pressable\'s own API.',
            credentialFields: [
                'client_id' => ['label' => 'Client ID', 'secret' => false],
                'client_secret' => ['label' => 'Client Secret', 'secret' => true],
                'view_only' => ['label' => 'View-Only Mode', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified managed Pressable hosting integration, site imports, and backup reports.',
        );
    }

    public function hostingProvider(): ?HostingProvider
    {
        return $this->app->make(PressableHostingProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(PressableCheck::class);
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        // Moved from routes/console.php (Phase 7) — exact same cron/modifier chains.
        $schedule->command('clockwork:pressable-backups-report')
            ->dailyAt('06:32')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command('clockwork:pressable-traffic-report')
            ->dailyAt('06:37')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->command('clockwork:pressable-security-summary-report')
            ->dailyAt('06:39')
            ->withoutOverlapping(60)
            ->onOneServer();
    }
}
