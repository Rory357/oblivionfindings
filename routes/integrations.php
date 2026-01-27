<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UnifiController;

/**
 * Integration Routes
 *
 * Handles third-party integrations like UniFi.
 */

Route::middleware(['auth'])->group(function () {
    // UniFi Integration
    Route::get('/integrations/unifi', [UnifiController::class, 'index'])
        ->middleware('permission:unifi.manage')
        ->name('integrations.unifi.index');
    Route::post('/integrations/unifi/{site}', [UnifiController::class, 'upsert'])
        ->middleware('permission:unifi.manage')
        ->name('integrations.unifi.upsert');
    Route::post('/integrations/unifi/{site}/sync', [UnifiController::class, 'sync'])
        ->middleware('permission:unifi.manage')
        ->name('integrations.unifi.sync');

    // Fleet Management (placeholder)
    Route::middleware('permission:fleet.viewAny')->group(function () {
        Route::get('/fleet-management', fn() => inertia('fleet-management/index'))
            ->name('fleet.index');
    });

    // Workers module (placeholder)
    Route::middleware('permission:workers.viewAny')->group(function () {
        Route::get('/workers', fn() => inertia('workers/index'))->name('workers.index');
    });
});
