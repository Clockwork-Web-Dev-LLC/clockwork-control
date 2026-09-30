<?php

use Illuminate\Support\Facades\Route;
use Modules\AiRemedy\Http\Controllers\AiRemedyController;
use Modules\AiRemedy\Http\Controllers\AiRemedySettingsController;

Route::middleware(['web', 'auth', 'active'])->group(function () {
    // Read-only audit log & incident forensics accessible to all active authenticated team members
    Route::get('/ai-remedy', [AiRemedyController::class, 'index'])->name('ai-remedy.index');
    Route::get('/ai-remedy/runs/{run}', [AiRemedyController::class, 'show'])->name('ai-remedy.show');

    // Admin-only: settings, simulations, diagnoses, and server command execution
    Route::middleware('admin')->group(function () {
        Route::get('/ai-remedy/settings', [AiRemedySettingsController::class, 'index'])->name('ai-remedy.settings');
        Route::post('/ai-remedy/settings', [AiRemedySettingsController::class, 'update'])->name('ai-remedy.settings.update');
        Route::post('/ai-remedy/test-connection', [AiRemedyController::class, 'testConnection'])->middleware('throttle:10,1')->name('ai-remedy.test-connection');
        Route::post('/ai-remedy/simulate', [AiRemedyController::class, 'simulateServer'])->middleware('throttle:10,1')->name('ai-remedy.simulate');
        Route::post('/ai-remedy/servers/{server}/diagnose', [AiRemedyController::class, 'diagnoseServer'])->middleware('throttle:15,1')->name('ai-remedy.server.diagnose');
        Route::post('/ai-remedy/runs/{run}/execute', [AiRemedyController::class, 'execute'])->middleware('throttle:10,1')->name('ai-remedy.execute');
        Route::delete('/ai-remedy/runs', [AiRemedyController::class, 'destroyMany'])->name('ai-remedy.runs.destroy');
    });
});
