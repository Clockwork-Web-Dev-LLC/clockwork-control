<?php

namespace Modules\Core;

use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Contracts\AuthProvider;
use Modules\Core\Contracts\CloudProvider;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SmsNotifier;

/**
 * Base class every module's service provider extends. Handles
 * self-registration into ModuleRegistry; modules override the hook methods
 * for whatever they contribute (a CloudProvider, a DiagnosticCheck,
 * scheduled tasks — a module can contribute none, one, or more of these).
 *
 * DiagnosticCheck stays under App\Services\Diagnostics rather than moving
 * to Modules\Core\Contracts — it's a pre-existing app-wide abstraction used
 * by 16 non-module checks today, and moving it is out of scope for this
 * phase (which is specifically the three IaaS providers). Module code
 * depending on it, and on App\Models\Server via the CloudProvider contract,
 * is a deliberate, narrow exception to "module code never imports App\" —
 * both are core domain types, not app business logic.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    abstract public function manifest(): ModuleManifest;

    public function register(): void
    {
        if ($this->enabled()) {
            $this->app->make(ModuleRegistry::class)->register($this);
        }
    }

    public function enabled(): bool
    {
        return $this->app->make(ModuleStateResolver::class)->isEnabled($this->manifest()->id);
    }

    public function cloudProvider(): ?CloudProvider
    {
        return null;
    }

    public function hostingProvider(): ?HostingProvider
    {
        return null;
    }

    public function authProvider(): ?AuthProvider
    {
        return null;
    }

    /**
     * The fleet-wide SMS vendor this module contributes, if any. Unlike
     * cloudProvider()/hostingProvider() (which are looked up per-server/
     * per-site by id), realistic installs have at most one SMS module
     * active — ModuleRegistry::smsNotifiers() just collects whichever
     * modules return non-null here, and the container binds
     * Modules\Core\Contracts\SmsNotifier to the first one (or
     * NullSmsNotifier if none).
     */
    public function smsNotifier(): ?SmsNotifier
    {
        return null;
    }

    /**
     * Gear-menu links this module wants to contribute. Empty by default —
     * most modules (all five as of Phase 7) are reached through existing
     * generic pages (Sites, Servers, /settings/integrations) and need no
     * nav entry of their own.
     *
     * @return list<NavItem>
     */
    public function navItems(): array
    {
        return [];
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return null;
    }

    /**
     * Contribute schedule() entries. No-op by default — the Azure/Hetzner/
     * DigitalOcean modules built in Phase 4 are polled by shared
     * cross-provider commands (clockwork:poll-servers, clockwork:reconcile-
     * provider), not their own schedule entries. SpinupWp's nightly import
     * (Phase 6) will be the first real user of this hook.
     */
    public function scheduledTasks(Schedule $schedule): void
    {
        //
    }
}
