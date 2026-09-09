<?php

namespace Modules\Core;

use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Contracts\AuthProvider;
use Modules\Core\Contracts\CloudProvider;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SmsNotifier;

/**
 * Collects every registered ModuleServiceProvider so the app can ask "what
 * cloud providers exist", "what diagnostic checks exist", "what credential
 * fields need a settings-page row" without a hardcoded list per concern.
 * Bound as a singleton by CoreServiceProvider, which must be registered
 * before any module provider in bootstrap/providers.php.
 */
class ModuleRegistry
{
    /** @var list<ModuleServiceProvider> */
    private array $providers = [];

    public function register(ModuleServiceProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * @return list<ModuleManifest>
     */
    public function manifests(): array
    {
        return array_map(fn (ModuleServiceProvider $p) => $p->manifest(), $this->providers);
    }

    /**
     * @return list<CloudProvider>
     */
    public function cloudProviders(): array
    {
        return array_values(array_filter(array_map(
            fn (ModuleServiceProvider $p) => $p->cloudProvider(),
            $this->providers,
        )));
    }

    /**
     * @return list<DiagnosticCheck>
     */
    public function diagnosticChecks(): array
    {
        return array_values(array_filter(array_map(
            fn (ModuleServiceProvider $p) => $p->diagnosticCheck(),
            $this->providers,
        )));
    }

    /**
     * @return list<NavItem>
     */
    public function navItems(): array
    {
        $items = [];
        foreach ($this->providers as $provider) {
            if ($provider->enabled()) {
                array_push($items, ...$provider->navItems());
            }
        }

        return $items;
    }

    /**
     * @return list<HostingProvider>
     */
    public function hostingProviders(): array
    {
        return array_values(array_filter(array_map(
            fn (ModuleServiceProvider $p) => $p->hostingProvider(),
            $this->providers,
        )));
    }

    /**
     * @return list<AuthProvider>
     */
    public function authProviders(): array
    {
        return array_values(array_filter(array_map(
            fn (ModuleServiceProvider $p) => $p->authProvider(),
            $this->providers,
        )));
    }

    /**
     * @return list<SmsNotifier>
     */
    public function smsNotifiers(): array
    {
        return array_values(array_filter(array_map(
            fn (ModuleServiceProvider $p) => $p->smsNotifier(),
            $this->providers,
        )));
    }

    /**
     * Looks up the DiagnosticCheck a specific module contributes, by module
     * id — for /settings/integrations' "Test connection" action, which
     * knows the integration id from the URL but not which provider object
     * owns it.
     */
    public function diagnosticCheckFor(string $moduleId): ?DiagnosticCheck
    {
        foreach ($this->providers as $provider) {
            if ($provider->manifest()->id === $moduleId) {
                return $provider->diagnosticCheck();
            }
        }

        return null;
    }

    public function scheduleAll(Schedule $schedule): void
    {
        foreach ($this->providers as $provider) {
            $provider->scheduledTasks($schedule);
        }
    }
}
