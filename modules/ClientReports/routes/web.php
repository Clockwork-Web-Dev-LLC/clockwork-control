<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientReports\Http\Controllers\ClientReportsController;
use Modules\ClientReports\Http\Controllers\SchedulesController;
use Modules\ClientReports\Http\Controllers\TemplatesController;

// Public client report link (accessible without login via unique secure token)
Route::middleware(['web'])->group(function () {
    Route::get('/reports/view/{token}', [ClientReportsController::class, 'publicShow'])->name('client-reports.public');
});

// Authenticated operator routes
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/client-reports', [ClientReportsController::class, 'index'])->name('client-reports.index');
    Route::post('/client-reports/generate', [ClientReportsController::class, 'generate'])->name('client-reports.generate');
    // Templates CRUD
    Route::resource('/client-reports/templates', TemplatesController::class)
        ->except(['show'])
        ->names('client-reports.templates');

    // Schedules CRUD & Actions
    Route::resource('/client-reports/schedules', SchedulesController::class)
        ->except(['show'])
        ->names('client-reports.schedules');
    Route::patch('/client-reports/schedules/{schedule}/toggle', [SchedulesController::class, 'toggle'])
        ->name('client-reports.schedules.toggle');
    Route::post('/client-reports/schedules/{schedule}/send-now', [SchedulesController::class, 'sendNow'])
        ->name('client-reports.schedules.send-now');

    Route::get('/client-reports/{report}', [ClientReportsController::class, 'show'])->name('client-reports.show');
    Route::post('/client-reports/{report}/send', [ClientReportsController::class, 'send'])->name('client-reports.send');
});
