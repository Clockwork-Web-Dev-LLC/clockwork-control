<?php

namespace Modules\EmailAuth;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;
use Modules\EmailAuth\Console\Commands\CheckEmailAuth;
use Modules\EmailAuth\Contracts\DnsTxtResolver;
use Modules\EmailAuth\Services\DohDnsTxtResolver;

class EmailAuthServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(DnsTxtResolver::class, function () {
            return new DohDnsTxtResolver;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-auth');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckEmailAuth::class,
            ]);
        }

        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        if (! $this->enabled()) {
            return;
        }

        $schedule->command('clockwork:check-email-auth')
            ->weeklyOn(1, '04:00')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground();
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'email-auth',
            name: 'Email Authentication',
            description: 'Monitor SPF 10-lookup limits, loop detection, DMARC enforcement policies, and DKIM selector probing across client domains.',
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Provides DoH-based DNS verification of SPF, DMARC, DKIM, and MX posture with transition degradation alerts.',
        );
    }

    public function navItems(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return [
            new NavItem(
                label: 'Email Auth',
                icon: 'fa-solid fa-envelope-circle-check',
                route: 'email-auth.index',
            ),
        ];
    }
}
