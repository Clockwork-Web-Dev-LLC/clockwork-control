<?php

use Illuminate\Support\Facades\Route;
use Modules\CodeSnippets\Http\Controllers\CodeSnippetsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/snippets', [CodeSnippetsController::class, 'index'])->name('snippets.index');

    Route::middleware('admin')->group(function () {
        Route::post('/snippets', [CodeSnippetsController::class, 'store'])->name('snippets.store');
        Route::put('/snippets/{snippet}', [CodeSnippetsController::class, 'update'])->name('snippets.update');
        Route::delete('/snippets/{snippet}', [CodeSnippetsController::class, 'destroy'])->name('snippets.destroy');
        Route::post('/snippets/execute', [CodeSnippetsController::class, 'execute'])->name('snippets.execute');
    });
});
