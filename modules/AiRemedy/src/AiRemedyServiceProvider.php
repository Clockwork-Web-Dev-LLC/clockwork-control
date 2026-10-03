<?php

namespace Modules\AiRemedy;

use Illuminate\Console\Scheduling\Schedule;
use Modules\AiRemedy\Console\Commands\WatchServerSpikes;
use Modules\AiRemedy\Console\EvaluateOutcomes;
use Modules\AiRemedy\Console\ExpireUnreviewedRuns;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

class AiRemedyServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-remedy');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                WatchServerSpikes::class,
                EvaluateOutcomes::class,
                ExpireUnreviewedRuns::class,
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

        $schedule->command('clockwork:watch-server-spikes')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground();

        $schedule->command('clockwork:ai-remedy-evaluate-outcomes')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground();

        $schedule->command('clockwork:ai-remedy-expire-unreviewed')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground();
    }

    public function diagnosticCheck(): ?AiRemedyCheck
    {
        return new AiRemedyCheck;
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'ai-remedy',
            name: 'AiRemedy',
            description: 'AI-powered server diagnostics, interactive root-cause analysis, 1-click SSH remediation, and autonomous downtime self-healing via OpenRouter.',
            credentialFields: [
                'openrouter_api_key' => ['label' => 'OpenRouter API Key', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Diagnose and heal server performance spikes and site outages using any OpenRouter model (default: Claude Sonnet 4.5).',
        );
    }

    public function navItems(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return [
            new NavItem(
                label: 'AiRemedy',
                icon: 'fa-solid fa-wand-magic-sparkles',
                route: 'ai-remedy.index',
            ),
        ];
    }
}
