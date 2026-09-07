<?php

use Illuminate\Support\Facades\Route;
use Modules\ClientManagement\Http\Controllers\ClientsController;

Route::middleware(['auth', 'verified'])->group(function () {
    // Clients management
    Route::resource('clients', ClientsController::class)->except(['create', 'edit']);
    Route::post('/clients/{client}/assign-site', [ClientsController::class, 'assignSite'])->name('clients.assign-site');
    Route::post('/clients/{client}/unassign-site/{site}', [ClientsController::class, 'unassignSite'])->name('clients.unassign-site');
});
