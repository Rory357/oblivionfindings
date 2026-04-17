<?php

use App\Http\Controllers\Emar\AuditLogController;
use App\Http\Controllers\Emar\CDLossReportController;
use App\Http\Controllers\Emar\EmarController;
use App\Http\Controllers\Emar\EmarPdfController;
use App\Http\Controllers\Emar\GuidedRoundController;
use App\Http\Controllers\Emar\EmarReportController;
use App\Http\Controllers\Emar\MedicationErrorController;
use App\Http\Controllers\Emar\RefusalFollowUpController;
use App\Http\Controllers\Emar\WorkerMedsController;
use App\Http\Controllers\BreakGlassController;
use App\Http\Controllers\EmergencyAccessController;
use App\Http\Controllers\MedicationAuditController;
use App\Http\Controllers\MedicationAdministrationCorrectionController;
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

// Worker-facing medication home (PR 12). Deliberately lives off-prefix so
// frontline staff can be routed to `/meds/today` without the admin-heavy
// `/emar` dashboard ever being their default destination. Gated by the
// administer/update permissions so support workers can load it, with manager
// permissions also allowed for oversight roles that want the operational view.
Route::middleware([
    'auth',
    'permission:medications.administer.record|clients.update|medications.orders.manage',
])->group(function () {
    Route::get('/meds/today', [WorkerMedsController::class, 'today'])
        ->name('meds.today');
});

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

    // Frontline Guided Round flow — worker-facing, gated by administer/record
    // rather than orders.manage so support workers can walk a round safely.
    Route::middleware('permission:medications.administer.record|clients.update|medications.orders.manage')->group(function () {
        Route::get('/rounds/{round}/guided', [GuidedRoundController::class, 'show'])
            ->name('meds.round.show');
        Route::post('/rounds/{round}/guided/items/{medication}', [GuidedRoundController::class, 'administer'])
            ->name('meds.round.administer');
        Route::post('/rounds/{round}/guided/complete', [GuidedRoundController::class, 'complete'])
            ->name('meds.round.complete');
    });

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

    // ─── CRUD Routes (permission-gated) ─────────────────────

    Route::middleware('permission:medications.orders.manage')->group(function () {

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
    Route::post('/medications/import', [EmarController::class, 'importMedications'])->name('emar.medications.import');
    Route::put('/medications/{medication}', [EmarController::class, 'updateMedication'])->name('emar.medications.update');
    Route::post('/medications/{medication}/discontinue', [EmarController::class, 'discontinueMedication'])->name('emar.medications.discontinue');
    Route::post('/alerts/{alert}/dismiss', [EmarController::class, 'dismissAlert'])->name('emar.alerts.dismiss');

    // Controlled Drug Entries
    Route::post('/controlled/entries', [EmarController::class, 'storeCDEntry'])->name('emar.controlled.entries.store');
    Route::post('/controlled/balance-check', [EmarController::class, 'storeBalanceCheck'])->name('emar.controlled.balance_check.store');
    Route::post('/controlled/discrepancies/{discrepancy}/resolve', [EmarController::class, 'resolveDiscrepancy'])->name('emar.controlled.discrepancies.resolve');

    // Destructions
    Route::post('/destructions', [EmarController::class, 'storeDestruction'])->name('emar.destructions.store');
    Route::delete('/destructions/{destruction}', [EmarController::class, 'destroyDestruction'])->name('emar.destructions.destroy');

    // Pharmacy Orders + Stock
    Route::post('/stock/pharmacy-orders', [EmarController::class, 'storePharmacyOrder'])->name('emar.pharmacy_orders.store');
    Route::put('/stock/pharmacy-orders/{order}', [EmarController::class, 'updatePharmacyOrder'])->name('emar.pharmacy_orders.update');
    Route::post('/stock/pharmacy-orders/{order}/advance', [EmarController::class, 'advancePharmacyOrder'])->name('emar.pharmacy_orders.advance');
    Route::patch('/stock/{stock}', [EmarController::class, 'updateStockItem'])->name('emar.stock.update');
    Route::post('/stock/receive', [EmarController::class, 'receiveStock'])->name('emar.stock.receive');
    Route::post('/stock/adjust', [EmarController::class, 'adjustStock'])->name('emar.stock.adjust');

    // PRN Effectiveness
    Route::post('/prn/effectiveness', [EmarController::class, 'storePrnEffectiveness'])->name('emar.prn_effectiveness.store');

    }); // end medications.orders.manage middleware group

    // ─── End CRUD Routes ────────────────────────────────────

    // Shared Shift Handovers (medication-focused eMAR view)
    Route::post('/handovers', [EmarController::class, 'storeHandover'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny|clients.update')
        ->name('emar.handovers.store');
    Route::put('/handovers/{handover}', [EmarController::class, 'updateHandover'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny|clients.update')
        ->name('emar.handovers.update');
    Route::post('/handovers/{handover}/submit', [EmarController::class, 'submitHandover'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny|clients.update')
        ->name('emar.handovers.submit');
    Route::post('/handovers/{handover}/acknowledge', [EmarController::class, 'acknowledgeHandover'])
        ->middleware('permission:handovers.viewAny|shifts.update|shifts.viewAssigned|clients.update')
        ->name('emar.handovers.acknowledge');
    Route::delete('/handovers/{handover}', [EmarController::class, 'destroyHandover'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny|clients.update')
        ->name('emar.handovers.destroy');

    // Audit trail
    Route::get('/audit', [AuditLogController::class, 'index'])
        ->middleware('permission:medications.audit.view')
        ->name('emar.audit');
    Route::get('/audit/export', [MedicationAuditController::class, 'exportCsv'])
        ->middleware('permission:medications.reports.export')
        ->name('emar.audit.export');

    // Emergency access
    Route::get('/emergency-access', [EmergencyAccessController::class, 'index'])
        ->middleware('permission:medications.breakglass')
        ->name('emar.emergency_access');

    // Canonical break-glass revoke
    Route::delete('/clients/{client}/break-glass/{access}', [BreakGlassController::class, 'destroy'])
        ->middleware('permission:medications.breakglass|medications.audit.view')
        ->name('emar.clients.break_glass.destroy');

    // Correction approval workflow
    Route::post('/corrections/{correction}/approve', [MedicationAdministrationCorrectionController::class, 'approve'])
        ->middleware('permission:medications.administer.correct')
        ->name('emar.corrections.approve');
    Route::post('/corrections/{correction}/reject', [MedicationAdministrationCorrectionController::class, 'reject'])
        ->middleware('permission:medications.administer.correct')
        ->name('emar.corrections.reject');

    // Reports
    Route::middleware('permission:reports.viewAny')->group(function () {
        Route::get('/reports', [EmarReportController::class, 'index'])
            ->name('emar.reports');
        Route::get('/reports/export', [EmarReportController::class, 'export'])
            ->name('emar.reports.export');
        Route::get('/reports/export-mar', [MedicationsReportController::class, 'exportMarCsv'])
            ->name('emar.reports.export_mar');
        Route::get('/reports/export-controlled-discrepancies', [MedicationsReportController::class, 'exportDiscrepanciesCsv'])
            ->name('emar.reports.export_discrepancies');
    });

    // ─── Refusal & Withholding Follow-Up ────────────────────
    Route::post('/refusal-followups', [RefusalFollowUpController::class, 'store'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->name('emar.refusal_followups.store');
    Route::post('/refusal-followups/{followup}/complete', [RefusalFollowUpController::class, 'complete'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('emar.refusal_followups.complete');
    Route::post('/refusal-followups/{followup}/notify-gp', [RefusalFollowUpController::class, 'notifyGp'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('emar.refusal_followups.notify_gp');

    // ─── Controlled Drug Loss Reports ─────────────────────
    Route::get('/controlled/loss-reports', [CDLossReportController::class, 'index'])
        ->middleware('permission:medications.controlled.view|clients.update')
        ->name('emar.cd_loss.index');
    Route::post('/controlled/loss-reports', [CDLossReportController::class, 'store'])
        ->middleware('permission:medications.controlled.record|clients.update')
        ->name('emar.cd_loss.store');
    Route::post('/controlled/loss-reports/{report}/investigate', [CDLossReportController::class, 'investigate'])
        ->middleware('permission:medications.controlled.record|clients.update')
        ->name('emar.cd_loss.investigate');
    Route::post('/controlled/loss-reports/{report}/resolve', [CDLossReportController::class, 'resolve'])
        ->middleware('permission:medications.controlled.record|clients.update')
        ->name('emar.cd_loss.resolve');

    // ─── Medication Errors ──────────────────────────────────
    Route::get('/errors', [MedicationErrorController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('emar.errors');
    Route::post('/errors', [MedicationErrorController::class, 'store'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->name('emar.errors.store');
    Route::put('/errors/{error}', [MedicationErrorController::class, 'update'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('emar.errors.update');
    Route::post('/errors/{error}/review', [MedicationErrorController::class, 'review'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('emar.errors.review');
    Route::post('/errors/{error}/resolve', [MedicationErrorController::class, 'resolve'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('emar.errors.resolve');

    // ─── PDF Exports ─────────────────────────────────────────
    Route::middleware('permission:medications.reports.export|reports.viewAny')->group(function () {
        Route::get('/pdf/mar-chart', [EmarPdfController::class, 'marChart'])->name('emar.pdf.mar');
        Route::get('/pdf/controlled-register', [EmarPdfController::class, 'controlledDrugRegister'])->name('emar.pdf.cd_register');
        Route::get('/pdf/round-sheet', [EmarPdfController::class, 'roundSheet'])->name('emar.pdf.round_sheet');
    });
});
