<?php

use App\Http\Controllers\InstallerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:30,1'])->prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallerController::class, 'welcome'])->name('welcome');

    Route::get('/database', [InstallerController::class, 'database'])->name('database');
    Route::post('/database/test', [InstallerController::class, 'testDatabase'])->name('database.test');
    Route::post('/database', [InstallerController::class, 'saveDatabase'])->name('database.save');

    Route::get('/app', [InstallerController::class, 'app'])->name('app');
    Route::post('/app', [InstallerController::class, 'saveApp'])->name('app.save');

    Route::get('/mail', [InstallerController::class, 'mail'])->name('mail');
    Route::post('/mail/test', [InstallerController::class, 'testMail'])->name('mail.test');
    Route::post('/mail', [InstallerController::class, 'saveMail'])->name('mail.save');
    Route::post('/mail/skip', [InstallerController::class, 'skipMail'])->name('mail.skip');

    Route::get('/google', [InstallerController::class, 'google'])->name('google');
    Route::post('/google', [InstallerController::class, 'saveGoogle'])->name('google.save');
    Route::post('/google/skip', [InstallerController::class, 'skipGoogle'])->name('google.skip');

    Route::get('/admin', [InstallerController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallerController::class, 'saveAdmin'])->name('admin.save');

    Route::get('/hosting', [InstallerController::class, 'hosting'])->name('hosting');
    Route::post('/hosting', [InstallerController::class, 'saveHosting'])->name('hosting.save');
    Route::post('/hosting/skip', [InstallerController::class, 'skipHosting'])->name('hosting.skip');

    Route::get('/vps', [InstallerController::class, 'vps'])->name('vps');
    Route::post('/vps', [InstallerController::class, 'saveVps'])->name('vps.save');
    Route::post('/vps/skip', [InstallerController::class, 'skipVps'])->name('vps.skip');

    Route::post('/unlock', [InstallerController::class, 'unlockExisting'])->name('unlock');

    Route::get('/review', [InstallerController::class, 'review'])->name('review');
    Route::post('/run', [InstallerController::class, 'install'])->name('run');

    Route::get('/done', [InstallerController::class, 'done'])->name('done');
});
