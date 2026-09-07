<?php

use Illuminate\Support\Facades\Route;
use Modules\SiteMaintenance\Http\Controllers\SiteMaintenanceController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/sites/{site}/maintenance-mode', [SiteMaintenanceController::class, 'show'])->name('sites.maintenance-mode.show');
    Route::post('/sites/{site}/maintenance-mode', [SiteMaintenanceController::class, 'update'])->name('sites.maintenance-mode.update');
});
