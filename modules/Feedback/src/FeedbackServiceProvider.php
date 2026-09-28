<?php

namespace Modules\Feedback;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\View\View;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;
use Modules\Feedback\Console\Commands\GenerateFeedbackPromptCommand;

class FeedbackServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'feedback');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateFeedbackPromptCommand::class,
            ]);
        }

        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

            $this->app['view']->composer('*', function (View $view): void {
                $name = $view->getName();
                if (str_starts_with($name, 'layouts.') ||
                    str_starts_with($name, 'components.') ||
                    $name === 'feedback::_overlay') {
                    return;
                }

                if (! app()->bound('feedback.page_view')) {
                    $realPath = realpath($view->getPath()) ?: $view->getPath();
                    $basePath = base_path();
                    $relative = str_starts_with($realPath, $basePath)
                        ? ltrim(substr($realPath, strlen($basePath)), '/\\')
                        : $realPath;

                    app()->instance('feedback.page_view', [
                        'name' => $name,
                        'path' => $relative,
                    ]);
                }
            });
        }
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        if ($this->enabled()) {
            $schedule->command('clockwork:feedback-prompt')
                ->dailyAt('09:00')
                ->withoutOverlapping()
                ->onOneServer();
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'feedback',
            name: 'Feedback',
            description: 'In-app visual feedback, live pin annotations, threaded team discussions, and one-click Claude prompt generation.',
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Point-and-click in-app commenting with live DOM context capture, threaded discussions, and automated Claude prompts.',
        );
    }

    public function navItems(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return [
            new NavItem(
                label: 'Feedback',
                icon: 'fa-solid fa-comment-dots',
                route: 'feedback.index',
            ),
        ];
    }
}
