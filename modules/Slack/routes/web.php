<?php

use Illuminate\Support\Facades\Route;
use Modules\Slack\SlackSettingsController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/settings/slack', [SlackSettingsController::class, 'index'])->name('settings.slack.index');
    Route::patch('/settings/slack/events/{key}', [SlackSettingsController::class, 'toggleEvent'])->name('settings.slack.events.update');
});
