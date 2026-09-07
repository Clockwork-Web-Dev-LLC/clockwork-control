<?php

namespace Modules\CommentModeration;

use Illuminate\Console\Scheduling\Schedule;
use Modules\CommentModeration\Console\Commands\CleanupSpamCommentsCommand;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class CommentModerationServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'comment-moderation');

        $this->commands([
            CleanupSpamCommentsCommand::class,
        ]);

        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'comment-moderation',
            name: 'Comment Moderation',
            description: 'Browse, filter, and moderate WordPress comments (approve, hold, spam, trash, delete) plus bulk cleanup of old spam/trash via the Companion plugin.',
            status: ModuleManifest::STATUS_VERIFIED,
        );
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        if ($this->enabled()) {
            $schedule->command('clockwork:cleanup-spam-comments')
                ->weeklyOn(0, '05:15')
                ->withoutOverlapping()
                ->onOneServer();
        }
    }
}
