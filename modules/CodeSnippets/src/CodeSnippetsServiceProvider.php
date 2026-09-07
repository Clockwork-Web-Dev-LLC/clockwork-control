<?php

namespace Modules\CodeSnippets;

use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

class CodeSnippetsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'code-snippets');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'code-snippets',
            name: 'Code Snippets',
            description: 'Execute sandboxed PHP code on one or more WordPress sites via the Companion plugin, with preset and custom snippets.',
            status: ModuleManifest::STATUS_VERIFIED,
        );
    }

    public function navItems(): array
    {
        return [
            new NavItem(
                label: 'Code Snippets',
                icon: 'fa-solid fa-code',
                route: 'snippets.index',
            ),
        ];
    }
}
