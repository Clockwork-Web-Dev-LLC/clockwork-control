<?php

use Illuminate\Support\Facades\Route;
use Modules\Mattermost\MattermostSettingsController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/settings/mattermost', [MattermostSettingsController::class, 'index'])->name('settings.mattermost.index');
    Route::patch('/settings/mattermost/events/{key}', [MattermostSettingsController::class, 'toggleEvent'])->name('settings.mattermost.events.update');
});
