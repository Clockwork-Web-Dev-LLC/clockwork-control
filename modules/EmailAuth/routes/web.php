<?php

use Illuminate\Support\Facades\Route;
use Modules\EmailAuth\Http\Controllers\EmailAuthController;

Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/email-auth', [EmailAuthController::class, 'index'])->name('email-auth.index');
    Route::post('/email-auth/scan', [EmailAuthController::class, 'scanNow'])->name('email-auth.scan');
    Route::post('/email-auth/scan-all', [EmailAuthController::class, 'scanAll'])->name('email-auth.scan-all');
    Route::post('/email-auth/domains/{domain}/ignore', [EmailAuthController::class, 'toggleIgnore'])->name('email-auth.ignore');
    Route::post('/email-auth/domains/{domain}/selectors', [EmailAuthController::class, 'updateSelectors'])->name('email-auth.selectors');
    Route::delete('/email-auth/domains/{domain}', [EmailAuthController::class, 'destroy'])->name('email-auth.destroy');
});
