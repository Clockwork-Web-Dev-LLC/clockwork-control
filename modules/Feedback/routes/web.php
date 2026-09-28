<?php

use Illuminate\Support\Facades\Route;
use Modules\Feedback\Http\Controllers\FeedbackController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback.index');
    Route::get('/feedback/pins', [FeedbackController::class, 'pins'])->name('feedback.pins');
    Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');
    Route::get('/feedback/prompt/batch', [FeedbackController::class, 'batchPrompt'])->name('feedback.prompt.batch');
    Route::get('/feedback/prompt/download', [FeedbackController::class, 'downloadPrompt'])->name('feedback.prompt.download');
    Route::post('/feedback/prompt/mark-in-progress', [FeedbackController::class, 'markApprovedInProgress'])->name('feedback.prompt.mark-in-progress');
    Route::post('/feedback/prompt/mark-resolved', [FeedbackController::class, 'markApprovedResolved'])->name('feedback.prompt.mark-resolved');
    Route::get('/feedback/{feedback}/prompt', [FeedbackController::class, 'claudePrompt'])->name('feedback.prompt');
    Route::post('/feedback/{feedback}/approve', [FeedbackController::class, 'approve'])->name('feedback.approve');
    Route::patch('/feedback/{feedback}', [FeedbackController::class, 'update'])->name('feedback.update');
    Route::post('/feedback/{feedback}/comments', [FeedbackController::class, 'storeComment'])->name('feedback.comments.store');
    Route::delete('/feedback/{feedback}', [FeedbackController::class, 'destroy'])->name('feedback.destroy');
});
