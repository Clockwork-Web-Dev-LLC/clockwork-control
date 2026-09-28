<?php

use Illuminate\Support\Facades\Route;
use Modules\Feedback\Http\Controllers\FeedbackController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback.index');
    Route::get('/feedback/pins', [FeedbackController::class, 'pins'])->name('feedback.pins');
    Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');
    Route::get('/feedback/{feedback}/prompt', [FeedbackController::class, 'claudePrompt'])->name('feedback.prompt');
    Route::patch('/feedback/{feedback}', [FeedbackController::class, 'update'])->name('feedback.update');
    Route::post('/feedback/{feedback}/comments', [FeedbackController::class, 'storeComment'])->name('feedback.comments.store');
    Route::delete('/feedback/{feedback}', [FeedbackController::class, 'destroy'])->name('feedback.destroy');
});
