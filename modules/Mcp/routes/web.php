<?php

use Illuminate\Support\Facades\Route;
use Modules\Mcp\Http\Controllers\ClaudeAccessController;

// The settings page exists whenever the module is enabled, whether or not
// the endpoint is switched on: the switch lives here.
Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/settings/claude-access', [ClaudeAccessController::class, 'index'])->name('settings.claude-access.index');

    // Anyone can disconnect their own Claude; an admin can disconnect anyone's.
    Route::delete('/settings/claude-access/authorizations/{user}/{client}', [ClaudeAccessController::class, 'revoke'])
        ->name('settings.claude-access.revoke');

    Route::middleware('admin')->group(function () {
        Route::patch('/settings/claude-access', [ClaudeAccessController::class, 'update'])->name('settings.claude-access.update');
        Route::post('/settings/claude-access/setup', [ClaudeAccessController::class, 'setup'])->name('settings.claude-access.setup');
        Route::post('/settings/claude-access/rotate-secret', [ClaudeAccessController::class, 'rotateSecret'])->name('settings.claude-access.rotate-secret');
        Route::delete('/settings/claude-access/authorizations', [ClaudeAccessController::class, 'revokeAll'])->name('settings.claude-access.revoke-all');
    });
});
