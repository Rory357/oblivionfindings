<?php

use App\Http\Controllers\Emar\EmarController;
use App\Http\Controllers\EmergencyAccessController;
use App\Http\Controllers\MedicationAuditController;
use App\Http\Controllers\MedicationsController;
use App\Http\Controllers\MedicationsReportController;
use Illuminate\Support\Facades\Route;

/**
 * eMAR (Electronic Medication Administration Record) Routes
 *
 * Comprehensive eMAR system for NZ residential care / supported living.
 * Covers medication administration, controlled drugs, prescriber orders,
 * reviews, competency, stock, pharmacy, rounds, and compliance.
 */

Route::middleware(['auth'])->prefix('emar')->group(function () {
    // Dashboard
    Route::get('/', function () {
        return \Inertia\Inertia::render('emar/Index');
    })->middleware('permission:medications.view')->name('emar.index');

    // Daily overview
    Route::get('/daily', [MedicationsController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('emar.daily');

    // MAR Charts
    Route::get('/mar', [EmarController::class, 'mar'])
        ->middleware('permission:medications.view')
        ->name('emar.mar');

    // PRN Records
    Route::get('/prn', [EmarController::class, 'prn'])
        ->middleware('permission:medications.view')
        ->name('emar.prn');

    // Controlled Drugs
    Route::get('/controlled', [EmarController::class, 'controlled'])
        ->middleware('permission:medications.view')
        ->name('emar.controlled');

    // Medications Database
    Route::get('/medications', [EmarController::class, 'medications'])
        ->middleware('permission:medications.view')
        ->name('emar.medications');

    // Stock Management
    Route::get('/stock', [EmarController::class, 'stock'])
        ->middleware('permission:medications.view')
        ->name('emar.stock');

    // Prescriptions & Prescriber Orders
    Route::get('/prescriptions', [EmarController::class, 'prescriptions'])
        ->middleware('permission:medications.view')
        ->name('emar.prescriptions');

    // Competency Assessments
    Route::get('/competency', [EmarController::class, 'competency'])
        ->middleware('permission:medications.view')
        ->name('emar.competency');

    // Medication Reviews
    Route::get('/reviews', [EmarController::class, 'reviews'])
        ->middleware('permission:medications.view')
        ->name('emar.reviews');

    // Medication Rounds
    Route::get('/rounds', [EmarController::class, 'rounds'])
        ->middleware('permission:medications.view')
        ->name('emar.rounds');

    // Self-Administration Assessments
    Route::get('/self-admin', [EmarController::class, 'selfAdmin'])
        ->middleware('permission:medications.view')
        ->name('emar.self_admin');

    // Destruction / Disposal Records
    Route::get('/destructions', [EmarController::class, 'destructions'])
        ->middleware('permission:medications.view')
        ->name('emar.destructions');

    // Medication Handovers
    Route::get('/handovers', [EmarController::class, 'handovers'])
        ->middleware('permission:medications.view')
        ->name('emar.handovers');

    // Audit trail
    Route::get('/audit', [MedicationAuditController::class, 'index'])
        ->middleware('permission:medications.audit.view')
        ->name('emar.audit');
    Route::get('/audit/export', [MedicationAuditController::class, 'exportCsv'])
        ->middleware('permission:medications.reports.export')
        ->name('emar.audit.export');

    // Emergency access
    Route::get('/emergency-access', [EmergencyAccessController::class, 'index'])
        ->middleware('permission:medications.breakGlass')
        ->name('emar.emergency_access');

    // Reports
    Route::middleware('permission:reports.viewAny')->group(function () {
        Route::get('/reports', [MedicationsReportController::class, 'index'])
            ->name('emar.reports');
        Route::get('/reports/export-mar', [MedicationsReportController::class, 'exportMarCsv'])
            ->name('emar.reports.export_mar');
        Route::get('/reports/export-controlled-discrepancies', [MedicationsReportController::class, 'exportDiscrepanciesCsv'])
            ->name('emar.reports.export_discrepancies');
    });
});
