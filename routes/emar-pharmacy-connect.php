<?php

use App\Http\Controllers\Emar\MedicationPharmacyConnectionController;
use App\Http\Middleware\EnsureConnectedCareFeature as Feature;
use App\Http\Middleware\ShareConnectedCareSwitches;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'permission:medications.view', ShareConnectedCareSwitches::class])->group(function (): void {
    Route::get('/emar/connections', [MedicationPharmacyConnectionController::class, 'directory'])
        ->middleware(['verified', 'permission:medications.external.manage|medications.transfers.manage|medications.pharmacy.connect.manage|medications.catalogue.manage|medications.backups.manage|medications.orders.manage'])
        ->name('emar.connected_services');
    // D4: the bridge's own pages and sends run only while it is switched on
    // and its partner is configured.
    Route::get('/emar/pharmacy-connections', [MedicationPharmacyConnectionController::class, 'index'])
        ->middleware(['permission:medications.settings.manage|medications.audit.view|medications.pharmacy.connect.manage', Feature::class.':pharmacy_bridge'])->name('emar.pharmacy_connections.index');
    Route::middleware(['permission:medications.settings.manage', 'permission:medications.pharmacy.connect.manage', Feature::class.':pharmacy_bridge'])->group(function (): void {
        Route::post('/emar/pharmacy-connections', [MedicationPharmacyConnectionController::class, 'store'])->name('emar.pharmacy_connections.store');
        Route::put('/emar/pharmacy-connections/{connection}', [MedicationPharmacyConnectionController::class, 'update'])->whereNumber('connection')->name('emar.pharmacy_connections.update');
    });
    // The order card says whether the bridge runs (EA-172); it is never hidden by the switch.
    Route::get('/emar/stock/pharmacy-orders/{order}/connection', [MedicationPharmacyConnectionController::class, 'order'])->whereNumber('order')->name('emar.pharmacy_orders.connection');
    Route::middleware(['permission:medications.stock.update', 'permission:medications.pharmacy.send'])->prefix('/emar/stock/pharmacy-orders/{order}')->whereNumber('order')->group(function (): void {
        Route::post('/dispatch', [MedicationPharmacyConnectionController::class, 'dispatch'])->middleware(Feature::class.':pharmacy_bridge')->name('emar.pharmacy_orders.dispatch');
        Route::post('/dispatch/{dispatch}/retry', [MedicationPharmacyConnectionController::class, 'retry'])->whereNumber('dispatch')->middleware(Feature::class.':pharmacy_bridge')->name('emar.pharmacy_orders.dispatch.retry');
        // Cancel and resolve are local closures: staff can always settle a
        // dispatch by hand, even after the bridge is switched off.
        Route::post('/dispatch/{dispatch}/cancel', [MedicationPharmacyConnectionController::class, 'cancel'])->whereNumber('dispatch')->name('emar.pharmacy_orders.dispatch.cancel');
        Route::post('/dispatch/{dispatch}/resolve', [MedicationPharmacyConnectionController::class, 'resolve'])->whereNumber('dispatch')->name('emar.pharmacy_orders.dispatch.resolve');
    });
});

// Included from web routes, but supplier receipts are an authenticated API, not a browser/session workflow.
// The kill switch is applied inside acknowledge(): evidence is kept, orders never change (EA-137).
Route::post('/api/emar/pharmacy-connections/{connection}/acknowledgments', [MedicationPharmacyConnectionController::class, 'acknowledge'])
    ->withoutMiddleware('web')->middleware(['api', 'throttle:30,1'])->whereNumber('connection')->name('api.emar.pharmacy_connections.acknowledge');
