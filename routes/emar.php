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
    Route::get('/', [EmarController::class, 'dashboard'])
        ->middleware('permission:medications.view')
        ->name('emar.index');

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

    // ─── CRUD Routes ──────────────────────────────────────

    // Prescriber Orders
    Route::post('/prescriptions', [EmarController::class, 'storePrescription'])->name('emar.prescriptions.store');
    Route::put('/prescriptions/{order}', [EmarController::class, 'updatePrescription'])->name('emar.prescriptions.update');
    Route::post('/prescriptions/{order}/countersign', [EmarController::class, 'countersignPrescription'])->name('emar.prescriptions.countersign');
    Route::delete('/prescriptions/{order}', [EmarController::class, 'destroyPrescription'])->name('emar.prescriptions.destroy');

    // Covert Authorisations
    Route::post('/prescriptions/covert', [EmarController::class, 'storeCovert'])->name('emar.covert.store');
    Route::post('/prescriptions/covert/{authorisation}/revoke', [EmarController::class, 'revokeCovert'])->name('emar.covert.revoke');

    // Reviews
    Route::post('/reviews', [EmarController::class, 'storeReview'])->name('emar.reviews.store');
    Route::put('/reviews/{review}', [EmarController::class, 'updateReview'])->name('emar.reviews.update');
    Route::post('/reviews/{review}/complete', [EmarController::class, 'completeReview'])->name('emar.reviews.complete');
    Route::delete('/reviews/{review}', [EmarController::class, 'destroyReview'])->name('emar.reviews.destroy');

    // Competency Assessments
    Route::post('/competency', [EmarController::class, 'storeCompetency'])->name('emar.competency.store');
    Route::put('/competency/{assessment}', [EmarController::class, 'updateCompetency'])->name('emar.competency.update');
    Route::delete('/competency/{assessment}', [EmarController::class, 'destroyCompetency'])->name('emar.competency.destroy');

    // Round Templates + Workflow
    Route::post('/rounds/templates', [EmarController::class, 'storeRoundTemplate'])->name('emar.rounds.templates.store');
    Route::put('/rounds/templates/{template}', [EmarController::class, 'updateRoundTemplate'])->name('emar.rounds.templates.update');
    Route::delete('/rounds/templates/{template}', [EmarController::class, 'destroyRoundTemplate'])->name('emar.rounds.templates.destroy');
    Route::post('/rounds/generate', [EmarController::class, 'generateRounds'])->name('emar.rounds.generate');
    Route::post('/rounds/{round}/start', [EmarController::class, 'startRound'])->name('emar.rounds.start');
    Route::post('/rounds/{round}/complete', [EmarController::class, 'completeRound'])->name('emar.rounds.complete');
    Route::put('/rounds/{round}/assign', [EmarController::class, 'assignRound'])->name('emar.rounds.assign');

    // Self-Admin Assessments
    Route::post('/self-admin', [EmarController::class, 'storeSelfAdmin'])->name('emar.self_admin.store');
    Route::put('/self-admin/{assessment}', [EmarController::class, 'updateSelfAdmin'])->name('emar.self_admin.update');
    Route::delete('/self-admin/{assessment}', [EmarController::class, 'destroySelfAdmin'])->name('emar.self_admin.destroy');

    // Medications CRUD
    Route::post('/medications', [EmarController::class, 'storeMedication'])->name('emar.medications.store');
    Route::put('/medications/{medication}', [EmarController::class, 'updateMedication'])->name('emar.medications.update');
    Route::post('/medications/{medication}/discontinue', [EmarController::class, 'discontinueMedication'])->name('emar.medications.discontinue');

    // Controlled Drug Entries
    Route::post('/controlled/entries', [EmarController::class, 'storeCDEntry'])->name('emar.controlled.entries.store');
    Route::post('/controlled/balance-check', [EmarController::class, 'storeBalanceCheck'])->name('emar.controlled.balance_check.store');
    Route::post('/controlled/discrepancies/{discrepancy}/resolve', [EmarController::class, 'resolveDiscrepancy'])->name('emar.controlled.discrepancies.resolve');

    // Destructions
    Route::post('/destructions', [EmarController::class, 'storeDestruction'])->name('emar.destructions.store');
    Route::delete('/destructions/{destruction}', [EmarController::class, 'destroyDestruction'])->name('emar.destructions.destroy');

    // Handovers
    Route::post('/handovers', [EmarController::class, 'storeHandover'])->name('emar.handovers.store');
    Route::put('/handovers/{handover}', [EmarController::class, 'updateHandover'])->name('emar.handovers.update');
    Route::delete('/handovers/{handover}', [EmarController::class, 'destroyHandover'])->name('emar.handovers.destroy');
    Route::post('/handovers/{handover}/acknowledge', [EmarController::class, 'acknowledgeHandover'])->name('emar.handovers.acknowledge');

    // Pharmacy Orders + Stock
    Route::post('/stock/pharmacy-orders', [EmarController::class, 'storePharmacyOrder'])->name('emar.pharmacy_orders.store');
    Route::put('/stock/pharmacy-orders/{order}', [EmarController::class, 'updatePharmacyOrder'])->name('emar.pharmacy_orders.update');
    Route::post('/stock/pharmacy-orders/{order}/advance', [EmarController::class, 'advancePharmacyOrder'])->name('emar.pharmacy_orders.advance');
    Route::post('/stock/receive', [EmarController::class, 'receiveStock'])->name('emar.stock.receive');
    Route::post('/stock/adjust', [EmarController::class, 'adjustStock'])->name('emar.stock.adjust');

    // PRN Effectiveness
    Route::post('/prn/effectiveness', [EmarController::class, 'storePrnEffectiveness'])->name('emar.prn_effectiveness.store');

    // ─── End CRUD Routes ────────────────────────────────────

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
