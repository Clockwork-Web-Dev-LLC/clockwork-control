<?php

use Illuminate\Support\Facades\Route;
use Modules\EmailAuth\Http\Controllers\EmailAuthClientNoticeController;
use Modules\EmailAuth\Http\Controllers\EmailAuthController;

Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/email-auth', [EmailAuthController::class, 'index'])->name('email-auth.index');
    Route::post('/email-auth/scan', [EmailAuthController::class, 'scanNow'])->name('email-auth.scan');
    Route::post('/email-auth/scan-all', [EmailAuthController::class, 'scanAll'])->name('email-auth.scan-all');
    Route::post('/email-auth/domains/{domain}/ignore', [EmailAuthController::class, 'toggleIgnore'])->name('email-auth.ignore');
    Route::post('/email-auth/domains/{domain}/selectors', [EmailAuthController::class, 'updateSelectors'])->name('email-auth.selectors');
    Route::delete('/email-auth/domains/{domain}', [EmailAuthController::class, 'destroy'])->name('email-auth.destroy');
    Route::get('/email-auth/domains/{domain:domain}/notify', [EmailAuthClientNoticeController::class, 'create'])->name('email-auth.notify');
    Route::post('/email-auth/domains/{domain:domain}/notify/preview', [EmailAuthClientNoticeController::class, 'preview'])->name('email-auth.notify.preview');
    Route::post('/email-auth/domains/{domain:domain}/notify', [EmailAuthClientNoticeController::class, 'store'])->name('email-auth.notify.send');
});
