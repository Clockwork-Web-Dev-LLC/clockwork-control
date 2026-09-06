<?php

use Illuminate\Support\Facades\Route;
use Modules\BillCom\BillComSettingsController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/settings/bill-com', [BillComSettingsController::class, 'index'])->name('settings.bill-com.index');
    Route::post('/settings/bill-com/run-customer-sync', [BillComSettingsController::class, 'runCustomerSync'])->name('settings.bill-com.run-customer-sync');
    Route::post('/settings/bill-com/run-care-plan-sync', [BillComSettingsController::class, 'runCarePlanSync'])->name('settings.bill-com.run-care-plan-sync');
});
