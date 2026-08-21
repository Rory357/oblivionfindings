<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MedicationAuditController;
use App\Http\Controllers\MedicationsReportController;
use App\Http\Controllers\MedicationAdministrationCorrectionController;
use App\Support\EmarUrl;

/**
 * Medication Management Routes
 *
 * Handles central medications module, audit logs, and compliance.
 */

Route::middleware(['auth'])->group(function () {
    // Central medications module - list view
    Route::get('/medications', function () {
        return redirect()->to(EmarUrl::daily());
    })
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
    Route::middleware([
        'permission:medications.view',
        'permission:medications.reports.export|reports.viewAny',
    ])->group(function () {
        Route::get('/reports/medications', [MedicationsReportController::class, 'index'])
            ->name('reports.medications');
        Route::get('/reports/medications/export-mar', [MedicationsReportController::class, 'exportMarCsv'])
            ->name('reports.medications.export_mar');
        Route::get('/reports/medications/export-controlled-discrepancies', [MedicationsReportController::class, 'exportDiscrepanciesCsv'])
            ->name('reports.medications.export_discrepancies');
    });

    // Medication correction approval workflow
    Route::post('/medications/corrections/{correction}/approve', [MedicationAdministrationCorrectionController::class, 'approve'])
        ->middleware('permission:medications.administer.correct')
        ->name('medications.corrections.approve');
    Route::post('/medications/corrections/{correction}/reject', [MedicationAdministrationCorrectionController::class, 'reject'])
        ->middleware('permission:medications.administer.correct')
        ->name('medications.corrections.reject');

    // NB: the /compliance command-centre route now lives in routes/compliance.php.
});
