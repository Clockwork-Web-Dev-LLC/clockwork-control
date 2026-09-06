<?php

namespace App\Providers;

use App\Models\BlockedIp;
use App\Models\ContactFormTest;
use App\Models\ReviewQueueEntry;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Chat\ChatNotifierDispatcher;
use App\Services\Updates\UpdateGrouping;
use App\Support\IssueCounter;
use App\Support\Settings;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Core\ModuleStateResolver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-request singleton so the read-cache inside Settings actually saves us hits.
        $this->app->singleton(Settings::class);

        // Fan-out chat notifier. Every real channel today (Mattermost, Slack,
        // ClientSlack) is a module that tags itself into the
        // 'clockwork.notifiers' container tag from its own register() — see
        // Modules\Mattermost\MattermostServiceProvider,
        // Modules\Slack\SlackServiceProvider,
        // Modules\ClientSlack\ClientSlackServiceProvider — the same way a
        // module contributes a CloudProvider or DiagnosticCheck. Nothing is
        // hardcoded here anymore; core app code just binds the fan-out
        // dispatcher behind the interface so callers type-hint ChatNotifier
        // and automatically benefit from every installed, enabled channel.
        $this->app->singleton(ChatNotifier::class, function ($app) {
            return new ChatNotifierDispatcher(iterator_to_array($app->tagged('clockwork.notifiers')));
        });
    }

    public function boot(): void
    {
        // Share the fleet-wide issue count + review queue count with the layout so the nav badges render.
        View::composer('layouts.app', function ($view) {
            $view->with([
                'issueCount' => app(IssueCounter::class)->total(),
                'reviewQueueCount' => ReviewQueueEntry::query()
                    ->where('status', ReviewQueueEntry::STATUS_PENDING)
                    ->count(),
                'monitoringDownCount' => Site::query()
                    ->where('uptime_monitoring_enabled', true)
                    ->where('uptime_state', 'down')
                    ->whereNull('uptime_ignored_at')
                    ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
                    ->count(),
                // Used by the unified Security tab strip — Active sub-tab
                // shows this badge regardless of which Security page you're on.
                'activeBansCount' => BlockedIp::query()->whereNull('unbanned_at')->count(),
                // Pending updates fleet-wide — plugins + themes + core + translations,
                // ignoring entries in plugin_update_ignores. Driven by UpdateGrouping
                // so the nav badge agrees with whatever the /updates page shows.
                'updatesPendingCount' => app(UpdateGrouping::class)->pendingCount(),
                // Failing form-tests fleet-wide — rows in failure state with a
                // streak past the Mattermost alert threshold (i.e. ones the
                // Issues page would surface).
                'failingFormsCount' => app(ModuleStateResolver::class)->isEnabled('contact-forms')
                    ? ContactFormTest::query()
                        ->where('enabled', true)
                        ->where('state', ContactFormTest::STATE_FAILED)
                        ->where('failure_streak', '>=', ContactFormTest::ALERT_STREAK_THRESHOLD)
                        ->whereHas('site', fn ($q) => $q->where('care_plan_enabled', true))
                        ->count()
                    : 0,
            ]);
        });
    }
}
