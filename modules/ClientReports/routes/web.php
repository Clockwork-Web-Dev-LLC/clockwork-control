<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientReports\Http\Controllers\ClientReportsController;
use Modules\ClientReports\Http\Controllers\ClientsOverviewController;
use Modules\ClientReports\Http\Controllers\ReportReviewController;
use Modules\ClientReports\Http\Controllers\SchedulesController;
use Modules\ClientReports\Http\Controllers\TemplatesController;

// Public client report link (accessible without login via unique secure token, rate limited against enumeration)
Route::middleware(['web', 'throttle:60,1'])->group(function () {
    Route::get('/reports/view/{token}', [ClientReportsController::class, 'publicShow'])->name('client-reports.public');
});

// Authenticated operator routes
Route::middleware(['web', 'auth', 'active'])->group(function () {
    // The module's landing page: clients with their care plans, schedules and report history.
    Route::get('/client-reports', [ClientsOverviewController::class, 'index'])->name('client-reports.index');
    Route::get('/client-reports/clients/{client}', [ClientsOverviewController::class, 'show'])
        ->where('client', 'unassigned|[0-9]+')
        ->name('client-reports.clients.show');

    Route::get('/client-reports/reports', [ClientReportsController::class, 'index'])->name('client-reports.reports');
    // Recompiles a batch of unsent scheduled reports. Never sends.
    Route::post('/client-reports/reports/recompile', [ClientReportsController::class, 'recompileMany'])->name('client-reports.recompile-many');
    Route::post('/client-reports/generate', [ClientReportsController::class, 'generate'])->name('client-reports.generate');
    // Templates CRUD
    Route::resource('/client-reports/templates', TemplatesController::class)
        ->except(['show'])
        ->names('client-reports.templates');

    // Schedules CRUD & Actions
    // One-click default schedules for care-plan sites that have none. Never send.
    Route::post('/client-reports/schedules/quick/{site}', [SchedulesController::class, 'quickStore'])
        ->name('client-reports.schedules.quick');
    Route::post('/client-reports/schedules/quick-all', [SchedulesController::class, 'quickStoreAll'])
        ->name('client-reports.schedules.quick-all');
    Route::resource('/client-reports/schedules', SchedulesController::class)
        ->except(['show'])
        ->names('client-reports.schedules');
    Route::patch('/client-reports/schedules/{schedule}/toggle', [SchedulesController::class, 'toggle'])
        ->name('client-reports.schedules.toggle');
    Route::post('/client-reports/schedules/{schedule}/send-now', [SchedulesController::class, 'sendNow'])
        ->name('client-reports.schedules.send-now');

    Route::get('/client-reports/{report}', [ClientReportsController::class, 'show'])->whereNumber('report')->name('client-reports.show');
    Route::get('/client-reports/{report}/email', [ClientReportsController::class, 'email'])->whereNumber('report')->name('client-reports.email');
    Route::post('/client-reports/{report}/send', [ClientReportsController::class, 'send'])->whereNumber('report')->name('client-reports.send');
    // Emails the client's exact email to the signed-in operator only. Changes nothing.
    Route::post('/client-reports/{report}/send-test', [ClientReportsController::class, 'sendTest'])->whereNumber('report')->name('client-reports.send-test');
    // Refreshes an unsent scheduled report from current data. Never sends.
    Route::post('/client-reports/{report}/recompile', [ClientReportsController::class, 'recompile'])->whereNumber('report')->name('client-reports.recompile');
    // Records that a held report's review-tier malware findings were checked. Never sends.
    Route::post('/client-reports/{report}/malware-reviewed', [ReportReviewController::class, 'markMalwareReviewed'])->whereNumber('report')->name('client-reports.malware-reviewed');
});
