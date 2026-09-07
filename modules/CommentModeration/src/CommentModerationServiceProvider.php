<?php

namespace Modules\CommentModeration;

use Illuminate\Support\ServiceProvider;

class CommentModerationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'comment-moderation');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
