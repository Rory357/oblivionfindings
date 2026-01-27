<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientAssignmentController;
use App\Http\Controllers\ClientNoteController;
use App\Http\Controllers\ClientMedicalController;
use App\Http\Controllers\ClientDocumentController;
use App\Http\Controllers\ClientPortalUserController;
use App\Http\Controllers\ClientRagController;
use App\Http\Controllers\ClientOnboardingController;
use App\Http\Controllers\ClientSupportPlanController;
use App\Http\Controllers\ClientAssessmentController;
use App\Http\Controllers\ClientMarController;
use App\Http\Controllers\MedicationAdministrationCorrectionController;
use App\Http\Controllers\BreakGlassController;
use App\Http\Controllers\ClientIncidentController;
use App\Http\Controllers\ClientRiskController;

/**
 * Client Management Routes
 *
 * Handles client profiles, medical records, documents, incidents,
 * risks, and medication administration.
 */

Route::middleware(['auth'])->group(function () {
    // Client listing and viewing
    Route::middleware('permission:clients.viewAny|clients.viewAssigned')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/{client}', [ClientController::class, 'show'])
            ->whereNumber('client')
            ->name('clients.show');

        // Documents
        Route::get('/clients/{client}/documents', [ClientDocumentController::class, 'index'])
            ->whereNumber('client')
            ->name('clients.documents.index');
        Route::get('/clients/{client}/documents/{document}/download', [ClientDocumentController::class, 'download'])
            ->whereNumber('client')
            ->name('clients.documents.download');

        // Medical records
        Route::get('/clients/{client}/medical', [ClientMedicalController::class, 'show'])
            ->whereNumber('client')
            ->name('clients.medical.show');

        // Medication Administration Record (MAR)
        Route::get('/clients/{client}/mar', [ClientMarController::class, 'show'])
            ->whereNumber('client')
            ->name('clients.mar.show');
        Route::get('/clients/{client}/mar/export.csv', [ClientMarController::class, 'exportCsv'])
            ->whereNumber('client')
            ->name('clients.mar.export_csv');
    });

    // Client creation
    Route::middleware('permission:clients.create')->group(function () {
        Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
        Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    });

    // Client updates
    Route::middleware('permission:clients.update')->group(function () {
        Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::post('/clients/{client}/photo', [ClientController::class, 'updatePhoto'])
            ->name('clients.photo.update');
        Route::delete('/clients/{client}/photo', [ClientController::class, 'destroyPhoto'])
            ->name('clients.photo.destroy');

        // Document management
        Route::post('/clients/{client}/documents', [ClientDocumentController::class, 'store'])
            ->name('clients.documents.store');
        Route::put('/clients/{client}/documents/{document}', [ClientDocumentController::class, 'update'])
            ->name('clients.documents.update');
        Route::delete('/clients/{client}/documents/{document}', [ClientDocumentController::class, 'destroy'])
            ->name('clients.documents.destroy');

        // Medical profile
        Route::put('/clients/{client}/medical/profile', [ClientMedicalController::class, 'updateProfile'])
            ->name('clients.medical.profile.update');

        // Medications
        Route::post('/clients/{client}/medical/medications', [ClientMedicalController::class, 'storeMedication'])
            ->name('clients.medical.medications.store');
        Route::put('/clients/{client}/medical/medications/{medication}', [ClientMedicalController::class, 'updateMedication'])
            ->name('clients.medical.medications.update');
        Route::delete('/clients/{client}/medical/medications/{medication}', [ClientMedicalController::class, 'destroyMedication'])
            ->name('clients.medical.medications.destroy');

        // Medical conditions
        Route::post('/clients/{client}/medical/conditions', [ClientMedicalController::class, 'storeCondition'])
            ->name('clients.medical.conditions.store');
        Route::put('/clients/{client}/medical/conditions/{condition}', [ClientMedicalController::class, 'updateCondition'])
            ->name('clients.medical.conditions.update');
        Route::delete('/clients/{client}/medical/conditions/{condition}', [ClientMedicalController::class, 'destroyCondition'])
            ->name('clients.medical.conditions.destroy');

        // Emergency contacts
        Route::post('/clients/{client}/medical/emergency-contacts', [ClientMedicalController::class, 'storeEmergencyContact'])
            ->name('clients.medical.emergency_contacts.store');
        Route::put('/clients/{client}/medical/emergency-contacts/{contact}', [ClientMedicalController::class, 'updateEmergencyContact'])
            ->name('clients.medical.emergency_contacts.update');
        Route::delete('/clients/{client}/medical/emergency-contacts/{contact}', [ClientMedicalController::class, 'destroyEmergencyContact'])
            ->name('clients.medical.emergency_contacts.destroy');

        // Portal users (family/NOK)
        Route::get('/clients/{client}/portal-users', [ClientPortalUserController::class, 'edit'])
            ->name('clients.portal_users.edit');
        Route::post('/clients/{client}/portal-users', [ClientPortalUserController::class, 'store'])
            ->name('clients.portal_users.store');
        Route::delete('/clients/{client}/portal-users/{user}', [ClientPortalUserController::class, 'destroy'])
            ->name('clients.portal_users.destroy');

        // RAG/AI queries
        Route::post('/clients/{client}/rag/ask', [ClientRagController::class, 'ask'])
            ->middleware('throttle:ai-queries')
            ->name('clients.rag.ask');

        // Onboarding checklist
        Route::post('/clients/{client}/onboarding/{key}', [ClientOnboardingController::class, 'toggle'])
            ->whereNumber('client')
            ->name('clients.onboarding.toggle')
            ->middleware('permission:clients.onboarding.manage|clients.update');

        // Support plan
        Route::put('/clients/{client}/support-plan', [ClientSupportPlanController::class, 'update'])
            ->name('clients.support_plan.update');

        // Assessments
        Route::post('/clients/{client}/assessments', [ClientAssessmentController::class, 'store'])
            ->name('clients.assessments.store');
        Route::put('/clients/{client}/assessments/{assessment}', [ClientAssessmentController::class, 'update'])
            ->name('clients.assessments.update');
        Route::delete('/clients/{client}/assessments/{assessment}', [ClientAssessmentController::class, 'destroy'])
            ->name('clients.assessments.destroy');
    });

    // Client assignments
    Route::middleware('permission:clients.assignments.update')->group(function () {
        Route::get('/clients/{client}/assignments', [ClientAssignmentController::class, 'edit'])
            ->name('clients.assignments.edit');
        Route::put('/clients/{client}/assignments', [ClientAssignmentController::class, 'update'])
            ->name('clients.assignments.update');
    });

    // Timeline notes
    Route::post('/clients/{client}/notes', [ClientNoteController::class, 'store'])
        ->middleware('permission:timeline.create')
        ->name('clients.notes.store');
    Route::post('/clients/{client}/notes/{note}/pin', [ClientNoteController::class, 'togglePin'])
        ->middleware('permission:timeline.pin|clients.update')
        ->name('clients.notes.pin');

    // Medication stock updates (managers/finance)
    Route::put('/clients/{client}/medical/medications/{medication}/stock', [ClientMedicalController::class, 'updateMedicationStock'])
        ->middleware('permission:medications.stock.update|clients.update')
        ->name('clients.medical.medications.stock.update');

    // Medication administration (support workers + managers)
    Route::post('/clients/{client}/medical/medications/{medication}/administrations', [ClientMedicalController::class, 'storeAdministration'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->name('clients.medical.medications.administrations.store');

    // Medication administration corrections
    Route::post('/clients/{client}/mar/administrations/{administration}/corrections', [MedicationAdministrationCorrectionController::class, 'store'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('clients.mar.administrations.corrections.store');

    // Break-glass emergency access
    Route::post('/clients/{client}/break-glass', [BreakGlassController::class, 'store'])
        ->middleware('permission:medications.breakglass')
        ->name('clients.break_glass.store');
    Route::delete('/clients/{client}/break-glass/{access}', [BreakGlassController::class, 'destroy'])
        ->middleware('permission:medications.breakglass')
        ->name('clients.break_glass.destroy');

    // Emergency access entry point
    Route::get('/emergency-access', [\App\Http\Controllers\EmergencyAccessController::class, 'index'])
        ->middleware('permission:medications.breakglass')
        ->name('emergency_access.index');

    // Controlled medication discrepancies
    Route::post('/clients/{client}/medical/controlled-discrepancies/{discrepancy}/close', [ClientMedicalController::class, 'closeControlledDiscrepancy'])
        ->middleware('permission:medications.controlled.record|clients.update')
        ->name('clients.medical.controlled_discrepancies.close');

    // Client incidents
    Route::middleware('permission:incidents.viewAny|incidents.viewAssigned')->group(function () {
        Route::get('/clients/{client}/incidents', [ClientIncidentController::class, 'index'])
            ->whereNumber('client')
            ->name('clients.incidents.index');
    });

    Route::post('/clients/{client}/incidents', [ClientIncidentController::class, 'store'])
        ->middleware('permission:incidents.create')
        ->whereNumber('client')
        ->name('clients.incidents.store');

    // Client incident attachments (compatibility routes)
    Route::post('/clients/{client}/incidents/{incident}/attachments', [ClientIncidentController::class, 'uploadAttachment'])
        ->middleware('permission:incidents.update')
        ->whereNumber('client')
        ->name('clients.incidents.attachments.store');
    Route::get('/clients/{client}/incidents/{incident}/attachments/{attachment}/download', [ClientIncidentController::class, 'downloadAttachment'])
        ->middleware('permission:incidents.viewAny|incidents.viewAssigned')
        ->whereNumber('client')
        ->name('clients.incidents.attachments.download');

    // Risk register
    Route::middleware('permission:risks.viewAny|risks.viewAssigned')->group(function () {
        Route::get('/clients/{client}/risks', [ClientRiskController::class, 'index'])
            ->whereNumber('client')
            ->name('clients.risks.index');
    });

    Route::post('/clients/{client}/risks', [ClientRiskController::class, 'store'])
        ->middleware('permission:risks.create')
        ->whereNumber('client')
        ->name('clients.risks.store');
    Route::put('/clients/{client}/risks/{risk}', [ClientRiskController::class, 'update'])
        ->middleware('permission:risks.update')
        ->whereNumber('client')
        ->name('clients.risks.update');
    Route::delete('/clients/{client}/risks/{risk}', [ClientRiskController::class, 'destroy'])
        ->middleware('permission:risks.delete')
        ->whereNumber('client')
        ->name('clients.risks.destroy');
});
