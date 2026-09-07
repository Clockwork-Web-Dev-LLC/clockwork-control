<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientReports\Http\Controllers\ClientReportsController;

// Public client report link (accessible without login via unique secure token)
Route::middleware(['web'])->group(function () {
    Route::get('/reports/view/{token}', [ClientReportsController::class, 'publicShow'])->name('client-reports.public');
});

// Authenticated operator routes
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/client-reports', [ClientReportsController::class, 'index'])->name('client-reports.index');
    Route::post('/client-reports/generate', [ClientReportsController::class, 'generate'])->name('client-reports.generate');
    Route::get('/client-reports/{report}', [ClientReportsController::class, 'show'])->name('client-reports.show');
    Route::post('/client-reports/{report}/send', [ClientReportsController::class, 'send'])->name('client-reports.send');
});
