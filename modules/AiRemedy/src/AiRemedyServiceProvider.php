<?php

namespace Modules\AiRemedy;

use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

class AiRemedyServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-remedy');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'ai-remedy',
            name: 'AiRemedy',
            description: 'AI-powered server diagnostics, interactive root-cause analysis, 1-click SSH remediation, and autonomous downtime self-healing via OpenRouter.',
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Diagnose and heal server performance spikes and site outages using Claude 3.5 Sonnet and GPT-4o via OpenRouter.',
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
