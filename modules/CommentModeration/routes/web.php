<?php

use Illuminate\Support\Facades\Route;
use Modules\CommentModeration\Http\Controllers\SiteCommentsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/sites/{site}/comments', [SiteCommentsController::class, 'index'])->name('sites.comments.index');
    Route::post('/sites/{site}/comments/moderate', [SiteCommentsController::class, 'moderate'])->name('sites.comments.moderate');
    Route::post('/sites/{site}/comments/cleanup', [SiteCommentsController::class, 'cleanup'])->name('sites.comments.cleanup');
});
