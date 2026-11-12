<?php

use Illuminate\Support\Facades\Route;
use Modules\Seo\Http\Controllers\AiImpressionsController;
use Modules\Seo\Http\Controllers\DataForSeoSettingsController;
use Modules\Seo\Http\Controllers\GoogleConnectionController;
use Modules\Seo\Http\Controllers\SeoController;
use Modules\Seo\Http\Controllers\SeoSettingsController;
use Modules\Seo\Http\Controllers\SiteProfileController;
use Modules\Seo\Http\Controllers\SiteSearchController;

Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/seo', [SeoController::class, 'index'])->name('seo.index');

    Route::get('/seo/sites/{site}', [SiteSearchController::class, 'show'])->name('seo.sites.show');
    Route::get('/seo/sites/{site}/profile', [SiteProfileController::class, 'show'])->name('seo.sites.profile');
    Route::put('/seo/sites/{site}/profile', [SiteProfileController::class, 'update'])->name('seo.sites.profile.update');
    Route::post('/seo/sites/{site}/properties/refresh', [SiteProfileController::class, 'refreshProperties'])->middleware('throttle:6,1')->name('seo.sites.properties.refresh');
    Route::post('/seo/sites/{site}/sync', [SiteProfileController::class, 'sync'])->middleware('throttle:6,1')->name('seo.sites.sync');
    Route::post('/seo/sites/{site}/ai-impressions', [AiImpressionsController::class, 'store'])->name('seo.sites.ai-impressions.store');

    Route::middleware('admin')->group(function () {
        Route::get('/seo/settings', [SeoSettingsController::class, 'index'])->name('seo.settings');
        Route::post('/seo/settings', [SeoSettingsController::class, 'update'])->name('seo.settings.update');

        Route::get('/seo/google/connect', [GoogleConnectionController::class, 'connect'])->name('seo.google.connect');
        Route::get('/seo/google/callback', [GoogleConnectionController::class, 'callback'])->name('seo.google.callback');
        Route::post('/seo/google/disconnect', [GoogleConnectionController::class, 'disconnect'])->name('seo.google.disconnect');
        Route::post('/seo/google/test', [GoogleConnectionController::class, 'test'])->middleware('throttle:10,1')->name('seo.google.test');

        Route::put('/seo/dataforseo/budget', [DataForSeoSettingsController::class, 'updateBudget'])->name('seo.dataforseo.budget');
        Route::post('/seo/dataforseo/test', [DataForSeoSettingsController::class, 'test'])->middleware('throttle:10,1')->name('seo.dataforseo.test');
    });
});
