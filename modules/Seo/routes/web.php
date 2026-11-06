<?php

use Illuminate\Support\Facades\Route;
use Modules\Seo\Http\Controllers\GoogleConnectionController;
use Modules\Seo\Http\Controllers\SeoController;
use Modules\Seo\Http\Controllers\SeoSettingsController;

Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/seo', [SeoController::class, 'index'])->name('seo.index');

    Route::middleware('admin')->group(function () {
        Route::get('/seo/settings', [SeoSettingsController::class, 'index'])->name('seo.settings');
        Route::post('/seo/settings', [SeoSettingsController::class, 'update'])->name('seo.settings.update');

        Route::get('/seo/google/connect', [GoogleConnectionController::class, 'connect'])->name('seo.google.connect');
        Route::get('/seo/google/callback', [GoogleConnectionController::class, 'callback'])->name('seo.google.callback');
        Route::post('/seo/google/disconnect', [GoogleConnectionController::class, 'disconnect'])->name('seo.google.disconnect');
        Route::post('/seo/google/test', [GoogleConnectionController::class, 'test'])->middleware('throttle:10,1')->name('seo.google.test');
    });
});
