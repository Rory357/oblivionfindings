<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Fleet\FleetDashboardController;
use App\Http\Controllers\Fleet\FleetVehicleController;
use App\Http\Controllers\Fleet\FleetTripController;
use App\Http\Controllers\Fleet\FleetDriverSessionController;
use App\Http\Controllers\Fleet\FleetMapUsageController;
use App\Http\Controllers\Fleet\FleetMapUsageDashboardController;

/**
 * Fleet Management Routes
 */
Route::middleware(['auth'])->group(function () {
    Route::middleware('permission:fleet.viewAny')->group(function () {
        Route::get('/fleet-management', FleetDashboardController::class)
            ->name('fleet.index');
        Route::get('/fleet/vehicles/{asset}', [FleetVehicleController::class, 'show'])
            ->whereNumber('asset')
            ->name('fleet.vehicles.show');
        Route::get('/fleet/trips/{trip}', [FleetTripController::class, 'show'])
            ->whereNumber('trip')
            ->name('fleet.trips.show');
        Route::get('/fleet/trips/{trip}/playback', [FleetTripController::class, 'playback'])
            ->whereNumber('trip')
            ->name('fleet.trips.playback');
        Route::get('/fleet-management/maps-usage', FleetMapUsageDashboardController::class)
            ->name('fleet.maps.usage-dashboard');
        Route::post('/fleet/maps/usage', [FleetMapUsageController::class, 'store'])
            ->name('fleet.maps.usage');
    });

    Route::middleware('permission:fleet.driverSessions.manage')->group(function () {
        Route::post('/fleet/vehicles/{asset}/driver-sessions', [FleetDriverSessionController::class, 'store'])
            ->whereNumber('asset')
            ->name('fleet.driver-sessions.store');
        Route::post('/fleet/driver-sessions/{session}/end', [FleetDriverSessionController::class, 'end'])
            ->whereNumber('session')
            ->name('fleet.driver-sessions.end');
    });
});
