<?php

use App\Http\Controllers\Fleet\FleetMapUsageDashboardController;
use App\Http\Controllers\Fleet\FleetTripController;
use Illuminate\Support\Facades\Route;

/**
 * Legacy Fleet Management Routes
 *
 * `/fleet-assets/*` (routes/fleet-assets.php) is the canonical fleet & asset
 * shell. The legacy GET pages that used to live here are permanently
 * redirected to their canonical equivalents. What remains live:
 *
 * - Trip write endpoints (update/close/destroy) — called by the trip
 *   playback page (`fleet-assets/trips/playback`).
 * - The map-usage dashboard — `fleet_map_usage_logs` is still written by
 *   the reverse-geocode pipeline (ReverseGeocodeService), so the read-only
 *   dashboard stays.
 */

// Permanent redirects from retired legacy pages to the canonical shell.
Route::permanentRedirect('/fleet-management', '/fleet-assets')->name('fleet.index');
Route::permanentRedirect('/fleet/fuel', '/fleet-assets/fuel')->name('fleet.fuel.index');
Route::permanentRedirect('/fleet/reports', '/fleet-assets/reports')->name('fleet.reports.index');
Route::get('/fleet/vehicles/{asset}', fn (int $asset) => redirect("/fleet-assets/vehicles/{$asset}", 301))
    ->whereNumber('asset')
    ->name('fleet.vehicles.show');
// The legacy trip page moved to the canonical shell as trip playback.
Route::get('/fleet/trips/{trip}', fn (int $trip) => redirect("/fleet-assets/trips/{$trip}/playback", 301))
    ->whereNumber('trip')
    ->name('fleet.trips.show');
// Old JSON playback endpoint → new data endpoint (fetch() follows redirects).
Route::get('/fleet/trips/{trip}/playback', fn (int $trip) => redirect("/fleet-assets/trips/{$trip}/playback/data", 301))
    ->whereNumber('trip')
    ->name('fleet.trips.playback');

Route::middleware(['auth'])->group(function () {
    // Maps usage dashboard (read-only; log rows come from reverse geocoding).
    Route::middleware('permission:fleet.viewAny')->group(function () {
        Route::get('/fleet-management/maps-usage', FleetMapUsageDashboardController::class)
            ->name('fleet.maps.usage-dashboard');
    });

    // Trip management — still called by the trip playback page.
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
});
