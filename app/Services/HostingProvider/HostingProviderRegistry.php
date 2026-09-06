<?php

namespace App\Services\HostingProvider;

use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleRegistry;

/**
 * Resolves Site::hosting_provider to its HostingProvider adapter.
 *
 * As of Phase 6, every registered provider (SpinupWp, Pressable) is
 * module-sourced via ModuleRegistry — Phase 5 injected SpinupWpHostingProvider
 * directly here since SpinupWp wasn't a module yet; that asymmetry is gone
 * now that it is.
 *
 * No NullHostingProvider fallback here — unlike servers.provider,
 * sites.hosting_provider is a required, non-nullable column with exactly
 * two values in this codebase today, both handled. resolve() throws
 * rather than silently misroute if that ever stops being true.
 */
class HostingProviderRegistry
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function resolve(string $hostingProvider): HostingProvider
    {
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
        return $this->modules->hostingProviders();
    }
}
