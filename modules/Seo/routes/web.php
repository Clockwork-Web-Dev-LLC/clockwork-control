<?php

use Illuminate\Support\Facades\Route;
use Modules\Seo\Http\Controllers\SeoController;

Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/seo', [SeoController::class, 'index'])->name('seo.index');
});
