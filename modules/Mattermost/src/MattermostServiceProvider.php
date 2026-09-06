<?php

namespace Modules\Mattermost;

use App\Services\Diagnostics\DiagnosticCheck;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

/**
 * Mattermost incoming-webhook chat notifications, as a real, independently
 * installable module — an agency that only wants Slack can leave this one
 * out of their composer.json entirely rather than having Mattermost baked
 * into core app code. Own settings page/routes/nav entry, same "full
 * module ownership" shape modules/BillCom already established.
 *
 * Container-tag registration ('clockwork.notifiers') is order-independent
 * — ChatNotifierDispatcher resolves app()->tagged('clockwork.notifiers')
 * lazily inside a closure at first use, well after every provider (core
 * and module) has registered — the same mechanism
 * Modules\ClientSlack\ClientSlackServiceProvider already uses.
 */
class MattermostServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        if ($this->enabled()) {
            $this->app->tag([MattermostNotifier::class], 'clockwork.notifiers');
        }
    }

    public function boot(): void
    {
        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'mattermost',
            name: 'Mattermost',
            description: 'Incoming-webhook chat notifications to a Mattermost channel, with a per-event on/off toggle. Independent of the Slack module — enable either, both, or neither.',
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and in active daily use for daytime ops alerts.',
        );
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(MattermostCheck::class);
    }

    public function navItems(): array
    {
        if (! config('clockwork.mattermost.enabled')) {
            return [];
        }

        return [
            new NavItem(
                label: 'Mattermost notifications',
                icon: 'fa-solid fa-comments',
                route: 'settings.mattermost.index',
            ),
        ];
    }
}
