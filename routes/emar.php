<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MedicationsController;
use App\Http\Controllers\MedicationAuditController;
use App\Http\Controllers\MedicationsReportController;
use App\Http\Controllers\EmergencyAccessController;

/**
 * eMAR (Electronic Medication Administration Record) Routes
 *
 * Placeholder module - full eMAR will be built as a separate module.
 * Currently contains migrated medication management routes.
 */

Route::middleware(['auth'])->prefix('emar')->group(function () {
    // Dashboard (placeholder)
    Route::get('/', function () {
        return \Inertia\Inertia::render('emar/Index');
    })->middleware('permission:medications.view')->name('emar.index');

    // Daily overview (migrated from /medications)
    Route::get('/daily', [MedicationsController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('emar.daily');

    // Audit trail (migrated from /medications/audit)
    Route::get('/audit', [MedicationAuditController::class, 'index'])
        ->middleware('permission:medications.audit.view')
        ->name('emar.audit');
    Route::get('/audit/export', [MedicationAuditController::class, 'exportCsv'])
        ->middleware('permission:medications.reports.export')
        ->name('emar.audit.export');

    // Emergency access (migrated from /emergency-access)
    Route::get('/emergency-access', [EmergencyAccessController::class, 'index'])
        ->middleware('permission:medications.breakGlass')
        ->name('emar.emergency_access');

    // Reports (migrated from /reports/medications)
    Route::middleware('permission:reports.viewAny')->group(function () {
        Route::get('/reports', [MedicationsReportController::class, 'index'])
            ->name('emar.reports');
        Route::get('/reports/export-mar', [MedicationsReportController::class, 'exportMarCsv'])
            ->name('emar.reports.export_mar');
        Route::get('/reports/export-controlled-discrepancies', [MedicationsReportController::class, 'exportDiscrepanciesCsv'])
            ->name('emar.reports.export_discrepancies');
    });

    // Placeholder routes for future eMAR features
    // These will render placeholder pages
    Route::get('/mar', fn () => \Inertia\Inertia::render('emar/Placeholder', ['feature' => 'MAR Charts']))
        ->middleware('permission:medications.view')->name('emar.mar');
    Route::get('/prn', fn () => \Inertia\Inertia::render('emar/Placeholder', ['feature' => 'PRN Records']))
        ->middleware('permission:medications.view')->name('emar.prn');
    Route::get('/controlled', fn () => \Inertia\Inertia::render('emar/Placeholder', ['feature' => 'Controlled Drugs']))
        ->middleware('permission:medications.view')->name('emar.controlled');
    Route::get('/medications', fn () => \Inertia\Inertia::render('emar/Placeholder', ['feature' => 'Medications']))
        ->middleware('permission:medications.view')->name('emar.medications');
    Route::get('/stock', fn () => \Inertia\Inertia::render('emar/Placeholder', ['feature' => 'Stock Management']))
        ->middleware('permission:medications.view')->name('emar.stock');
    Route::get('/prescriptions', fn () => \Inertia\Inertia::render('emar/Placeholder', ['feature' => 'Prescriptions']))
        ->middleware('permission:medications.view')->name('emar.prescriptions');
    Route::get('/competency', fn () => \Inertia\Inertia::render('emar/Placeholder', ['feature' => 'Competency']))
        ->middleware('permission:medications.view')->name('emar.competency');
});
