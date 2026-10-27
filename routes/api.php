<?php

use App\Http\Controllers\Reader\DeviceRegistrationController;
use App\Http\Controllers\Reader\NumberBlockReleaseController;
use App\Http\Controllers\Reader\ReadingPhotoController;
use App\Http\Controllers\Reader\RoutePackageController;
use App\Http\Controllers\Reader\StatusController;
use App\Http\Controllers\Reader\SyncBatchController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

// The meter reader app (App\Support\Reader). Devices sign in with their own token.
Route::prefix('reader/v1')->name('reader.')->group(function () {
    Route::post('/devices/register', DeviceRegistrationController::class)
        ->middleware('throttle:5,1')
        ->name('devices.register');

    // Also open to a signed-out device, so it can still upload the readings it took; they are held for review.
    Route::middleware([AuthenticateDevice::class.':uploads', 'throttle:120,1'])->group(function () {
        Route::get('/status', StatusController::class)->name('status');

        Route::post('/sync/batches', [SyncBatchController::class, 'store'])->name('sync.store');
        Route::put('/sync/batches/{batch}/items', [SyncBatchController::class, 'items'])->whereNumber('batch')->name('sync.items');
        Route::post('/sync/batches/{batch}/commit', [SyncBatchController::class, 'commit'])->whereNumber('batch')->name('sync.commit');

        Route::post('/readings/{clientUuid}/photo', ReadingPhotoController::class)->whereUuid('clientUuid')->name('readings.photo');
    });

    Route::middleware([AuthenticateDevice::class, 'throttle:120,1'])->group(function () {
        Route::post('/packages', [RoutePackageController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}/changes', [RoutePackageController::class, 'changes'])->whereNumber('package')->name('packages.changes');

        Route::post('/blocks/release', NumberBlockReleaseController::class)->name('blocks.release');

        Route::get('/sync/results', [SyncBatchController::class, 'results'])->name('sync.results');
    });
});
