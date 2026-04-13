<?php

use App\Http\Controllers\Clinical\ClientClinicalController;
use App\Http\Controllers\Clinical\HealthClinicalDashboardController;
use App\Http\Controllers\Clinical\ShiftClinicalController;
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
