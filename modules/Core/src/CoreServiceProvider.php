<?php

namespace Modules\Core;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Contracts\SmsNotifier;

/**
 * Must be listed before any ModuleServiceProvider in bootstrap/providers.php
 * — module providers resolve ModuleRegistry out of the container during
 * their own register(), which requires this binding to exist first.
 */
class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(ModuleStateResolver::class);

        // Lazy — the closure only runs on first actual resolution, by which
        // point every module provider (registered after this one) has
        // already run its own register(), so ModuleRegistry is fully
        // populated. Same safety pattern AppServiceProvider's ChatNotifier
        // binding already relies on for 'clockwork.notifiers'.
        $this->app->singleton(SmsNotifier::class, function ($app) {
            $modules = $app->make(ModuleRegistry::class)->smsNotifiers();

            return $modules[0] ?? $app->make(NullSmsNotifier::class);
        });
    }
}
