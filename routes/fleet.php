<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Fleet\FleetDashboardController;
use App\Http\Controllers\Fleet\FleetVehicleController;
use App\Http\Controllers\Fleet\FleetTripController;
use App\Http\Controllers\Fleet\FleetDriverSessionController;
use App\Http\Controllers\Fleet\FleetFuelController;
use App\Http\Controllers\Fleet\FleetReportController;
use App\Http\Controllers\Fleet\FleetMapUsageController;
use App\Http\Controllers\Fleet\FleetMapUsageDashboardController;

/**
 * Fleet Management Routes
 *
 * Vehicle tracking, trips, fuel logs, driver sessions, and reporting.
 */
Route::middleware(['auth'])->group(function () {
    // Legacy child/detail routes retained intentionally while `/fleet-assets/*`
    // remains the unified operator shell for fleet and asset workflows.
    // These routes still own trip playback, map usage capture, and report links.
    Route::middleware('permission:fleet.viewAny')->group(function () {
        // Dashboard
        Route::get('/fleet-management', FleetDashboardController::class)
            ->name('fleet.index');

        // Vehicles
        Route::get('/fleet/vehicles/{asset}', [FleetVehicleController::class, 'show'])
            ->whereNumber('asset')
            ->name('fleet.vehicles.show');

        // Trips
        Route::get('/fleet/trips/{trip}', [FleetTripController::class, 'show'])
            ->whereNumber('trip')
            ->name('fleet.trips.show');
        Route::get('/fleet/trips/{trip}/playback', [FleetTripController::class, 'playback'])
            ->whereNumber('trip')
            ->name('fleet.trips.playback');

        // Fuel logs (view)
        Route::get('/fleet/fuel', [FleetFuelController::class, 'index'])
            ->name('fleet.fuel.index');

        // Maps usage
        Route::get('/fleet-management/maps-usage', FleetMapUsageDashboardController::class)
            ->name('fleet.maps.usage-dashboard');
        Route::post('/fleet/maps/usage', [FleetMapUsageController::class, 'store'])
            ->name('fleet.maps.usage');
    });

    // Trip management
    Route::middleware('permission:fleet.trips.manage')->group(function () {
        Route::put('/fleet/trips/{trip}', [FleetTripController::class, 'update'])
            ->whereNumber('trip')
            ->name('fleet.trips.update');
        Route::post('/fleet/trips/{trip}/close', [FleetTripController::class, 'close'])
            ->whereNumber('trip')
            ->name('fleet.trips.close');
        Route::delete('/fleet/trips/{trip}', [FleetTripController::class, 'destroy'])
            ->whereNumber('trip')
            ->name('fleet.trips.destroy');
    });

    // Fuel management
    Route::middleware('permission:fleet.fuel.manage')->group(function () {
        Route::post('/fleet/vehicles/{asset}/fuel', [FleetFuelController::class, 'store'])
            ->whereNumber('asset')
            ->name('fleet.fuel.store');
        Route::put('/fleet/fuel/{fuelLog}', [FleetFuelController::class, 'update'])
            ->whereNumber('fuelLog')
            ->name('fleet.fuel.update');
        Route::delete('/fleet/fuel/{fuelLog}', [FleetFuelController::class, 'destroy'])
            ->whereNumber('fuelLog')
            ->name('fleet.fuel.destroy');
    });

    // Driver sessions
    Route::middleware('permission:fleet.driverSessions.manage')->group(function () {
        Route::post('/fleet/vehicles/{asset}/driver-sessions', [FleetDriverSessionController::class, 'store'])
            ->whereNumber('asset')
            ->name('fleet.driver-sessions.store');
        Route::post('/fleet/driver-sessions/{session}/end', [FleetDriverSessionController::class, 'end'])
            ->whereNumber('session')
            ->name('fleet.driver-sessions.end');
    });

    // Reports
    Route::middleware('permission:fleet.reports.view')->group(function () {
        Route::get('/fleet/reports', [FleetReportController::class, 'index'])
            ->name('fleet.reports.index');
        Route::get('/fleet/reports/export', [FleetReportController::class, 'export'])
            ->name('fleet.reports.export');
    });
});
