<?php

use App\Http\Controllers\Clinical\ClientClinicalController;
use App\Http\Controllers\Clinical\HealthClinicalDashboardController;
use App\Http\Controllers\Clinical\ShiftClinicalController;
use App\Http\Controllers\HealthClinical\HealthClinicalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health & Clinical Routes
|--------------------------------------------------------------------------
|
| Client-scoped observation/event recording, shift-scoped clinical
| integration, and Health & Clinical module dashboard.
*/

Route::middleware(['auth'])->group(function () {

    // ── Health & Clinical module pages ─────────────────────────────────
    Route::prefix('health-clinical')->name('health-clinical.')->middleware('permission:clinical.dashboard')->group(function () {
        Route::get('/', [HealthClinicalDashboardController::class, 'index'])->name('dashboard');
    });

    // ── Cross-client registers ───────────────────────────────────────
    Route::prefix('health-clinical')->name('health-clinical.')->group(function () {
        Route::get('/observations', [HealthClinicalDashboardController::class, 'observations'])
            ->middleware('permission:clinical.observations.viewAny')
            ->name('observations.index');
        Route::post('/observations', [HealthClinicalController::class, 'storeObservation'])
            ->middleware('permission:clinical.observations.record')
            ->name('observations.store');
        Route::get('/events', [HealthClinicalController::class, 'events'])
            ->middleware('permission:clinical.events.view')
            ->name('events.index');
        Route::post('/events', [HealthClinicalController::class, 'storeEvent'])
            ->middleware('permission:clinical.events.record')
            ->name('events.store');
        Route::get('/protocols', [HealthClinicalController::class, 'protocols'])
            ->middleware('permission:clinical.observations.view')
            ->name('protocols.index');
        Route::post('/protocols', [HealthClinicalController::class, 'storeProtocol'])
            ->middleware('permission:clinical.protocols.manage')
            ->name('protocols.store');
        Route::put('/protocols/{protocol}', [HealthClinicalController::class, 'updateProtocol'])
            ->middleware('permission:clinical.protocols.manage')
            ->name('protocols.update');
        Route::get('/clients/{client}/summary', [HealthClinicalController::class, 'clientSummary'])
            ->middleware('permission:clinical.observations.view')
            ->name('clients.summary');
    });

    // ── Client-scoped clinical routes ────────────────────────────────────
    Route::prefix('clients/{client}/clinical')->name('clients.clinical.')->group(function () {
        Route::get('observations', [ClientClinicalController::class, 'observations'])->name('observations.index');
        Route::post('observations', [ClientClinicalController::class, 'store'])->name('observations.store');
    });

    // ── Shift-scoped clinical routes ───────────────────────────────────
    Route::prefix('shifts/{shift}/clinical')->name('shifts.clinical.')->group(function () {
        Route::get('observations/due', [ShiftClinicalController::class, 'dueObservations'])->name('observations.due');
        Route::post('observations', [ShiftClinicalController::class, 'store'])->name('observations.store');
    });

});
