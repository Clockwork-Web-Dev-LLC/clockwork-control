<?php

use Illuminate\Support\Facades\Route;
use Modules\Seo\Http\Controllers\AiImpressionsController;
use Modules\Seo\Http\Controllers\DataForSeoSettingsController;
use Modules\Seo\Http\Controllers\GoogleConnectionController;
use Modules\Seo\Http\Controllers\KeywordMapController;
use Modules\Seo\Http\Controllers\KeywordToolsController;
use Modules\Seo\Http\Controllers\PageAuditController;
use Modules\Seo\Http\Controllers\RankingsController;
use Modules\Seo\Http\Controllers\ResearchController;
use Modules\Seo\Http\Controllers\SeoClientsController;
use Modules\Seo\Http\Controllers\SeoController;
use Modules\Seo\Http\Controllers\SeoSettingsController;
use Modules\Seo\Http\Controllers\SiteProfileController;
use Modules\Seo\Http\Controllers\SiteSearchController;

Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/seo', [SeoController::class, 'index'])->name('seo.index');

    Route::get('/seo/clients', [SeoClientsController::class, 'index'])->name('seo.clients.index');
    Route::get('/seo/clients/new', [SeoClientsController::class, 'create'])->name('seo.clients.create');
    Route::post('/seo/clients', [SeoClientsController::class, 'store'])->middleware('throttle:20,1')->name('seo.clients.store');

    Route::get('/seo/sites/{site}', [SiteSearchController::class, 'show'])->name('seo.sites.show');
    Route::get('/seo/sites/{site}/profile', [SiteProfileController::class, 'show'])->name('seo.sites.profile');
    Route::put('/seo/sites/{site}/profile', [SiteProfileController::class, 'update'])->name('seo.sites.profile.update');
    Route::post('/seo/sites/{site}/properties/refresh', [SiteProfileController::class, 'refreshProperties'])->middleware('throttle:6,1')->name('seo.sites.properties.refresh');
    Route::post('/seo/sites/{site}/sync', [SiteProfileController::class, 'sync'])->middleware('throttle:6,1')->name('seo.sites.sync');
    Route::post('/seo/sites/{site}/ai-impressions', [AiImpressionsController::class, 'store'])->name('seo.sites.ai-impressions.store');

    Route::get('/seo/sites/{site}/keyword-map', [KeywordMapController::class, 'show'])->name('seo.sites.keyword-map');
    Route::post('/seo/sites/{site}/keyword-map/import', [KeywordMapController::class, 'import'])->middleware('throttle:10,1')->name('seo.sites.keyword-map.import');
    Route::post('/seo/sites/{site}/keyword-map/version', [KeywordMapController::class, 'newVersion'])->middleware('throttle:10,1')->name('seo.sites.keyword-map.version');
    Route::post('/seo/sites/{site}/keyword-map/fill', [KeywordMapController::class, 'fill'])->name('seo.sites.keyword-map.fill');
    Route::get('/seo/sites/{site}/keyword-map/rows/new', [KeywordMapController::class, 'createRow'])->name('seo.sites.keyword-map.rows.create');
    Route::post('/seo/sites/{site}/keyword-map/rows', [KeywordMapController::class, 'storeRow'])->name('seo.sites.keyword-map.rows.store');
    Route::get('/seo/sites/{site}/keyword-map/rows/{row}/edit', [KeywordMapController::class, 'editRow'])->name('seo.sites.keyword-map.rows.edit');
    Route::put('/seo/sites/{site}/keyword-map/rows/{row}', [KeywordMapController::class, 'updateRow'])->name('seo.sites.keyword-map.rows.update');
    Route::get('/seo/sites/{site}/keyword-map/{map}/download', [KeywordMapController::class, 'download'])->name('seo.sites.keyword-map.download');

    Route::get('/seo/sites/{site}/keywords', [KeywordToolsController::class, 'show'])->name('seo.sites.keywords');
    Route::post('/seo/sites/{site}/keywords/lookup', [KeywordToolsController::class, 'lookup'])->middleware('throttle:30,1')->name('seo.sites.keywords.lookup');
    Route::post('/seo/sites/{site}/keywords/buy', [KeywordToolsController::class, 'buy'])->middleware('throttle:10,1')->name('seo.sites.keywords.buy');
    Route::post('/seo/sites/{site}/keywords/ideas', [KeywordToolsController::class, 'ideas'])->middleware('throttle:10,1')->name('seo.sites.keywords.ideas');
    Route::get('/seo/sites/{site}/page-audit', [PageAuditController::class, 'show'])->name('seo.sites.page-audit');
    Route::post('/seo/sites/{site}/page-audit', [PageAuditController::class, 'run'])->middleware('throttle:12,1')->name('seo.sites.page-audit.run');

    Route::get('/seo/sites/{site}/research', [ResearchController::class, 'show'])->name('seo.sites.research');
    Route::get('/seo/sites/{site}/research/keywords', [ResearchController::class, 'keywords'])->name('seo.sites.research.keywords');
    Route::post('/seo/sites/{site}/research/buy', [ResearchController::class, 'buy'])->middleware('throttle:20,1')->name('seo.sites.research.buy');

    Route::get('/seo/sites/{site}/rankings', [RankingsController::class, 'show'])->name('seo.sites.rankings');
    Route::post('/seo/sites/{site}/rankings', [RankingsController::class, 'store'])->middleware('throttle:20,1')->name('seo.sites.rankings.store');
    Route::patch('/seo/sites/{site}/rankings/{keyword}', [RankingsController::class, 'toggle'])->name('seo.sites.rankings.toggle');
    Route::delete('/seo/sites/{site}/rankings/{keyword}', [RankingsController::class, 'destroy'])->name('seo.sites.rankings.destroy');
    Route::put('/seo/sites/{site}/competitors', [RankingsController::class, 'competitors'])->name('seo.sites.competitors');

    Route::middleware('admin')->group(function () {
        Route::put('/seo/rank-tracking', [DataForSeoSettingsController::class, 'updateRankTracking'])->name('seo.rank-tracking.update');
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
