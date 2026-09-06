<?php

namespace Modules\ClientSlack;

use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

/**
 * Sends a subset of alerts to a per-site Slack webhook a client configures
 * themselves in wp-admin.
 *
 * Container-tag registration ('clockwork.notifiers') is order-independent —
 * ChatNotifierDispatcher resolves app()->tagged('clockwork.notifiers')
 * lazily inside a closure at first use, well after every provider (core and
 * module) has registered, so it doesn't matter that this tag() call happens
 * from a module provider rather than AppServiceProvider.
 */
class ClientSlackServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        if ($this->enabled()) {
            $this->app->tag([ClientSlackNotifier::class], 'clockwork.notifiers');
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'client_slack',
            name: 'Client Slack Notifications',
            description: 'Sends a subset of alerts (form failures, site-down) to a per-site Slack webhook a client configures themselves in wp-admin. Agency-specific — see CONTRIBUTING.md.',
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and in active daily use for client-configured webhook notifications.',
        );
    }
}
