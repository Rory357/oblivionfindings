<?php

use App\Http\Controllers\BreakGlassController;
use App\Http\Controllers\Emar\AuditLogController;
use App\Http\Controllers\Emar\CDLossReportController;
use App\Http\Controllers\Emar\ClientMedicationDayController;
use App\Http\Controllers\Emar\ControlledProductController;
use App\Http\Controllers\Emar\CompetencyExemptionController;
use App\Http\Controllers\Emar\DoseRequirementsController;
use App\Http\Controllers\Emar\EmarController;
use App\Http\Controllers\Emar\PersonMedicationRecordController;
use App\Http\Controllers\Emar\PersonMedicationClinicalController;
use App\Http\Controllers\Emar\EmarPdfController;
use App\Http\Controllers\Emar\EmarReportController;
use App\Http\Controllers\Emar\GuidedRoundController;
use App\Http\Controllers\Emar\MedicationAuditEventController;
use App\Http\Controllers\Emar\MedicationErrorController;
use App\Http\Controllers\Emar\MedicationFollowupController;
use App\Http\Controllers\Emar\MedicationReviewController;
use App\Http\Controllers\Emar\MedicationOrdersController;
use App\Http\Controllers\Emar\MedicationSecondPersonConfirmationController;
use App\Http\Controllers\Emar\MedicationSettingsController;
use App\Http\Controllers\Emar\MedicationSupportController;
use App\Http\Controllers\Emar\RefusalFollowUpController;
use App\Http\Controllers\Emar\StaffEligibilityController;
use App\Http\Controllers\Emar\WorkerMedsController;
use App\Http\Controllers\EmergencyAccessController;
use App\Http\Controllers\MedicationAdministrationCorrectionController;
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

// Worker-facing medication home (PR 12). Deliberately lives off-prefix so
// frontline staff can be routed to `/meds/today` without the admin-heavy
// `/emar` dashboard ever being their default destination. Gated by the
// administer/update permissions so support workers can load it, with manager
// permissions also allowed for oversight roles that want the operational view.
Route::middleware(['auth'])->group(function () {
    Route::post('/medication-followups/administrations/{administration}/prepare', [MedicationFollowupController::class, 'prepare'])
        ->whereNumber('administration')->middleware('permission:medications.administer.record')->name('medication_followups.prepare');
    Route::get('/medication-followups', [MedicationFollowupController::class, 'index'])
        ->middleware('permission:medications.view')->name('medication_followups.index');
    Route::get('/medication-followups/{followup}', [MedicationFollowupController::class, 'show'])
        ->whereNumber('followup')->middleware('permission:medications.view')->name('medication_followups.show');
    Route::post('/medication-followups/{followup}/transition', [MedicationFollowupController::class, 'transition'])
        ->whereNumber('followup')->middleware('permission:medications.administer.record|medications.followups.manage')
        ->name('medication_followups.transition');

    // Own-login PIN-2 attestation, projected through the canonical follow-up.
    Route::get('/meds/confirmations/{confirmation}', [MedicationSecondPersonConfirmationController::class, 'show'])
        ->whereNumber('confirmation')->middleware('permission:medications.view')
        ->name('meds.confirmations.show');
    Route::post('/meds/confirmations/{confirmation}', [MedicationSecondPersonConfirmationController::class, 'respond'])
        ->whereNumber('confirmation')->middleware('permission:medications.view')->middleware('throttle:30,1')
        ->name('meds.confirmations.respond');

    Route::get('/meds/today', [WorkerMedsController::class, 'today'])
        ->middleware('permission:medications.view|medications.administer.record')
        ->name('meds.today');

    // PR 13 — PRN (as-needed) quick-entry flow. Delegates to the same
    // EnhancedMarService used everywhere else so audit/safety logic runs
    // untouched; this is just a fast, frontline-shaped surface for it.
    Route::post('/meds/today/prn', [WorkerMedsController::class, 'recordPrn'])
        ->middleware('permission:medications.administer.record')
        ->name('meds.today.prn');

    // Desktop medication board — scheduled-dose recording (Record Dose
    // wizard) and the PRN follow-up effect check. Both delegate to the same
    // services as the admin paths; no second administration pipeline.
    Route::post('/meds/today/record', [WorkerMedsController::class, 'recordDose'])
        ->middleware('permission:medications.administer.record')
        ->name('meds.today.record');
    Route::post('/meds/today/prn/effect', [WorkerMedsController::class, 'recordPrnEffect'])
        ->middleware('permission:medications.administer.record')
        ->name('meds.today.prn_effect');

    // eMAR P01 — what recording a dose needs and allows (the recording
    // dialog's safety checks, blocks and second-person candidates).
    Route::get('/meds/today/doses/requirements', [DoseRequirementsController::class, 'scheduled'])
        ->middleware('permission:medications.administer.record')
        ->name('meds.today.requirements');
    Route::get('/meds/today/prn/{medication}/requirements', [DoseRequirementsController::class, 'asNeeded'])
        ->whereNumber('medication')
        ->middleware('permission:medications.administer.record')
        ->name('meds.today.prn_requirements');
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
    Route::get('/controlled', [ControlledProductController::class, 'index'])
        ->middleware([
            'permission:medications.view',
            'permission:medications.controlled.view',
        ])
        ->name('emar.controlled');

    Route::middleware(['permission:medications.view', 'permission:medications.controlled.view'])->group(function () {
        Route::get('/controlled/product', [ControlledProductController::class, 'product'])->name('emar.controlled.product');
        Route::post('/controlled/product/actions/{action}', [ControlledProductController::class, 'action'])
            ->where('action', 'count|movement|void|resolve|loss_report|loss_note|loss_notify|loss_close|destruction|destruction_receipt|destruction_void|class_review|witness_request|witness_answer|witness_cancel|override_request|override_decide|override_signoff')
            ->name('emar.controlled.product.action');
        Route::get('/controlled/product/destructions/{destruction}/photo', [ControlledProductController::class, 'photo'])->name('emar.controlled.product.photo');
        Route::get('/safety/witness-overrides', [ControlledProductController::class, 'overrides'])->name('emar.safety.witness_overrides');
    });

    // Medications Database
    Route::get('/medications', [EmarController::class, 'medications'])
        ->middleware('permission:medications.view')
        ->name('emar.medications');

    // Lazy detail (stock-movement history + per-client interaction detail) for the register row modal.
    Route::get('/medications/{medication}/detail', [EmarController::class, 'medicationDetail'])
        ->middleware('permission:medications.view')
        ->name('emar.medications.detail');

    // Stock Management
    Route::get('/stock', [EmarController::class, 'stock'])
        ->middleware([
            'permission:medications.view',
            'permission:medications.stock.update',
        ])
        ->name('emar.stock');

    // Prescriptions & Prescriber Orders
    Route::get('/prescriptions', [MedicationOrdersController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('emar.prescriptions');

    // Preserve existing source records and dispensing capabilities while P06
    // integrates supply. New prescriptions use the single chart-order flow.
    Route::get('/prescriptions/legacy', [EmarController::class, 'prescriptions'])
        ->middleware('permission:medications.view')->name('emar.prescriptions.legacy');
    Route::get('/orders/{medication}', [MedicationOrdersController::class, 'detail'])
        ->whereNumber('medication')->middleware('permission:medications.view')->name('emar.orders.detail');
    Route::get('/orders/allergies/{client}', [MedicationOrdersController::class, 'allergyCheck'])
        ->whereNumber('client')->middleware('permission:medications.orders.manage')->name('emar.orders.allergies');
    Route::post('/orders', [MedicationOrdersController::class, 'enter'])
        ->middleware('permission:medications.orders.manage')->name('emar.orders.enter');
    Route::get('/orders/witnesses/{client}', [MedicationOrdersController::class, 'witnesses'])
        ->whereNumber('client')->middleware('permission:medications.orders.manage')->name('emar.orders.witnesses');
    Route::post('/order-revisions/{revision}/check', [MedicationOrdersController::class, 'check'])
        ->middleware('permission:medications.orders.verify')->name('emar.orders.check');
    Route::post('/order-revisions/{revision}/send-back', [MedicationOrdersController::class, 'sendBack'])
        ->middleware('permission:medications.orders.verify')->name('emar.orders.send-back');
    Route::post('/order-revisions/{revision}/allergy-confirmation', [MedicationOrdersController::class, 'confirmAllergy'])
        ->middleware('permission:medications.orders.manage')->name('emar.orders.allergy-confirmation');
    Route::post('/order-revisions/{revision}/written-confirmation', [MedicationOrdersController::class, 'confirmWritten'])
        ->middleware('permission:medications.orders.manage')->name('emar.orders.written-confirmation');
    Route::post('/orders/{medication}/stop', [MedicationOrdersController::class, 'stop'])
        ->middleware('permission:medications.orders.manage')->name('emar.orders.stop');
    Route::post('/orders/{medication}/hold', [MedicationOrdersController::class, 'hold'])
        ->middleware('permission:medications.orders.manage')->name('emar.orders.hold');
    Route::post('/orders/{medication}/resume', [MedicationOrdersController::class, 'resume'])
        ->middleware('permission:medications.orders.manage')->name('emar.orders.resume');
    Route::get('/order-files/{file}', [MedicationOrdersController::class, 'file'])
        ->middleware('permission:medications.view')->name('emar.orders.file');
    Route::post('/orders/{medication}/covert', [MedicationOrdersController::class, 'authoriseCovert'])
        ->middleware('permission:medications.orders.manage')->name('emar.orders.covert');
    Route::post('/order-covert/{authorisation}/revoke', [MedicationOrdersController::class, 'revokeCovert'])
        ->middleware('permission:medications.orders.manage')->name('emar.orders.covert-revoke');
    Route::post('/reconciliations', [MedicationOrdersController::class, 'startReconciliation'])
        ->middleware('permission:medications.orders.manage')->name('emar.reconciliations.start');
    Route::put('/reconciliations/{reconciliation}', [MedicationOrdersController::class, 'saveReconciliation'])
        ->middleware('permission:medications.orders.manage')->name('emar.reconciliations.save');
    Route::post('/reconciliations/{reconciliation}/apply', [MedicationOrdersController::class, 'applyReconciliation'])
        ->middleware('permission:medications.orders.manage')->name('emar.reconciliations.apply');
    Route::post('/reconciliations/{reconciliation}/sign-off', [MedicationOrdersController::class, 'signOffReconciliation'])
        ->middleware('permission:medications.orders.manage')->name('emar.reconciliations.sign-off');
    Route::post('/reconciliations/{reconciliation}/items/{item}/query', [MedicationOrdersController::class, 'resolveReconciliationQuery'])
        ->middleware('permission:medications.orders.verify')->name('emar.reconciliations.query');
    Route::get('/orders/candidates/{client}', [MedicationOrdersController::class, 'candidates'])
        ->middleware('permission:medications.view')->name('emar.orders.candidates');

    // P11 chunk 6: Safety & oversight › Staff eligibility replaces
    // Medication › Competency; old links land on it — a ?site_id only after
    // the same reader check as before (a foreign house is 404).
    Route::get('/safety/eligibility', [StaffEligibilityController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('emar.safety.eligibility');
    Route::get('/competency', [StaffEligibilityController::class, 'legacyCompetency'])
        ->middleware('permission:medications.view')
        ->name('emar.competency');

    // Medication Reviews
    Route::get('/reviews', [MedicationReviewController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('emar.reviews');

    Route::get('/reviews/pickers', [MedicationReviewController::class, 'pickers'])
        ->middleware('permission:medications.reviews.manage')->name('emar.reviews.pickers');
    Route::get('/reviews/{review}/source', [MedicationReviewController::class, 'source'])
        ->middleware('permission:medications.view')->name('emar.reviews.source');
    Route::get('/reviews/{review}/items/{item}/decision-source', [MedicationReviewController::class, 'source'])
        ->whereNumber('item')->middleware('permission:medications.view')->name('emar.reviews.decision_source');
    Route::middleware('permission:medications.reviews.manage')->group(function () {
        Route::post('/reviews', [MedicationReviewController::class, 'store'])->name('emar.reviews.store');
        Route::put('/reviews/{review}', [MedicationReviewController::class, 'update'])->name('emar.reviews.update');
        Route::put('/reviews/{review}/appointment', [MedicationReviewController::class, 'appointment'])->name('emar.reviews.appointment');
        Route::post('/reviews/{review}/complete', [MedicationReviewController::class, 'complete'])->name('emar.reviews.complete');
        Route::post('/reviews/{review}/actions/advance', [MedicationReviewController::class, 'advance'])->name('emar.reviews.actions.advance');
        Route::delete('/reviews/{review}', [MedicationReviewController::class, 'destroy'])->name('emar.reviews.destroy');
        Route::post('/reviews/{review}/items/{item}/decision', [MedicationReviewController::class, 'decision'])->whereNumber('item')->name('emar.reviews.decision');
        Route::post('/reviews/{review}/items/{item}/outcome', [MedicationReviewController::class, 'outcome'])->whereNumber('item')->name('emar.reviews.outcome');
        Route::put('/clients/{client}/review-interval', [MedicationReviewController::class, 'interval'])->name('emar.clients.review_interval');
    });

    Route::get('/clients/{client}/inr', [EmarController::class, 'inrHistory'])
        ->middleware('permission:medications.view')
        ->name('emar.clients.inr.index');

    // Medication Rounds
    Route::get('/rounds', [EmarController::class, 'rounds'])
        ->middleware('permission:medications.view|medications.administer.record')
        ->name('emar.rounds');

    // Frontline Guided Round flow — worker-facing, gated by administer/record
    // rather than orders.manage so support workers can walk a round safely.
    Route::middleware('permission:medications.administer.record')->group(function () {
        Route::get('/rounds/{round}/guided', [GuidedRoundController::class, 'show'])
            ->name('meds.round.show');
        Route::post('/rounds/{round}/guided/start', [GuidedRoundController::class, 'start'])
            ->name('meds.round.start');
        Route::post('/rounds/{round}/guided/items/{medication}', [GuidedRoundController::class, 'administer'])
            ->name('meds.round.administer');
        Route::post('/rounds/{round}/guided/complete', [GuidedRoundController::class, 'complete'])
            ->name('meds.round.complete');
    });

    // One person's medication day for the client profile's MAR tab (P02-1b);
    // the per-person gate runs in the controller (MedicationRecordAccess).
    Route::get('/clients/{client}/day', [ClientMedicationDayController::class, 'show'])
        ->whereNumber('client')
        ->middleware('permission:medications.view')
        ->name('emar.clients.day');

    Route::prefix('/clients/{client}/record')->whereNumber('client')->middleware('permission:medications.view')->group(function () {
        Route::get('/allergies', [\App\Http\Controllers\ClientAllergyRecordController::class, 'show'])->name('emar.record.allergies');
        Route::post('/allergies', [\App\Http\Controllers\ClientAllergyRecordController::class, 'update'])->name('emar.record.allergies.update');
        Route::get('/medicines', [PersonMedicationRecordController::class, 'medicines'])->name('emar.record.medicines');
        Route::get('/medicines/{medication}', [PersonMedicationRecordController::class, 'medicine'])->whereNumber('medication')->name('emar.record.medicine');
        Route::get('/support', [PersonMedicationRecordController::class, 'support'])->name('emar.record.support');
        Route::get('/safety', [PersonMedicationRecordController::class, 'safety'])->name('emar.record.safety');
        Route::get('/clinical', [PersonMedicationRecordController::class, 'clinical'])->name('emar.record.clinical');
        Route::get('/week', [PersonMedicationRecordController::class, 'week'])->name('emar.record.week');
        Route::post('/clinical/{command}', [PersonMedicationClinicalController::class, 'store'])->name('emar.record.clinical.store');
        Route::get('/history', [PersonMedicationRecordController::class, 'history'])->name('emar.record.history');
        Route::get('/doses/{administration}', [PersonMedicationRecordController::class, 'dose'])->whereNumber('administration')->name('emar.record.dose');
        Route::post('/doses/{administration}/corrections/{command}', [\App\Http\Controllers\Emar\PersonMedicationCorrectionController::class, 'store'])->whereNumber('administration')->name('emar.record.correction');
    });

    // Self-Administration Assessments
    Route::get('/self-admin', [MedicationSupportController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('emar.self_admin');
    Route::get('/self-admin/clients/{client}', [MedicationSupportController::class, 'show'])->middleware('permission:medications.view')->name('emar.support.show');
    Route::post('/self-admin/{assessment}/consent', [MedicationSupportController::class, 'consent'])->middleware('permission:medications.orders.manage|medications.administer.record')->name('emar.support.consent');
    Route::post('/self-admin/{assessment}/agreement', [MedicationSupportController::class, 'agreement'])->middleware('permission:medications.orders.manage')->name('emar.support.agreement');
    Route::get('/self-admin/agreements/{agreement}/file', [MedicationSupportController::class, 'agreementFile'])->middleware('permission:medications.view')->name('emar.support.agreement.file');

    Route::post('/competency/{assessment}/acknowledge', [EmarController::class, 'acknowledgeCompetency'])
        ->name('emar.competency.acknowledge');
    // P11: competency exemptions — one house, a reason, an end date within the
    // longest exemption (Settings › Staff & PINs); never for yourself.
    Route::middleware('permission:medications.competency.exempt')->group(function () {
        Route::post('/competency/exemptions', [CompetencyExemptionController::class, 'store'])
            ->name('emar.competency.exemptions.store');
        Route::post('/competency/exemptions/{exemption}/end', [CompetencyExemptionController::class, 'end'])
            ->name('emar.competency.exemptions.end');
    });

    // Destruction / Disposal Records
    Route::get('/destructions', [ControlledProductController::class, 'destructions'])
        ->middleware([
            'permission:medications.view',
            'permission:medications.controlled.view',
        ])
        ->name('emar.destructions');

    // Medication Handovers
    Route::get('/handovers', [EmarController::class, 'handovers'])
        ->middleware('permission:medications.view')
        ->name('emar.handovers');
    // Live "Medications this shift" snapshot for the handover wizard/detail lens.
    Route::get('/handovers/shift-medications', [EmarController::class, 'shiftMedicationSnapshot'])
        ->middleware('permission:medications.view')
        ->name('emar.handovers.shift_medications');

    // ─── CRUD Routes (permission-gated) ─────────────────────

    Route::post('/prescriptions/{order}/confirm', [EmarController::class, 'confirmPrescription'])
        ->middleware('permission:medications.orders.verify')
        ->name('emar.prescriptions.confirm');
    Route::post('/prescriptions/{order}/countersign', [EmarController::class, 'countersignPrescription'])
        ->middleware('permission:medications.orders.verify')
        ->name('emar.prescriptions.countersign');

    Route::middleware('permission:medications.orders.manage')->group(function () {

        // Prescriber Orders
        Route::post('/prescriptions', [EmarController::class, 'storePrescription'])->name('emar.prescriptions.store');
        Route::put('/prescriptions/{order}', [EmarController::class, 'updatePrescription'])->name('emar.prescriptions.update');
        Route::post('/prescriptions/{order}/dispense', [EmarController::class, 'dispensePrescription'])->name('emar.prescriptions.dispense');
        Route::post('/prescriptions/{order}/cancel', [EmarController::class, 'cancelPrescription'])->name('emar.prescriptions.cancel');

        // Covert Authorisations
        Route::post('/prescriptions/covert', [EmarController::class, 'storeCovert'])->name('emar.covert.store');
        Route::post('/prescriptions/covert/{authorisation}/revoke', [EmarController::class, 'revokeCovert'])->name('emar.covert.revoke');

        // 1CHART attention, INR, and syringe-driver workflows
        Route::post('/clients/{client}/attention-alerts', [EmarController::class, 'storeAttentionAlert'])->name('emar.clients.attention_alerts.store');
        Route::put('/attention-alerts/{alert}', [EmarController::class, 'updateAttentionAlert'])->name('emar.attention_alerts.update');
        Route::post('/attention-alerts/{alert}/resolve', [EmarController::class, 'resolveAttentionAlert'])->name('emar.attention_alerts.resolve');
        Route::post('/clients/{client}/alert-suppression', [EmarController::class, 'toggleMedicationAlertSuppression'])->name('emar.clients.alert_suppression');
        Route::post('/clients/{client}/medication-settings', [EmarController::class, 'updateMedicationSettings'])->name('emar.clients.medication_settings');
        Route::post('/clients/{client}/inr', [EmarController::class, 'storeInr'])->name('emar.clients.inr.store');
        Route::post('/inr/{inr}/disable', [EmarController::class, 'disableInr'])->name('emar.inr.disable');
        Route::post('/clients/{client}/syringe-drivers', [EmarController::class, 'storeSyringeDriver'])->name('emar.clients.syringe_drivers.store');
        Route::post('/syringe-drivers/{driver}/complete', [EmarController::class, 'completeSyringeDriver'])->name('emar.syringe_drivers.complete');

        // Competency Assessments
        Route::post('/competency', [EmarController::class, 'storeCompetency'])->name('emar.competency.store');
        Route::put('/competency/{assessment}', [EmarController::class, 'updateCompetency'])->name('emar.competency.update');
        Route::delete('/competency/{assessment}', [EmarController::class, 'destroyCompetency'])->name('emar.competency.destroy');

        // Round Templates + Workflow
        Route::post('/rounds/templates', [EmarController::class, 'storeRoundTemplate'])->name('emar.rounds.templates.store');
        Route::put('/rounds/templates/{template}', [EmarController::class, 'updateRoundTemplate'])->name('emar.rounds.templates.update');
        Route::post('/rounds/templates/{template}/retire', [EmarController::class, 'retireRoundTemplate'])->name('emar.rounds.templates.retire');
        Route::post('/rounds/generate', [EmarController::class, 'generateRounds'])->name('emar.rounds.generate');
        Route::get('/rounds/generate/preview', [EmarController::class, 'previewRounds'])->name('emar.rounds.generate.preview');
        Route::post('/rounds/{round}/start', [EmarController::class, 'startRound'])->name('emar.rounds.start');
        Route::post('/rounds/{round}/complete', [EmarController::class, 'completeRound'])->name('emar.rounds.complete');
        Route::put('/rounds/{round}/assign', [EmarController::class, 'assignRound'])->name('emar.rounds.assign');

        // Self-Admin Assessments
        Route::post('/self-admin', [MedicationSupportController::class, 'store'])->name('emar.self_admin.store');
        Route::put('/self-admin/{assessment}', [MedicationSupportController::class, 'update'])->name('emar.self_admin.update');
        Route::delete('/self-admin/{assessment}', [MedicationSupportController::class, 'destroy'])->name('emar.self_admin.destroy');

        // Medications CRUD
        Route::post('/medications', [EmarController::class, 'storeMedication'])->name('emar.medications.store');
        Route::post('/medications/import', [EmarController::class, 'importMedications'])->name('emar.medications.import');
        Route::put('/medications/{medication}', [EmarController::class, 'updateMedication'])->name('emar.medications.update');
        Route::post('/medications/{medication}/discontinue', [EmarController::class, 'discontinueMedication'])->name('emar.medications.discontinue');

    }); // end medications.orders.manage middleware group

    Route::post('/syringe-drivers/{driver}/checks', [EmarController::class, 'addSyringeDriverCheck'])
        ->middleware('permission:medications.administer.record')
        ->name('emar.syringe_drivers.checks.store');

    Route::post('/prn/effectiveness', [EmarController::class, 'storePrnEffectiveness'])
        ->middleware('permission:medications.administer.record')
        ->name('emar.prn_effectiveness.store');

    Route::post('/alerts/{alert}/dismiss', [EmarController::class, 'dismissAlert'])
        ->whereNumber('alert')
        ->middleware([
            'permission:medications.view',
            'permission:medications.administer.correct',
        ])
        ->name('emar.alerts.dismiss');

    Route::middleware('permission:medications.controlled.record')->group(function () {
        // Controlled Drug Entries
        Route::post('/controlled/entries', [ControlledProductController::class, 'legacy'])->name('emar.controlled.entries.store');
        Route::post('/controlled/balance-check', [ControlledProductController::class, 'legacy'])->name('emar.controlled.balance_check.store');
        Route::post('/stock/pharmacy-orders/{order}/controlled-delivery', [EmarController::class, 'receiveControlledPharmacyOrder'])
            ->name('emar.pharmacy_orders.controlled_delivery');
        Route::post('/controlled/discrepancies/{discrepancy}/resolve', [ControlledProductController::class, 'legacy'])->middleware('permission:medications.controlled.manage')->name('emar.controlled.discrepancies.resolve');

        // The destruction register is immutable; erroneous records are voided, not deleted.
        Route::post('/destructions', [ControlledProductController::class, 'legacy'])->name('emar.destructions.store');
        Route::post('/destructions/{destruction}/void', [ControlledProductController::class, 'legacy'])->middleware('permission:medications.controlled.manage')->name('emar.destructions.void');
    });

    Route::middleware('permission:medications.stock.update')->group(function () {
        // Pharmacy Orders + Stock
        Route::post('/stock/pharmacy-orders', [EmarController::class, 'storePharmacyOrder'])->name('emar.pharmacy_orders.store');
        Route::put('/stock/pharmacy-orders/{order}', [EmarController::class, 'updatePharmacyOrder'])->name('emar.pharmacy_orders.update');
        Route::post('/stock/pharmacy-orders/{order}/advance', [EmarController::class, 'advancePharmacyOrder'])->name('emar.pharmacy_orders.advance');
        Route::patch('/stock/{stock}', [EmarController::class, 'updateStockItem'])->name('emar.stock.update');
        Route::post('/stock/receive', [EmarController::class, 'receiveStock'])->name('emar.stock.receive');
        Route::post('/stock/adjust', [EmarController::class, 'adjustStock'])->name('emar.stock.adjust');
    });

    Route::middleware('permission:medications.orders.verify')->group(function () {
        Route::post('/medications/{medication}/verify', [EmarController::class, 'verifyMedication'])->name('emar.medications.verify');
        Route::post('/medications/{medication}/reject', [EmarController::class, 'rejectMedication'])->name('emar.medications.reject');
    });

    // ─── Facility Medication Admin Rules (1CHART §6.1 — countersign / observation prompts) ───
    // House leads with the PIN reset permission reach the settings page for the
    // second-person confirmation section only (PIN-1); auditors read every
    // setting and its change history, read-only (P11 answer 6); people who
    // manage orders at a house reach its round templates only (P11 F1), and
    // medications.view holders read them, read-only (P11 Q2).
    Route::get('/settings', [MedicationSettingsController::class, 'index'])
        ->middleware('permission:medications.settings.manage|medications.witness_pin.reset|medications.audit.view|medications.orders.manage|medications.alerts.manage_house|medications.view')
        ->name('emar.settings');
    // P11: who a medicine rule would apply to now, in the reader's own person scope.
    Route::get('/settings/rules/preview', [MedicationSettingsController::class, 'previewRule'])
        ->middleware('permission:medications.settings.manage|medications.audit.view')
        ->name('emar.settings.rules.preview');
    Route::post('/settings/witness-pins/{user}/reset', [MedicationSettingsController::class, 'resetWitnessPin'])
        ->middleware('permission:medications.witness_pin.reset')
        ->name('emar.settings.witness_pins.reset');
    Route::post('/settings/witness-pins/remind', [MedicationSettingsController::class, 'remindWitnessPins'])
        ->middleware('permission:medications.witness_pin.reset')
        ->name('emar.settings.witness_pins.remind');
    Route::middleware('permission:medications.settings.manage')->group(function () {
        Route::post('/settings/rules', [MedicationSettingsController::class, 'store'])->name('emar.settings.rules.store');
        Route::put('/settings/rules/{rule}', [MedicationSettingsController::class, 'update'])->name('emar.settings.rules.update');
        // P00 v5: rules are paused and turned back on, recorded in the change history.
        Route::post('/settings/rules/{rule}/active', [MedicationSettingsController::class, 'setActive'])->name('emar.settings.rules.active');
        // P11: "Keep today's value" for defaults nobody has reviewed, recorded
        // in the change history and the audit log.
        Route::post('/settings/keep', [MedicationSettingsController::class, 'keepDefaults'])->name('emar.settings.keep');
    });
    // P11: save one Settings view's draft (safety checks, witness PIN rules, …),
    // recorded in the change history and the audit log. House managers save
    // only their own houses' alert extras (B2 Q3); the controller checks each change.
    Route::put('/settings/changes', [MedicationSettingsController::class, 'saveChanges'])
        ->middleware('permission:medications.settings.manage|medications.alerts.manage_house')
        ->name('emar.settings.changes.save');
    // P11 B2 chunk 4: a house's on-call contact saves (and is removed) straight
    // away; the controller checks authority over that house.
    Route::put('/settings/oncall/{site}', [MedicationSettingsController::class, 'saveOnCall'])
        ->middleware('permission:medications.settings.manage|medications.alerts.manage_house')
        ->name('emar.settings.oncall.save');
    Route::delete('/settings/oncall/{site}', [MedicationSettingsController::class, 'removeOnCall'])
        ->middleware('permission:medications.settings.manage|medications.alerts.manage_house')
        ->name('emar.settings.oncall.remove');

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
    // Presence edit-lock (acquire on wizard open, release on close).
    Route::post('/handovers/{handover}/lock', [EmarController::class, 'lockHandover'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny|clients.update')
        ->name('emar.handovers.lock');
    Route::post('/handovers/{handover}/unlock', [EmarController::class, 'unlockHandover'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny|clients.update')
        ->name('emar.handovers.unlock');
    Route::delete('/handovers/{handover}', [EmarController::class, 'destroyHandover'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny|clients.update')
        ->name('emar.handovers.destroy');

    // Audit trail
    Route::get('/audit', [\App\Http\Controllers\Emar\MedicationReportsController::class, 'redirect'])
        ->middleware([
            'permission:medications.view',
            'permission:medications.audit.view',
        ])
        ->name('emar.audit');
    Route::get('/audit/export', [MedicationAuditController::class, 'exportCsv'])
        ->middleware(\App\Http\Middleware\MedicationExportGuard::class.':audit')
        ->middleware([
            'permission:medications.view',
            'permission:medications.audit.view',
            'permission:medications.audit.export',
        ])
        ->name('emar.audit.export');
    // Per-event drawer actions (synthetic id → backing record; see controller).
    Route::get('/audit/event/{id}/integrity', [MedicationAuditEventController::class, 'integrity'])
        ->middleware([
            'permission:medications.view',
            'permission:medications.audit.view',
        ])
        ->name('emar.audit.event.integrity');
    Route::get('/audit/event/{id}/export', [MedicationAuditEventController::class, 'export'])
        ->middleware(\App\Http\Middleware\MedicationExportGuard::class.':audit')
        ->middleware([
            'permission:medications.view',
            'permission:medications.audit.view',
            'permission:medications.audit.export',
        ])
        ->name('emar.audit.event.export');
    Route::post('/audit/event/{id}/flag', [MedicationAuditEventController::class, 'flag'])
        ->middleware([
            'permission:medications.view',
            'permission:medications.audit.view',
            'permission:medications.administer.record',
        ])
        ->name('emar.audit.event.flag');

    // Emergency access
    Route::get('/emergency-access', [EmergencyAccessController::class, 'index'])
        ->middleware('permission:medications.breakglass|medications.audit.view')
        ->name('emar.emergency_access');

    // Canonical break-glass revoke
    Route::delete('/clients/{client}/break-glass/{access}', [BreakGlassController::class, 'destroy'])
        ->middleware('permission:medications.breakglass|medications.breakglass.end')
        ->name('emar.clients.break_glass.destroy');

    // Extend a live grant (+30 min, capped at the policy max)
    Route::post('/clients/{client}/break-glass/{access}/extend', [BreakGlassController::class, 'extend'])
        ->middleware('permission:medications.breakglass')
        ->name('emar.clients.break_glass.extend');

    // Post-event review (oversight sign-off): justified / not justified
    Route::post('/clients/{client}/break-glass/{access}/review', [BreakGlassController::class, 'review'])
        ->middleware('permission:medications.audit.view')
        ->name('emar.clients.break_glass.review');

    // Editable per-organisation break-glass policy (admin-gated in-controller).
    Route::put('/break-glass-policy', [BreakGlassController::class, 'updatePolicy'])
        ->name('emar.break_glass.policy.update');

    // Acknowledge / dismiss a derived misuse signal (re-surfaces on new activity).
    Route::post('/break-glass-flags/dismiss', [BreakGlassController::class, 'dismissFlag'])
        ->middleware('permission:medications.audit.view')
        ->name('emar.break_glass.flag.dismiss');

    // Correction approval workflow
    Route::post('/corrections/{correction}/approve', [MedicationAdministrationCorrectionController::class, 'approve'])
        ->middleware('permission:medications.administer.correct')
        ->name('emar.corrections.approve');
    Route::post('/corrections/{correction}/reject', [MedicationAdministrationCorrectionController::class, 'reject'])
        ->middleware('permission:medications.administer.correct')
        ->name('emar.corrections.reject');

    // Reports
    require __DIR__.'/emar-reporting.php';
    Route::middleware(['permission:medications.reports.view', 'permission:medications.reports.export'])->group(function () {
        Route::get('/reports/export-mar', [MedicationsReportController::class, 'exportMarCsv'])
            ->middleware(\App\Http\Middleware\MedicationExportGuard::class.':doses')
            ->name('emar.reports.export_mar');
        Route::get('/reports/export-controlled-discrepancies', [MedicationsReportController::class, 'exportDiscrepanciesCsv'])
            ->middleware(\App\Http\Middleware\MedicationExportGuard::class.':controlled')
            ->middleware('permission:medications.controlled.view')
            ->name('emar.reports.export_discrepancies');
    });

    // ─── Refusal & Withholding Follow-Up ────────────────────
    Route::post('/refusal-followups', [RefusalFollowUpController::class, 'store'])
        ->middleware('permission:medications.administer.record')
        ->name('emar.refusal_followups.store');
    Route::post('/refusal-followups/{followup}/complete', [RefusalFollowUpController::class, 'complete'])
        ->middleware('permission:medications.administer.record')
        ->name('emar.refusal_followups.complete');
    Route::post('/refusal-followups/{followup}/notify-gp', [RefusalFollowUpController::class, 'notifyGp'])
        ->middleware('permission:medications.followups.manage')
        ->name('emar.refusal_followups.notify_gp');

    // ─── Controlled Drug Loss Reports ─────────────────────
    Route::get('/controlled/loss-reports', [ControlledProductController::class, 'losses'])
        ->middleware([
            'permission:medications.view',
            'permission:medications.controlled.view',
        ])
        ->name('emar.cd_loss.index');
    Route::post('/controlled/loss-reports', [ControlledProductController::class, 'legacy'])
        ->middleware('permission:medications.controlled.record')
        ->name('emar.cd_loss.store');
    Route::post('/controlled/loss-reports/{report}/investigate', [ControlledProductController::class, 'legacy'])
        ->middleware('permission:medications.controlled.record')
        ->name('emar.cd_loss.investigate');
    Route::post('/controlled/loss-reports/{report}/resolve', [ControlledProductController::class, 'legacy'])
        ->middleware('permission:medications.controlled.manage')
        ->name('emar.cd_loss.resolve');

    // ─── Medication Errors ──────────────────────────────────
    Route::get('/errors/export', [MedicationErrorController::class, 'export'])->middleware('permission:medications.view')->name('emar.errors.export');
    Route::get('/errors', [MedicationErrorController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('emar.errors');
    Route::post('/errors', [MedicationErrorController::class, 'store'])
        ->middleware('permission:medications.administer.record')
        ->name('emar.errors.store');
    Route::put('/errors/{error}', [MedicationErrorController::class, 'update'])
        ->middleware('permission:medications.errors.manage')
        ->name('emar.errors.update');
    Route::post('/errors/{error}/review', [MedicationErrorController::class, 'review'])
        ->middleware('permission:medications.errors.manage')
        ->name('emar.errors.review');
    Route::post('/errors/{error}/resolve', [MedicationErrorController::class, 'resolve'])
        ->middleware('permission:medications.errors.manage')
        ->name('emar.errors.resolve');
    Route::post('/errors/{error}/close', [MedicationErrorController::class, 'close'])
        ->middleware('permission:medications.errors.manage')
        ->name('emar.errors.close');
    // Post-report "create & link incident" — the report-time create_incident path
    // only runs at store(). Reuses that incident-creation shape, links it, then
    // jumps to the incidents module. See docs/ERRORS_GAP_ANALYSIS.md (C1).
    Route::post('/errors/{error}/link-incident', [MedicationErrorController::class, 'linkIncident'])
        ->middleware('permission:medications.errors.manage')
        ->name('emar.errors.link_incident');

    Route::get('/errors/medicines/{client}', [MedicationErrorController::class, 'medicines'])->middleware('permission:medications.administer.record')->name('emar.errors.medicines');
    Route::post('/errors/{error}/accounts', [MedicationErrorController::class, 'account'])->middleware('permission:medications.administer.record')->name('emar.errors.account');
    Route::post('/errors/{error}/notes', [MedicationErrorController::class, 'note'])->middleware('permission:medications.errors.manage')->name('emar.errors.note');
    Route::post('/errors/{error}/actions', [MedicationErrorController::class, 'action'])->middleware('permission:medications.errors.manage')->name('emar.errors.action');
    Route::post('/errors/{error}/actions/{action}/complete', [MedicationErrorController::class, 'completeAction'])->middleware('permission:medications.errors.manage')->name('emar.errors.action.complete');
    Route::post('/errors/{error}/disclosure', [MedicationErrorController::class, 'disclosure'])->middleware('permission:medications.errors.manage')->name('emar.errors.disclosure');
    Route::post('/errors/{error}/reopen', [MedicationErrorController::class, 'reopen'])->middleware('permission:medications.errors.manage')->name('emar.errors.reopen');

    // ─── PDF Exports ─────────────────────────────────────────
    Route::middleware(['permission:medications.reports.view', 'permission:medications.reports.export'])->group(function () {
        Route::get('/pdf/mar-chart', [EmarPdfController::class, 'marChart'])->middleware(\App\Http\Middleware\MedicationExportGuard::class.':mar')->name('emar.pdf.mar');
        Route::get('/pdf/controlled-register', [EmarPdfController::class, 'controlledDrugRegister'])
            ->middleware(\App\Http\Middleware\MedicationExportGuard::class.':cd_register')
            ->middleware('permission:medications.controlled.view')
            ->name('emar.pdf.cd_register');
        Route::get('/pdf/round-sheet', [\App\Http\Controllers\Emar\MedicationReportsController::class, 'legacyRoundSheet'])->name('emar.pdf.round_sheet');
    });
});

require __DIR__.'/emar-stock.php';
require __DIR__.'/emar-downtime.php';
