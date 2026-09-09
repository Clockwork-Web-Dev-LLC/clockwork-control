<?php

namespace App\Providers;

use App\Models\BlockedIp;
use App\Models\ContactFormTest;
use App\Models\ReviewQueueEntry;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Chat\ChatNotifierDispatcher;
use App\Services\Scheduler\SchedulerHeartbeat;
use App\Services\Updates\UpdateGrouping;
use App\Support\IssueCounter;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Core\ModuleStateResolver;
use Throwable;

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
        // and Modules\ClientSlack\ClientSlackServiceProvider.
        // Third-party notification modules only have to tag their ChatNotifier
        // and automatically benefit from every installed, enabled channel.
        $this->app->singleton(ChatNotifier::class, function ($app) {
            return new ChatNotifierDispatcher(iterator_to_array($app->tagged('clockwork.notifiers')));
        });
    }

    public function boot(): void
    {
        // Share the fleet-wide issue count + review queue count with the layout so the nav badges render.
        View::composer('layouts.app', function ($view) {
            $data = [
                'issueCount' => 0,
                'reviewQueueCount' => 0,
                'monitoringDownCount' => 0,
                'activeBansCount' => 0,
                'updatesPendingCount' => 0,
                'failingFormsCount' => 0,
            ];

            try {
                $data['issueCount'] = app(IssueCounter::class)->total();
            } catch (Throwable $e) {
                Log::warning('layout_composer.issue_count_failed', ['error' => $e->getMessage()]);
            }

            try {
                $data['reviewQueueCount'] = ReviewQueueEntry::query()
                    ->where('status', ReviewQueueEntry::STATUS_PENDING)
                    ->count();
            } catch (Throwable) {
            }

            try {
                $data['monitoringDownCount'] = Site::query()
                    ->where('uptime_monitoring_enabled', true)
                    ->where('uptime_state', 'down')
                    ->whereNull('uptime_ignored_at')
                    ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
                    ->count();
            } catch (Throwable) {
            }

            try {
                $data['activeBansCount'] = BlockedIp::query()->whereNull('unbanned_at')->count();
            } catch (Throwable) {
            }

            try {
                $data['updatesPendingCount'] = app(UpdateGrouping::class)->pendingCount();
            } catch (Throwable) {
            }

            try {
                $data['failingFormsCount'] = app(ModuleStateResolver::class)->isEnabled('contact-forms')
                    ? ContactFormTest::query()
                        ->where('enabled', true)
                        ->where('state', ContactFormTest::STATE_FAILED)
                        ->where('failure_streak', '>=', ContactFormTest::ALERT_STREAK_THRESHOLD)
                        ->whereHas('site', fn ($q) => $q->where('care_plan_enabled', true))
                        ->count()
                    : 0;
            } catch (Throwable) {
            }

            $view->with($data);

            try {
                $heartbeat = app(SchedulerHeartbeat::class);
                $heartbeat->notifyIfStale();
                $view->with('schedulerHeartbeat', $heartbeat->status());
            } catch (Throwable) {
                // Settings / chat must not take the layout down.
            }
        });
    }
}
