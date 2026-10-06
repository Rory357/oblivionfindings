<?php

use App\Http\Controllers\MedicationAdministrationCorrectionController;
use App\Http\Controllers\MedicationAuditController;
use App\Http\Controllers\MedicationsReportController;
use App\Support\EmarUrl;
use Illuminate\Support\Facades\Route;

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
    Route::get('/medications/audit', [\App\Http\Controllers\Emar\MedicationReportsController::class, 'redirect'])
        ->middleware([
            'permission:medications.view',
            'permission:medications.audit.view',
        ])
        ->name('medications.audit.index');
    Route::get('/medications/audit/export', [MedicationAuditController::class, 'exportCsv'])
        ->middleware(\App\Http\Middleware\MedicationExportGuard::class.':audit')
        ->middleware([
            'permission:medications.view',
            'permission:medications.audit.view',
            'permission:medications.audit.export',
        ])
        ->name('medications.audit.export');

    // Medication reports
    Route::middleware('permission:medications.reports.view')->group(function () {
        Route::get('/reports/medications', [\App\Http\Controllers\Emar\MedicationReportsController::class, 'redirect'])
            ->name('reports.medications');
        Route::get('/reports/medications/export-mar', [MedicationsReportController::class, 'exportMarCsv'])
            ->middleware(\App\Http\Middleware\MedicationExportGuard::class.':doses')
            ->middleware('permission:medications.reports.export')
            ->name('reports.medications.export_mar');
        Route::get('/reports/medications/export-controlled-discrepancies', [MedicationsReportController::class, 'exportDiscrepanciesCsv'])
            ->middleware(\App\Http\Middleware\MedicationExportGuard::class.':controlled')
            ->middleware(['permission:medications.controlled.view', 'permission:medications.reports.export'])
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
