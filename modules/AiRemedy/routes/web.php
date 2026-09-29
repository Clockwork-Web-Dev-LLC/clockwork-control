<?php

use Illuminate\Support\Facades\Route;
use Modules\AiRemedy\Http\Controllers\AiRemedyController;
use Modules\AiRemedy\Http\Controllers\AiRemedySettingsController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/ai-remedy', [AiRemedyController::class, 'index'])->name('ai-remedy.index');
    Route::get('/ai-remedy/settings', [AiRemedySettingsController::class, 'index'])->name('ai-remedy.settings');
    Route::post('/ai-remedy/settings', [AiRemedySettingsController::class, 'update'])->name('ai-remedy.settings.update');
    Route::post('/ai-remedy/test-connection', [AiRemedyController::class, 'testConnection'])->name('ai-remedy.test-connection');
    Route::get('/ai-remedy/runs/{run}', [AiRemedyController::class, 'show'])->name('ai-remedy.show');
    Route::post('/ai-remedy/servers/{server}/diagnose', [AiRemedyController::class, 'diagnoseServer'])->name('ai-remedy.server.diagnose');
    Route::post('/ai-remedy/runs/{run}/execute', [AiRemedyController::class, 'execute'])->name('ai-remedy.execute');
});
