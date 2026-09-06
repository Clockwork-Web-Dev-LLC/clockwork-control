<?php

namespace Modules\Slack;

use App\Services\Diagnostics\DiagnosticCheck;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

/**
 * Slack incoming-webhook chat notifications, as a real, independently
 * installable module — mirrors Modules\Mattermost\MattermostServiceProvider
 * exactly. An agency that only wants Mattermost can leave this one out of
 * their composer.json entirely.
 */
class SlackServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        if ($this->enabled()) {
            $this->app->tag([SlackNotifier::class], 'clockwork.notifiers');
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
            id: 'slack',
            name: 'Slack',
            description: 'Incoming-webhook chat notifications to a Slack channel, with a per-event on/off toggle. Independent of the Mattermost module — enable either, both, or neither.',
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and in active daily use for ops and per-site client channels.',
        );
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(SlackCheck::class);
    }

    public function navItems(): array
    {
        if (! config('clockwork.slack.enabled')) {
            return [];
        }

        return [
            new NavItem(
                label: 'Slack notifications',
                icon: 'fa-brands fa-slack',
                route: 'settings.slack.index',
            ),
        ];
    }
}
