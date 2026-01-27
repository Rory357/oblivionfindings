<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MedicationsController;
use App\Http\Controllers\MedicationAuditController;
use App\Http\Controllers\MedicationsReportController;
use App\Http\Controllers\Compliance\ComplianceDashboardController;

/**
 * Medication Management Routes
 *
 * Handles central medications module, audit logs, and compliance.
 */

Route::middleware(['auth'])->group(function () {
    // Central medications module
    Route::get('/medications', [MedicationsController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('medications.index');

    // Medication audit log
    Route::get('/medications/audit', [MedicationAuditController::class, 'index'])
        ->middleware('permission:medications.audit.view')
        ->name('medications.audit.index');
    Route::get('/medications/audit/export', [MedicationAuditController::class, 'exportCsv'])
        ->middleware('permission:medications.reports.export')
        ->name('medications.audit.export');

    // Medication reports
    Route::middleware('permission:reports.viewAny')->group(function () {
        Route::get('/reports/medications', [MedicationsReportController::class, 'index'])
            ->name('reports.medications');
        Route::get('/reports/medications/export-mar', [MedicationsReportController::class, 'exportMarCsv'])
            ->name('reports.medications.export_mar');
        Route::get('/reports/medications/export-controlled-discrepancies', [MedicationsReportController::class, 'exportDiscrepanciesCsv'])
            ->name('reports.medications.export_discrepancies');
    });

    // Compliance dashboard
    Route::get('/compliance', [ComplianceDashboardController::class, 'index'])
        ->middleware('permission:compliance.view')
        ->name('compliance.index');
});
