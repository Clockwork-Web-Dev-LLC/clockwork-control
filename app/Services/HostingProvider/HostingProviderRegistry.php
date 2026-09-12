<?php

namespace App\Services\HostingProvider;

use App\Models\Site;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleRegistry;

/**
 * Resolves Site::hosting_provider to its HostingProvider adapter.
 *
 * Every registered provider (SpinupWp, Pressable, WPEngine, Kinsta,
 * Cloudways, GridPane) is module-sourced via ModuleRegistry, which only
 * collects a module's contribution when that module is actually enabled
 * (ModuleServiceProvider::register() gates on enabled() before registering
 * anything). all() is therefore already "enabled hosting providers only" —
 * callers that need to show operators only the providers they've actually
 * turned on (e.g. SitesController's provider tabs/filter) can use it
 * directly rather than re-deriving enablement themselves.
 *
 * No NullHostingProvider fallback here — sites.hosting_provider is a
 * required, non-nullable column, and resolve() throws rather than silently
 * misroute if it's ever asked to resolve a value with no registered
 * provider (e.g. a disabled module whose sites are still in the DB).
 */
class HostingProviderRegistry
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function resolve(string $hostingProvider): HostingProvider
    {
        if ($hostingProvider === Site::HOSTING_PROVIDER_CUSTOM) {
            return app(CustomHostingProvider::class);
        }

        foreach ($this->modules->hostingProviders() as $provider) {
            if ($provider->id() === $hostingProvider) {
                return $provider;
            }
        }

        throw new \RuntimeException("No HostingProvider registered for '{$hostingProvider}'.");
    }

    /**
     * @return list<HostingProvider>
     */
    public function all(): array
    {
        $providers = $this->modules->hostingProviders();
        $providers[] = app(CustomHostingProvider::class);

        return $providers;
    }
}
