<?php

use App\Http\Controllers\Api\MedicationsApiController;
use Illuminate\Support\Facades\Route;

/**
 * Medication Management API Routes
 * 
 * These routes provide the enhanced medication management system API endpoints.
 */

Route::middleware(['auth:sanctum'])->prefix('api/medications')->group(function () {
    // Dashboard
    Route::get('/dashboard/widgets', [MedicationsApiController::class, 'getDashboardWidgets'])
        ->middleware('permission:medications.view|clients.viewAny')
        ->name('api.medications.dashboard.widgets');

    // MAR
    Route::get('/clients/{client}/mar', [MedicationsApiController::class, 'getMar'])
        ->middleware('permission:medications.view|clients.viewAny|clients.viewAssigned')
        ->name('api.medications.mar.show');

    Route::post('/clients/{client}/medications/{medication}/administrations', [MedicationsApiController::class, 'recordAdministration'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->name('api.medications.administrations.record');

    Route::post('/clients/{client}/mar/administrations/{administration}/corrections', [MedicationsApiController::class, 'correctAdministration'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('api.medications.administrations.correct');

    // Safety checks
    Route::get('/clients/{client}/medications/{medication}/safety-check', [MedicationsApiController::class, 'safetyCheck'])
        ->middleware('permission:medications.view|clients.viewAny|clients.viewAssigned')
        ->name('api.medications.safety.check');

    Route::get('/clients/{client}/medications/{medication}/prn-history', [MedicationsApiController::class, 'getPrnHistory'])
        ->middleware('permission:medications.view|clients.viewAny|clients.viewAssigned')
        ->name('api.medications.prn.history');

    // Alerts
    Route::get('/alerts', [MedicationsApiController::class, 'getDashboardAlerts'])
        ->middleware('permission:medications.view|clients.viewAny')
        ->name('api.medications.alerts.index');

    Route::get('/clients/{client}/alerts', [MedicationsApiController::class, 'getDashboardAlerts'])
        ->middleware('permission:medications.view|clients.viewAny|clients.viewAssigned')
        ->name('api.medications.alerts.client');

    Route::post('/alerts/{alertId}/acknowledge', [MedicationsApiController::class, 'acknowledgeAlert'])
        ->middleware('permission:medications.view|clients.viewAny')
        ->name('api.medications.alerts.acknowledge');

    Route::post('/alerts/{alertId}/resolve', [MedicationsApiController::class, 'resolveAlert'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('api.medications.alerts.resolve');

    // Reports
    Route::get('/reports', [MedicationsApiController::class, 'getReports'])
        ->middleware('permission:medications.reports.export|reports.viewAny')
        ->name('api.medications.reports');

    Route::get('/reports/export', [MedicationsApiController::class, 'exportReportCsv'])
        ->middleware('permission:medications.reports.export|reports.viewAny')
        ->name('api.medications.reports.export');

    // Shift summary
    Route::get('/shifts/{shiftId}/medication-summary', [MedicationsApiController::class, 'getShiftSummary'])
        ->middleware('permission:medications.view|shifts.viewAny|shifts.viewAssigned')
        ->name('api.medications.shift.summary');

    // Allergies
    Route::get('/clients/{client}/allergies', [MedicationsApiController::class, 'getAllergies'])
        ->middleware('permission:medications.view|clients.viewAny|clients.viewAssigned')
        ->name('api.medications.allergies.index');

    Route::post('/clients/{client}/allergies', [MedicationsApiController::class, 'createAllergy'])
        ->middleware('permission:clients.update')
        ->name('api.medications.allergies.create');

    // Medication Version History
    Route::get('/clients/{client}/medications/{medication}/versions', [MedicationsApiController::class, 'getMedicationVersions'])
        ->middleware('permission:medications.view|clients.viewAny|clients.viewAssigned')
        ->name('api.medications.versions.index');

    // Scheduled Stock Counts
    Route::get('/clients/{client}/medications/{medication}/scheduled-counts', [MedicationsApiController::class, 'getScheduledStockCounts'])
        ->middleware('permission:medications.view|clients.viewAny|clients.viewAssigned')
        ->name('api.medications.scheduled_counts.index');

    Route::post('/clients/{client}/medications/{medication}/scheduled-counts', [MedicationsApiController::class, 'createScheduledStockCount'])
        ->middleware('permission:medications.stock.update|clients.update')
        ->name('api.medications.scheduled_counts.store');

    Route::post('/clients/{client}/scheduled-counts/{count}/complete', [MedicationsApiController::class, 'completeScheduledStockCount'])
        ->middleware('permission:medications.stock.update|clients.update')
        ->name('api.medications.scheduled_counts.complete');

    // Drug Interactions Admin
    Route::get('/interactions', [MedicationsApiController::class, 'getDrugInteractions'])
        ->middleware('permission:medications.view|clients.viewAny')
        ->name('api.medications.interactions.index');

    Route::post('/interactions', [MedicationsApiController::class, 'createDrugInteraction'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('api.medications.interactions.store');
});
