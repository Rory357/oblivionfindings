<?php

use App\Http\Controllers\Clinical\BehaviourAbcController;
use App\Http\Controllers\Clinical\ClientClinicalController;
use App\Http\Controllers\Clinical\HealthClinicalClientTrendsController;
use App\Http\Controllers\Clinical\HealthClinicalDashboardController;
use App\Http\Controllers\Clinical\HealthClinicalProtocolController;
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
        Route::post('/observations', [HealthClinicalDashboardController::class, 'storeObservation'])
            ->middleware('permission:clinical.observations.record')
            ->name('observations.store');
        Route::get('/events', [HealthClinicalDashboardController::class, 'events'])
            ->middleware('permission:clinical.events.viewAny')
            ->name('events.index');
        Route::post('/events', [HealthClinicalDashboardController::class, 'storeEvent'])
            ->middleware('permission:clinical.events.record')
            ->name('events.store');
        Route::patch('/events/{event}/review', [HealthClinicalDashboardController::class, 'reviewEvent'])
            ->middleware('permission:clinical.events.review')
            ->name('events.review');
        Route::patch('/events/{event}/follow-up/complete', [HealthClinicalDashboardController::class, 'completeEventFollowup'])
            ->middleware('permission:clinical.events.review|clinical.events.record')
            ->name('events.followup.complete');
        Route::post('/events/{event}/escalate', [HealthClinicalDashboardController::class, 'escalateEvent'])
            ->middleware('permission:clinical.events.escalate')
            ->name('events.escalate');
        Route::get('/behaviour', [HealthClinicalDashboardController::class, 'behaviour'])
            ->middleware('permission:clinical.behaviour.viewAny')
            ->name('behaviour.index');
        Route::get('/protocols', [HealthClinicalProtocolController::class, 'index'])
            ->middleware('permission:clinical.protocols.viewAny|clinical.protocols.manage')
            ->name('protocols.index');
        Route::get('/protocols/create', [HealthClinicalProtocolController::class, 'create'])
            ->middleware('permission:clinical.protocols.manage')
            ->name('protocols.create');
        Route::post('/protocols', [HealthClinicalProtocolController::class, 'store'])
            ->middleware('permission:clinical.protocols.manage')
            ->name('protocols.store');
        Route::get('/protocols/{protocol}/edit', [HealthClinicalProtocolController::class, 'edit'])
            ->middleware('permission:clinical.protocols.manage')
            ->name('protocols.edit');
        Route::put('/protocols/{protocol}', [HealthClinicalProtocolController::class, 'update'])
            ->middleware('permission:clinical.protocols.manage')
            ->name('protocols.update');
        Route::patch('/protocols/{protocol}/toggle-active', [HealthClinicalProtocolController::class, 'toggleActive'])
            ->middleware('permission:clinical.protocols.manage')
            ->name('protocols.toggle-active');
        // Record-wizard support (debounced client search + the live clinical card).
        Route::get('/clients/search', [HealthClinicalDashboardController::class, 'clientSearch'])
            ->name('clients.search');
        Route::get('/clients/{client}/clinical-card', [HealthClinicalDashboardController::class, 'clinicalCard'])
            ->name('clients.clinical-card');
        Route::get('/clients/{client}/trends', [HealthClinicalClientTrendsController::class, 'show'])
            ->middleware('permission:clinical.observations.viewAny|clinical.observations.viewAssigned')
            ->name('clients.trends');
        Route::get('/clients/{client}/summary', [HealthClinicalController::class, 'clientSummary'])
            ->middleware('permission:clinical.observations.viewAny|clinical.observations.viewAssigned')
            ->name('clients.summary');
    });

    // ── Client-scoped clinical routes ────────────────────────────────────
    Route::prefix('clients/{client}/clinical')->name('clients.clinical.')->group(function () {
        Route::get('observations', [ClientClinicalController::class, 'observations'])->name('observations.index');
        Route::post('observations', [ClientClinicalController::class, 'store'])->name('observations.store');
        Route::post('events', [ClientClinicalController::class, 'storeEvent'])->name('events.store');
    });

    // ── Client-scoped Behaviour / ABC charting ────────────────────────────
    Route::prefix('clients/{client}/behaviour/abc')->name('clients.behaviour.abc.')->group(function () {
        Route::get('/', [BehaviourAbcController::class, 'index'])->name('index');
        Route::post('/', [BehaviourAbcController::class, 'store'])->name('store');
        Route::get('/{abc}', [BehaviourAbcController::class, 'show'])->name('show');
        Route::put('/{abc}', [BehaviourAbcController::class, 'update'])->name('update');
        Route::delete('/{abc}', [BehaviourAbcController::class, 'destroy'])->name('destroy');
    });

    // ── Shift-scoped clinical routes ───────────────────────────────────
    Route::prefix('shifts/{shift}/clinical')->name('shifts.clinical.')->group(function () {
        Route::get('observations/due', [ShiftClinicalController::class, 'dueObservations'])->name('observations.due');
        Route::post('observations', [ShiftClinicalController::class, 'store'])->name('observations.store');
        Route::post('events', [ShiftClinicalController::class, 'storeEvent'])->name('events.store');
    });

});
