<?php

use App\Http\Controllers\BreakGlassController;
// Existing controllers (root namespace)
use App\Http\Controllers\ClientAssessmentController;
use App\Http\Controllers\ClientAssignmentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientDocumentController;
use App\Http\Controllers\ClientIncidentController;
use App\Http\Controllers\ClientMarController;
use App\Http\Controllers\ClientMedicalController;
use App\Http\Controllers\ClientNoteController;
use App\Http\Controllers\ClientOnboardingController;
use App\Http\Controllers\ClientPersonalAssetController;
use App\Http\Controllers\ClientPortalUserController;
use App\Http\Controllers\ClientRagController;
use App\Http\Controllers\ClientRiskController;
use App\Http\Controllers\ClientSupportPlanController;
use App\Http\Controllers\CoverageGapController;
use App\Http\Controllers\CoverageReservationController;
use App\Http\Controllers\MedicationAdministrationCorrectionController;
use App\Http\Controllers\Operations\ActivityFeedController;
use App\Http\Controllers\Operations\AvailabilityController;
use App\Http\Controllers\Operations\BillingController;
use App\Http\Controllers\Operations\CalendarSyncController;
use App\Http\Controllers\Operations\CareNoteTemplateController;
// New Operations controllers
use App\Http\Controllers\Operations\CarePlanController;
use App\Http\Controllers\Operations\CarePlanGoalController;
use App\Http\Controllers\Operations\ClientConsentController;
use App\Http\Controllers\Operations\ClientFundController;
use App\Http\Controllers\Operations\ClientOnboardingWorkflowController;
use App\Http\Controllers\Operations\ConsentRequestController;
use App\Http\Controllers\Operations\CustomFormController;
use App\Http\Controllers\Operations\DashboardController;
use App\Http\Controllers\Operations\EvvController;
use App\Http\Controllers\Operations\FamilyPortalController;
use App\Http\Controllers\Operations\FundingClaimController;
use App\Http\Controllers\Operations\FundingController;
use App\Http\Controllers\Operations\GeofenceController;
use App\Http\Controllers\Operations\HandoverController;
use App\Http\Controllers\Operations\InvoiceController;
use App\Http\Controllers\Operations\JobBoardController;
use App\Http\Controllers\Operations\MessageController;
use App\Http\Controllers\Operations\MileageClaimController;
use App\Http\Controllers\Operations\OpsNotificationController;
use App\Http\Controllers\Operations\PayrollExportController;
use App\Http\Controllers\Operations\PriceBookController;
use App\Http\Controllers\Operations\ProgressNoteController;
use App\Http\Controllers\Operations\QualificationMatchController;
use App\Http\Controllers\Operations\QuoteController;
use App\Http\Controllers\Operations\RecurringChargeController;
use App\Http\Controllers\Operations\ReportController;
use App\Http\Controllers\Operations\RosterSuggestionController;
use App\Http\Controllers\Operations\RosterTemplateController;
use App\Http\Controllers\Operations\ServiceAgreementController;
use App\Http\Controllers\Operations\ShiftNoteController;
use App\Http\Controllers\Operations\ShiftReportController;
use App\Http\Controllers\RosteringController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShiftSeriesController;
use App\Http\Controllers\ShiftTaskController;
use App\Http\Controllers\StaffTimeOffController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\TimesheetController;
use Illuminate\Support\Facades\Route;

/**
 * Operations Module Routes
 *
 * Centralises client management, shifts, rostering, timesheets,
 * care plans, billing, invoicing, funding, and messaging.
 */
Route::middleware(['auth'])->prefix('operations')->group(function () {

    // -------------------------------------------------------------------------
    // Dashboard & Activity
    // -------------------------------------------------------------------------

    // PR 18 — the operations dashboard is scheduler/admin-first. Frontline
    // staff without any management capability are redirected to `/my-day`
    // (their canonical home). Managers / admins still see the same dashboard.
    Route::get('/', DashboardController::class)
        ->middleware('role_scope:my-day')
        ->name('operations.dashboard');
    Route::get('/activity', [ActivityFeedController::class, 'index'])->name('operations.activity.index');

    // Timeline
    Route::get('/timeline', [TimelineController::class, 'my'])->name('operations.timeline');

    // Summaries
    Route::get('/summaries', [SummaryController::class, 'my'])->name('operations.summaries');

    // -------------------------------------------------------------------------
    // Clients (migrated from /clients)
    // -------------------------------------------------------------------------

    // Client listing and viewing
    Route::middleware('permission:clients.viewAny|clients.viewAssigned')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('operations.clients.index');
        Route::get('/clients/{client}', [ClientController::class, 'show'])
            ->whereNumber('client')
            ->name('operations.clients.show');

        // Client Location History (JSON)
        Route::get('/clients/{client}/location/history', [ClientController::class, 'locationHistory'])
            ->whereNumber('client')
            ->name('operations.clients.location.history');
        Route::post('/clients/{client}/location/locate-now', [ClientController::class, 'locateNow'])
            ->whereNumber('client')
            ->name('operations.clients.location.locate-now');

        // Documents
        Route::get('/clients/{client}/documents', [ClientDocumentController::class, 'index'])
            ->whereNumber('client')
            ->name('operations.clients.documents.index');
        Route::get('/clients/{client}/documents/{document}/download', [ClientDocumentController::class, 'download'])
            ->whereNumber('client')
            ->name('operations.clients.documents.download');

        // Medical records
        Route::get('/clients/{client}/medical', [ClientMedicalController::class, 'show'])
            ->whereNumber('client')
            ->name('operations.clients.medical.show');

        // Medication Administration Record (MAR)
        Route::get('/clients/{client}/mar', [ClientMarController::class, 'show'])
            ->whereNumber('client')
            ->name('operations.clients.mar.show');
        Route::get('/clients/{client}/mar/export.csv', [ClientMarController::class, 'exportCsv'])
            ->whereNumber('client')
            ->name('operations.clients.mar.export_csv');

        // Consents (read)
        Route::get('/clients/{client}/consents', [ClientConsentController::class, 'index'])
            ->whereNumber('client')
            ->name('operations.clients.consents.index');

        Route::prefix('/clients/{client}/consent-requests')
            ->whereNumber('client')
            ->group(function () {
                Route::get('/', [ConsentRequestController::class, 'index'])
                    ->middleware('permission:consents.viewAny')
                    ->name('operations.clients.consent-requests.index');
                Route::get('/create', [ConsentRequestController::class, 'create'])
                    ->middleware('permission:consents.request')
                    ->name('operations.clients.consent-requests.create');
                Route::post('/', [ConsentRequestController::class, 'store'])
                    ->middleware('permission:consents.request')
                    ->name('operations.clients.consent-requests.store');
                Route::get('/{consentRequest}', [ConsentRequestController::class, 'show'])
                    ->whereNumber('consentRequest')
                    ->middleware('permission:consents.viewAny')
                    ->name('operations.clients.consent-requests.show');
                Route::post('/{consentRequest}/cancel', [ConsentRequestController::class, 'cancel'])
                    ->whereNumber('consentRequest')
                    ->middleware('permission:consents.request')
                    ->name('operations.clients.consent-requests.cancel');
            });

        // PR 14 — Consolidated frontline client care page
        // Worker-facing landing for a single client. Uses StaffPageShell,
        // ClientSafetyRibbon and the shared PRN sheet; admin show/medical/
        // risks remain at their existing routes for manager flows.
        Route::get('/clients/{client}/care', [\App\Http\Controllers\Operations\ClientCareController::class, 'show'])
            ->whereNumber('client')
            ->name('operations.clients.care');
        Route::post('/clients/{client}/care/prn', [\App\Http\Controllers\Operations\ClientCareController::class, 'recordPrn'])
            ->whereNumber('client')
            ->name('operations.clients.care.prn');
    });

    // Client creation
    Route::middleware('permission:clients.create')->group(function () {
        Route::get('/clients/create', [ClientController::class, 'create'])->name('operations.clients.create');
        Route::post('/clients', [ClientController::class, 'store'])->name('operations.clients.store');
    });

    // Client onboarding workflow (from client profile)
    Route::post('/clients/{client}/onboarding-workflow', [ClientOnboardingWorkflowController::class, 'storeForClient'])
        ->name('operations.clients.onboarding_workflow.store')
        ->whereNumber('client');

    // Client updates
    Route::middleware('permission:clients.update')->group(function () {
        Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('operations.clients.edit');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('operations.clients.update');
        Route::patch('/clients/{client}/quick-update', [ClientController::class, 'quickUpdate'])->name('operations.clients.quick_update');
        Route::post('/clients/{client}/photo', [ClientController::class, 'updatePhoto'])
            ->name('operations.clients.photo.update');
        Route::delete('/clients/{client}/photo', [ClientController::class, 'destroyPhoto'])
            ->name('operations.clients.photo.destroy');

        // Gallery photos
        Route::post('/clients/{client}/gallery-photos', [ClientController::class, 'storeGalleryPhoto'])
            ->name('operations.clients.gallery-photos.store');
        Route::delete('/clients/{client}/gallery-photos/{photo}', [ClientController::class, 'destroyGalleryPhoto'])
            ->name('operations.clients.gallery-photos.destroy');

        // Personal assets
        Route::post('/clients/{client}/personal-assets', [ClientPersonalAssetController::class, 'store'])
            ->name('operations.clients.personal-assets.store');
        Route::put('/clients/{client}/personal-assets/{asset}', [ClientPersonalAssetController::class, 'update'])
            ->name('operations.clients.personal-assets.update');
        Route::patch('/clients/{client}/personal-assets/{asset}/status', [ClientPersonalAssetController::class, 'updateStatus'])
            ->name('operations.clients.personal-assets.status');
        Route::delete('/clients/{client}/personal-assets/{asset}', [ClientPersonalAssetController::class, 'destroy'])
            ->name('operations.clients.personal-assets.destroy');

        // Document management
        Route::post('/clients/{client}/document-folders', [ClientDocumentController::class, 'storeFolder'])
            ->name('operations.clients.document-folders.store');
        Route::post('/clients/{client}/documents', [ClientDocumentController::class, 'store'])
            ->name('operations.clients.documents.store');
        Route::put('/clients/{client}/documents/{document}', [ClientDocumentController::class, 'update'])
            ->name('operations.clients.documents.update');
        Route::delete('/clients/{client}/documents/{document}', [ClientDocumentController::class, 'destroy'])
            ->name('operations.clients.documents.destroy');

        // Medical profile
        Route::put('/clients/{client}/medical/profile', [ClientMedicalController::class, 'updateProfile'])
            ->name('operations.clients.medical.profile.update');

        // Medications
        Route::post('/clients/{client}/medical/medications', [ClientMedicalController::class, 'storeMedication'])
            ->name('operations.clients.medical.medications.store');
        Route::put('/clients/{client}/medical/medications/{medication}', [ClientMedicalController::class, 'updateMedication'])
            ->name('operations.clients.medical.medications.update');
        Route::delete('/clients/{client}/medical/medications/{medication}', [ClientMedicalController::class, 'destroyMedication'])
            ->name('operations.clients.medical.medications.destroy');

        // Medical conditions
        Route::post('/clients/{client}/medical/conditions', [ClientMedicalController::class, 'storeCondition'])
            ->name('operations.clients.medical.conditions.store');
        Route::put('/clients/{client}/medical/conditions/{condition}', [ClientMedicalController::class, 'updateCondition'])
            ->name('operations.clients.medical.conditions.update');
        Route::delete('/clients/{client}/medical/conditions/{condition}', [ClientMedicalController::class, 'destroyCondition'])
            ->name('operations.clients.medical.conditions.destroy');

        // Emergency contacts
        Route::post('/clients/{client}/medical/emergency-contacts', [ClientMedicalController::class, 'storeEmergencyContact'])
            ->name('operations.clients.medical.emergency_contacts.store');
        Route::put('/clients/{client}/medical/emergency-contacts/{contact}', [ClientMedicalController::class, 'updateEmergencyContact'])
            ->name('operations.clients.medical.emergency_contacts.update');
        Route::delete('/clients/{client}/medical/emergency-contacts/{contact}', [ClientMedicalController::class, 'destroyEmergencyContact'])
            ->name('operations.clients.medical.emergency_contacts.destroy');

        // Portal users (family/NOK)
        Route::get('/clients/{client}/portal-users', [ClientPortalUserController::class, 'edit'])
            ->name('operations.clients.portal_users.edit');
        Route::post('/clients/{client}/portal-users', [ClientPortalUserController::class, 'store'])
            ->name('operations.clients.portal_users.store');
        Route::delete('/clients/{client}/portal-users/{user}', [ClientPortalUserController::class, 'destroy'])
            ->name('operations.clients.portal_users.destroy');

        // RAG/AI queries
        Route::post('/clients/{client}/rag/ask', [ClientRagController::class, 'ask'])
            ->middleware('throttle:ai-queries')
            ->name('operations.clients.rag.ask');

        // Onboarding checklist
        Route::post('/clients/{client}/onboarding/{key}', [ClientOnboardingController::class, 'toggle'])
            ->whereNumber('client')
            ->name('operations.clients.onboarding.toggle')
            ->middleware('permission:clients.onboarding.manage|clients.update');

        // Support plan
        Route::put('/clients/{client}/support-plan', [ClientSupportPlanController::class, 'update'])
            ->name('operations.clients.support_plan.update');

        // Assessments
        Route::post('/clients/{client}/assessments', [ClientAssessmentController::class, 'store'])
            ->name('operations.clients.assessments.store');
        Route::put('/clients/{client}/assessments/{assessment}', [ClientAssessmentController::class, 'update'])
            ->name('operations.clients.assessments.update');
        Route::delete('/clients/{client}/assessments/{assessment}', [ClientAssessmentController::class, 'destroy'])
            ->name('operations.clients.assessments.destroy');

        // Consents
        Route::post('/clients/{client}/consents', [ClientConsentController::class, 'store'])
            ->whereNumber('client')
            ->name('operations.clients.consents.store');
        Route::post('/clients/{client}/consents/{consent}/withdraw', [ClientConsentController::class, 'withdraw'])
            ->whereNumber('client')
            ->name('operations.clients.consents.withdraw');
    });

    // Client assignments
    Route::middleware('permission:clients.assignments.update')->group(function () {
        Route::get('/clients/{client}/assignments', [ClientAssignmentController::class, 'edit'])
            ->name('operations.clients.assignments.edit');
        Route::put('/clients/{client}/assignments', [ClientAssignmentController::class, 'update'])
            ->name('operations.clients.assignments.update');
    });

    // Timeline notes
    Route::post('/clients/{client}/notes', [ClientNoteController::class, 'store'])
        ->middleware('permission:timeline.create')
        ->name('operations.clients.notes.store');
    Route::post('/clients/{client}/notes/{note}/pin', [ClientNoteController::class, 'togglePin'])
        ->middleware('permission:timeline.pin|clients.update')
        ->name('operations.clients.notes.pin');

    // Medication stock updates
    Route::put('/clients/{client}/medical/medications/{medication}/stock', [ClientMedicalController::class, 'updateMedicationStock'])
        ->middleware('permission:medications.stock.update|medications.controlled.record|clients.update')
        ->name('operations.clients.medical.medications.stock.update');

    // Medication administration
    Route::post('/clients/{client}/medical/medications/{medication}/administrations', [ClientMedicalController::class, 'storeAdministration'])
        ->middleware('permission:medications.administer.record|clients.update|medications.orders.manage')
        ->name('operations.clients.medical.medications.administrations.store');

    // Medication administration corrections
    Route::post('/clients/{client}/mar/administrations/{administration}/corrections', [MedicationAdministrationCorrectionController::class, 'store'])
        ->middleware('permission:medications.administer.correct|clients.update')
        ->name('operations.clients.mar.administrations.corrections.store');

    // Break-glass emergency access
    Route::post('/clients/{client}/break-glass', [BreakGlassController::class, 'store'])
        ->middleware('permission:medications.breakglass')
        ->name('operations.clients.break_glass.store');
    Route::delete('/clients/{client}/break-glass/{access}', [BreakGlassController::class, 'destroy'])
        ->middleware('permission:medications.breakglass|medications.audit.view')
        ->name('operations.clients.break_glass.destroy');

    // Client incidents
    Route::middleware('permission:incidents.viewAny|incidents.viewAssigned')->group(function () {
        Route::get('/clients/{client}/incidents', [ClientIncidentController::class, 'index'])
            ->whereNumber('client')
            ->name('operations.clients.incidents.index');
    });

    Route::post('/clients/{client}/incidents', [ClientIncidentController::class, 'store'])
        ->middleware('permission:incidents.create')
        ->whereNumber('client')
        ->name('operations.clients.incidents.store');

    Route::post('/clients/{client}/incidents/{incident}/attachments', [ClientIncidentController::class, 'uploadAttachment'])
        ->middleware('permission:incidents.update')
        ->whereNumber('client')
        ->name('operations.clients.incidents.attachments.store');
    Route::get('/clients/{client}/incidents/{incident}/attachments/{attachment}/download', [ClientIncidentController::class, 'downloadAttachment'])
        ->middleware('permission:incidents.viewAny|incidents.viewAssigned')
        ->whereNumber('client')
        ->name('operations.clients.incidents.attachments.download');

    // Risk register
    Route::middleware('permission:risks.viewAny|risks.viewAssigned')->group(function () {
        Route::get('/clients/{client}/risks', [ClientRiskController::class, 'index'])
            ->whereNumber('client')
            ->name('operations.clients.risks.index');
    });

    Route::post('/clients/{client}/risks', [ClientRiskController::class, 'store'])
        ->middleware('permission:risks.create')
        ->whereNumber('client')
        ->name('operations.clients.risks.store');
    Route::put('/clients/{client}/risks/{risk}', [ClientRiskController::class, 'update'])
        ->middleware('permission:risks.update')
        ->whereNumber('client')
        ->name('operations.clients.risks.update');
    Route::delete('/clients/{client}/risks/{risk}', [ClientRiskController::class, 'destroy'])
        ->middleware('permission:risks.delete')
        ->whereNumber('client')
        ->name('operations.clients.risks.destroy');

    // -------------------------------------------------------------------------
    // Care Plans (NEW)
    // -------------------------------------------------------------------------

    Route::middleware('permission:care_plans.create')->group(function () {
        Route::get('/care-plans/create', [CarePlanController::class, 'create'])->name('operations.care_plans.create');
        Route::post('/care-plans', [CarePlanController::class, 'store'])->name('operations.care_plans.store');
    });

    Route::middleware('permission:care_plans.viewAny')->group(function () {
        Route::get('/care-plans', [CarePlanController::class, 'index'])->name('operations.care_plans.index');
        Route::get('/care-plans/{carePlan}', [CarePlanController::class, 'show'])->name('operations.care_plans.show');
    });

    Route::middleware('permission:care_plans.update')->group(function () {
        Route::get('/care-plans/{carePlan}/edit', [CarePlanController::class, 'edit'])->name('operations.care_plans.edit');
        Route::put('/care-plans/{carePlan}', [CarePlanController::class, 'update'])->name('operations.care_plans.update');

        // Care plan goals
        Route::post('/care-plans/{carePlan}/goals', [CarePlanGoalController::class, 'store'])
            ->name('operations.care_plans.goals.store');
        Route::put('/care-plans/{carePlan}/goals/{goal}', [CarePlanGoalController::class, 'update'])
            ->name('operations.care_plans.goals.update');
        Route::delete('/care-plans/{carePlan}/goals/{goal}', [CarePlanGoalController::class, 'destroy'])
            ->name('operations.care_plans.goals.destroy');
        Route::patch('/care-plans/{carePlan}/goals/{goal}/progress', [CarePlanGoalController::class, 'updateProgress'])
            ->name('operations.care_plans.goals.progress');

        Route::post('/care-plans/{carePlan}/start-review', [CarePlanController::class, 'startReview'])->name('operations.care_plans.start_review');
        Route::post('/care-plans/{carePlan}/complete-review', [CarePlanController::class, 'completeReview'])->name('operations.care_plans.complete_review');
    });

    Route::delete('/care-plans/{carePlan}', [CarePlanController::class, 'destroy'])
        ->middleware('permission:care_plans.delete')
        ->name('operations.care_plans.destroy');

    // -------------------------------------------------------------------------
    // Service Agreements (NEW)
    // -------------------------------------------------------------------------

    Route::middleware('permission:service_agreements.create')->group(function () {
        Route::get('/service-agreements/create', [ServiceAgreementController::class, 'create'])->name('operations.service_agreements.create');
        Route::post('/service-agreements', [ServiceAgreementController::class, 'store'])->name('operations.service_agreements.store');
    });

    Route::middleware('permission:service_agreements.viewAny')->group(function () {
        Route::get('/service-agreements', [ServiceAgreementController::class, 'index'])->name('operations.service_agreements.index');
        Route::get('/service-agreements/{agreement}', [ServiceAgreementController::class, 'show'])->name('operations.service_agreements.show');
    });

    Route::middleware('permission:service_agreements.update')->group(function () {
        Route::get('/service-agreements/{agreement}/edit', [ServiceAgreementController::class, 'edit'])->name('operations.service_agreements.edit');
        Route::put('/service-agreements/{agreement}', [ServiceAgreementController::class, 'update'])->name('operations.service_agreements.update');
        Route::post('/service-agreements/{serviceAgreement}/transition', [ServiceAgreementController::class, 'transition'])->name('operations.service_agreements.transition');
        Route::post('/service-agreements/{serviceAgreement}/submit-for-approval', [ServiceAgreementController::class, 'submitForApproval'])->name('operations.service_agreements.submit_for_approval');
        Route::post('/service-agreements/{serviceAgreement}/approve', [ServiceAgreementController::class, 'approve'])->name('operations.service_agreements.approve');
        Route::post('/service-agreements/{serviceAgreement}/reject', [ServiceAgreementController::class, 'reject'])->name('operations.service_agreements.reject');
    });

    Route::delete('/service-agreements/{agreement}', [ServiceAgreementController::class, 'destroy'])
        ->middleware('permission:service_agreements.delete')
        ->name('operations.service_agreements.destroy');

    Route::middleware('permission:service_agreements.update')->group(function () {
        Route::post('/service-agreements/{serviceAgreement}/line-items', [ServiceAgreementController::class, 'storeLineItem']);
        Route::put('/service-agreements/{serviceAgreement}/line-items/{lineItem}', [ServiceAgreementController::class, 'updateLineItem']);
        Route::delete('/service-agreements/{serviceAgreement}/line-items/{lineItem}', [ServiceAgreementController::class, 'destroyLineItem']);
        Route::post('/service-agreements/{serviceAgreement}/rates', [ServiceAgreementController::class, 'storeRate']);
        Route::delete('/service-agreements/{serviceAgreement}/rates/{rate}', [ServiceAgreementController::class, 'destroyRate']);
    });

    // -------------------------------------------------------------------------
    // Progress Notes (NEW)
    // -------------------------------------------------------------------------

    Route::get('/progress-notes', [ProgressNoteController::class, 'index'])
        ->middleware('permission:progress_notes.viewAny')
        ->name('operations.progress_notes.index');
    Route::post('/progress-notes', [ProgressNoteController::class, 'store'])
        ->middleware('permission:progress_notes.create')
        ->name('operations.progress_notes.store');
    Route::put('/progress-notes/{note}', [ProgressNoteController::class, 'update'])
        ->middleware('permission:progress_notes.update')
        ->name('operations.progress_notes.update');
    Route::delete('/progress-notes/{note}', [ProgressNoteController::class, 'destroy'])
        ->middleware('permission:progress_notes.delete')
        ->name('operations.progress_notes.destroy');

    // -------------------------------------------------------------------------
    // Shifts (migrated from /shifts)
    // -------------------------------------------------------------------------

    // PR 18 — the shifts index is the scheduler's table. Frontline staff see
    // their own shifts on `/my-day` and should not land on the scheduler list.
    // Individual shift detail (`/shifts/{shift}`) remains accessible because
    // workers legitimately click through to their own assigned shift.
    Route::get('/shifts', [ShiftController::class, 'index'])
        ->middleware(['permission:shifts.viewAny|shifts.viewAssigned', 'role_scope:my-day'])
        ->name('operations.shifts.index');

    Route::get('/shifts/{shift}', [ShiftController::class, 'show'])
        ->whereNumber('shift')
        ->middleware('permission:shifts.viewAny|shifts.viewAssigned')
        ->name('operations.shifts.show');

    // Shift creation
    Route::get('/shifts/create', [ShiftController::class, 'create'])
        ->middleware('permission:shifts.create')
        ->name('operations.shifts.create');
    Route::post('/shifts', [ShiftController::class, 'store'])
        ->middleware('permission:shifts.create')
        ->name('operations.shifts.store');
    Route::post('/coverage/reservations', [CoverageReservationController::class, 'store'])
        ->middleware('permission:shifts.create|shifts.manageAny')
        ->name('operations.coverage.reservations.store');
    Route::get('/shifts/eligibility-preview', [ShiftController::class, 'eligibilityPreview'])
        ->middleware('permission:shifts.create|shifts.update')
        ->name('operations.shifts.eligibility_preview');

    // Recurring shifts (weekly series) — manager/scheduler surface.
    // PR 18: belt-and-braces role_scope ensures a frontline worker never lands
    // here via a stale link even if `shifts.viewAny` is inadvertently granted.
    Route::get('/shifts/series', [ShiftSeriesController::class, 'index'])
        ->middleware(['role_scope:my-day', 'permission:rostering.viewAny|shifts.viewAny|shifts.manageAny'])
        ->name('operations.shifts.series.index');
    Route::get('/shifts/series/{series}', [ShiftSeriesController::class, 'show'])
        ->middleware(['role_scope:my-day', 'permission:rostering.viewAny|shifts.viewAny|shifts.manageAny'])
        ->name('operations.shifts.series.show');
    Route::post('/shifts/series', [ShiftSeriesController::class, 'store'])
        ->middleware('permission:shifts.create')
        ->name('operations.shifts.series.store');
    Route::patch('/shifts/series/{series}/cancel-future', [ShiftSeriesController::class, 'cancelFuture'])
        ->middleware('permission:rostering.viewAny|shifts.manageAny')
        ->name('operations.shifts.series.cancel_future');

    // Shift updates
    Route::get('/shifts/{shift}/edit', [ShiftController::class, 'edit'])
        ->middleware('permission:shifts.update')
        ->name('operations.shifts.edit');
    Route::put('/shifts/{shift}', [ShiftController::class, 'update'])
        ->middleware('permission:shifts.update')
        ->name('operations.shifts.update');

    // Roster planning (assign/unassign)
    Route::post('/shifts/{shift}/assign', [ShiftController::class, 'assign'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.assign');
    Route::post('/shifts/{shift}/unassign', [ShiftController::class, 'unassign'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.unassign');

    // Shift lifecycle
    Route::patch('/shifts/{shift}/start', [ShiftController::class, 'start'])
        ->middleware('permission:shifts.update|shifts.manageAny')
        ->name('operations.shifts.start');
    Route::patch('/shifts/{shift}/complete', [ShiftController::class, 'complete'])
        ->middleware('permission:shifts.update|shifts.manageAny')
        ->name('operations.shifts.complete');
    Route::patch('/shifts/{shift}/cancel', [ShiftController::class, 'cancelOccurrence'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.cancel');
    Route::patch('/shifts/{shift}/reopen', [ShiftController::class, 'reopenOccurrence'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.reopen');
    Route::post('/shifts/{shift}/replacement-request', [ShiftController::class, 'requestReplacement'])
        ->middleware('permission:shifts.update|shifts.manageAny')
        ->name('operations.shifts.replacement.request');
    Route::patch('/shifts/{shift}/replacement-request/cancel', [ShiftController::class, 'cancelReplacement'])
        ->middleware('permission:shifts.update|shifts.manageAny')
        ->name('operations.shifts.replacement.cancel');

    // Shift tasks
    Route::patch('/shifts/{shift}/tasks/{task}', [ShiftTaskController::class, 'update'])
        ->middleware('permission:shifts.update|shifts.tasks.updateSelf|shifts.manageAny')
        ->name('operations.shifts.tasks.update');

    // -------------------------------------------------------------------------
    // Shift Notes (NEW)
    // -------------------------------------------------------------------------

    Route::get('/shift-notes', [ShiftNoteController::class, 'index'])->name('operations.shift_notes.index');
    Route::get('/shift-notes/export', [ShiftNoteController::class, 'export'])->name('operations.shift_notes.export');
    Route::patch('/shift-notes/{note}/flag', [ShiftNoteController::class, 'flag'])->name('operations.shift_notes.flag');
    Route::patch('/shift-notes/{note}/review', [ShiftNoteController::class, 'markReviewed'])->name('operations.shift_notes.review');

    // -------------------------------------------------------------------------
    // Handovers (NEW)
    // -------------------------------------------------------------------------

    Route::get('/handovers', [HandoverController::class, 'index'])
        ->middleware('permission:handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny')
        ->name('operations.handovers.index');
    Route::get('/handovers/{handover}', [HandoverController::class, 'show'])
        ->middleware('permission:handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny')
        ->name('operations.handovers.show');
    Route::post('/shifts/{shift}/handover', [HandoverController::class, 'store'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny')
        ->name('operations.shifts.handover.store');
    Route::patch('/handovers/{handover}/submit', [HandoverController::class, 'submit'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny')
        ->name('operations.handovers.submit');
    Route::patch('/handovers/{handover}/acknowledge', [HandoverController::class, 'acknowledge'])
        ->middleware('permission:handovers.viewAny|shifts.viewAny|shifts.viewAssigned|shifts.update|shifts.manageAny')
        ->name('operations.handovers.acknowledge');

    // -------------------------------------------------------------------------
    // Rostering (migrated from /rostering)
    // -------------------------------------------------------------------------

    // PR 18 — rostering is the scheduler surface. `rostering.viewAny` already
    // excludes support workers, but `role_scope` layers a friendly redirect
    // to `/my-day` on top of the 403 so any staff user who lands here via an
    // old bookmark gets routed home instead of hitting an error page.
    Route::middleware(['role_scope:my-day', 'permission:rostering.viewAny'])->group(function () {
        Route::get('/rostering', [RosteringController::class, 'index'])->name('operations.rostering.index');
        Route::get('/rostering/conflicts', [RosteringController::class, 'conflicts'])->name('operations.rostering.conflicts');
        Route::post('/rostering/coverage/{key}/ack', [CoverageGapController::class, 'ack'])
            ->name('operations.rostering.coverage.ack');
        Route::post('/rostering/coverage/{key}/dismiss', [CoverageGapController::class, 'dismiss'])
            ->name('operations.rostering.coverage.dismiss');
        Route::delete('/rostering/coverage/{key}/clear', [CoverageGapController::class, 'clear'])
            ->name('operations.rostering.coverage.clear');
    });

    // Roster templates
    Route::middleware('permission:roster_templates.create')->group(function () {
        Route::get('/rostering/templates/create', [RosterTemplateController::class, 'create'])->name('operations.rostering.templates.create');
        Route::post('/rostering/templates', [RosterTemplateController::class, 'store'])->name('operations.rostering.templates.store');
    });

    Route::middleware('permission:roster_templates.viewAny')->group(function () {
        Route::get('/rostering/templates', [RosterTemplateController::class, 'index'])->name('operations.rostering.templates.index');
        Route::get('/rostering/templates/{template}', [RosterTemplateController::class, 'show'])->name('operations.rostering.templates.show');
    });

    Route::middleware('permission:roster_templates.update')->group(function () {
        Route::get('/rostering/templates/{template}/edit', [RosterTemplateController::class, 'edit'])->name('operations.rostering.templates.edit');
        Route::put('/rostering/templates/{template}', [RosterTemplateController::class, 'update'])->name('operations.rostering.templates.update');
        Route::post('/rostering/templates/{template}/apply', [RosterTemplateController::class, 'apply'])->name('operations.rostering.templates.apply');
    });

    Route::delete('/rostering/templates/{template}', [RosterTemplateController::class, 'destroy'])
        ->middleware('permission:roster_templates.delete')
        ->name('operations.rostering.templates.destroy');

    // Auto-scheduling
    Route::post('/rostering/auto-schedule', [RosteringController::class, 'autoSchedule'])
        ->middleware('permission:rostering.autoSchedule')
        ->name('operations.rostering.auto_schedule');

    // Time-off/unavailability blocks shown on the rostering board.
    Route::middleware('permission:staff.availability.updateAny|staff.availability.updateSelf')->group(function () {
        Route::post('/rostering/time-off', [StaffTimeOffController::class, 'store'])
            ->name('operations.rostering.time_off.store');
        Route::delete('/rostering/time-off/{staffTimeOff}', [StaffTimeOffController::class, 'destroy'])
            ->name('operations.rostering.time_off.destroy');
    });

    Route::middleware('permission:rostering.publish')->group(function () {
        Route::get('/rostering/periods/{period}/review', [RosteringController::class, 'viewPublishReview'])
            ->name('operations.rostering.periods.review.show');
        Route::get('/rostering/periods/{period}/diff', [RosteringController::class, 'viewDiff'])
            ->name('operations.rostering.periods.diff');
        Route::post('/rostering/periods/{period}/review', [RosteringController::class, 'reviewForPublish'])
            ->name('operations.rostering.periods.review');
        Route::post('/rostering/periods/{period}/publish', [RosteringController::class, 'confirmPublish'])
            ->name('operations.rostering.periods.publish');
        Route::post('/rostering/periods/{period}/republish', [RosteringController::class, 'republish'])
            ->name('operations.rostering.periods.republish');
        Route::post('/rostering/periods/{period}/unpublish', [RosteringController::class, 'unpublish'])
            ->name('operations.rostering.periods.unpublish');
    });

    Route::middleware('permission:rostering.autoSchedule')->group(function () {
        Route::get('/rostering/suggestions/{run}', [RosterSuggestionController::class, 'show'])
            ->name('operations.rostering.suggestions.show');
        Route::post('/rostering/suggestions/{suggestion}/accept', [RosterSuggestionController::class, 'accept'])
            ->name('operations.rostering.suggestions.accept');
        Route::post('/rostering/suggestions/{suggestion}/dismiss', [RosterSuggestionController::class, 'dismiss'])
            ->name('operations.rostering.suggestions.dismiss');
        Route::post('/rostering/suggestions/{suggestion}/apply', [RosterSuggestionController::class, 'apply'])
            ->name('operations.rostering.suggestions.apply');
        Route::post('/rostering/suggestions/{run}/apply-accepted', [RosterSuggestionController::class, 'applyAccepted'])
            ->name('operations.rostering.suggestions.apply_accepted');
    });

    // -------------------------------------------------------------------------
    // Availability (NEW)
    // -------------------------------------------------------------------------

    // PR 18 — availability planner is scheduler-first. Permission already
    // filters support workers; role_scope adds the soft redirect home.
    Route::get('/availability', [AvailabilityController::class, 'index'])
        ->middleware(['role_scope:my-day', 'permission:rostering.viewAny'])
        ->name('operations.availability.index');

    // -------------------------------------------------------------------------
    // Timesheets (migrated from /timesheets)
    // -------------------------------------------------------------------------

    Route::get('/timesheets', [TimesheetController::class, 'index'])
        ->middleware('permission:timesheets.viewAny|timesheets.viewAssigned')
        ->name('operations.timesheets.index');

    // Manager/Admin approval queue
    // PR 18 — role_scope redirects frontline staff to `/my-day` (their own
    // timesheet list stays on `operations.timesheets.index`, which is not
    // gated here because workers legitimately review their own sheets).
    Route::middleware(['role_scope:my-day', 'permission:timesheets.approve|timesheets.manageAny'])->group(function () {
        Route::get('/timesheets/approvals', [TimesheetController::class, 'approvals'])->name('operations.timesheets.approvals');
        Route::get('/timesheets/payroll-adjustments', [TimesheetController::class, 'payrollAdjustmentsPending'])->name('operations.timesheets.payrollAdjustments');
        Route::post('/timesheets/amendments/{amendment}/mark-processed', [TimesheetController::class, 'markPayrollAdjustmentProcessed'])->name('operations.timesheets.markPayrollProcessed');
        Route::post('/timesheets/bulk-approve', [TimesheetController::class, 'bulkApprove'])->name('operations.timesheets.bulkApprove');
        Route::post('/timesheets/bulk-return', [TimesheetController::class, 'bulkReturnForChanges'])->name('operations.timesheets.bulkReturn');
        Route::post('/timesheets/bulk-reject', [TimesheetController::class, 'bulkReject'])->name('operations.timesheets.bulkReject');
    });

    // Timesheet creation
    Route::get('/timesheets/create', [TimesheetController::class, 'create'])
        ->middleware('permission:timesheets.create')
        ->name('operations.timesheets.create');
    Route::post('/timesheets', [TimesheetController::class, 'store'])
        ->middleware('permission:timesheets.create')
        ->name('operations.timesheets.store');

    // Timesheet viewing and editing
    Route::get('/timesheets/{timesheet}', [TimesheetController::class, 'show'])
        ->middleware('permission:timesheets.viewAny|timesheets.viewAssigned')
        ->name('operations.timesheets.show');
    Route::get('/timesheets/{timesheet}/edit', [TimesheetController::class, 'edit'])
        ->middleware('permission:timesheets.viewAny|timesheets.viewAssigned')
        ->name('operations.timesheets.edit');
    Route::put('/timesheets/{timesheet}', [TimesheetController::class, 'update'])
        ->middleware('permission:timesheets.update')
        ->name('operations.timesheets.update');

    // Timesheet workflow
    Route::post('/timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])
        ->middleware('permission:timesheets.submit|timesheets.manageAny')
        ->name('operations.timesheets.submit');
    // Atomic save-and-resubmit (used by the inline edit sheet on /my-day so a
    // returned timesheet's update + submit cannot land in a half-finished
    // state if the second call fails).
    Route::post('/timesheets/{timesheet}/resubmit', [TimesheetController::class, 'resubmit'])
        ->middleware('permission:timesheets.update|timesheets.manageAny')
        ->name('operations.timesheets.resubmit');
    Route::post('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('operations.timesheets.approve');
    Route::post('/timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('operations.timesheets.reject');
    Route::post('/timesheets/{timesheet}/return', [TimesheetController::class, 'returnForChanges'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('operations.timesheets.return');

    // -------------------------------------------------------------------------
    // Billing (NEW)
    // -------------------------------------------------------------------------

    Route::middleware('permission:billing.viewAny')->group(function () {
        Route::get('/billing', [BillingController::class, 'index'])->name('operations.billing.index');
        Route::get('/billing/entries', [BillingController::class, 'entries'])->name('operations.billing.entries');
    });

    // -------------------------------------------------------------------------
    // Invoices (NEW)
    // -------------------------------------------------------------------------

    Route::middleware('permission:invoices.create')->group(function () {
        Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('operations.invoices.create');
        Route::post('/invoices', [InvoiceController::class, 'store'])->name('operations.invoices.store');
    });

    Route::middleware('permission:invoices.viewAny')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('operations.invoices.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('operations.invoices.show');
    });

    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->middleware('permission:invoices.send')
        ->name('operations.invoices.send');
    Route::patch('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
        ->middleware('permission:invoices.update')
        ->name('operations.invoices.mark_paid');
    Route::patch('/invoices/{invoice}/void', [InvoiceController::class, 'void'])
        ->middleware('permission:invoices.void')
        ->name('operations.invoices.void');

    // -------------------------------------------------------------------------
    // Funding (NEW)
    // -------------------------------------------------------------------------

    Route::get('/funding', [FundingController::class, 'index'])
        ->middleware('permission:funding.viewAny')
        ->name('operations.funding.index');

    // Funding claims
    Route::get('/funding/claims', [FundingClaimController::class, 'index'])
        ->middleware('permission:funding.viewAny')
        ->name('operations.funding.claims.index');

    Route::middleware('permission:funding.claims.create')->group(function () {
        Route::get('/funding/claims/create', [FundingClaimController::class, 'create'])->name('operations.funding.claims.create');
        Route::post('/funding/claims', [FundingClaimController::class, 'store'])->name('operations.funding.claims.store');
    });

    Route::get('/funding/claims/{claim}', [FundingClaimController::class, 'show'])
        ->middleware('permission:funding.viewAny')
        ->name('operations.funding.claims.show');

    Route::post('/funding/claims/{claim}/submit', [FundingClaimController::class, 'submit'])
        ->middleware('permission:funding.claims.submit')
        ->name('operations.funding.claims.submit');
    Route::post('/funding/claims/{claim}/approve', [FundingClaimController::class, 'approve'])
        ->middleware('permission:funding.claims.approve')
        ->name('operations.funding.claims.approve');

    // -------------------------------------------------------------------------
    // Messages (NEW)
    // -------------------------------------------------------------------------

    Route::get('/messages', [MessageController::class, 'index'])->name('operations.messages.index');
    Route::post('/messages/create', [MessageController::class, 'createConversation'])->name('operations.messages.create');
    Route::get('/messages/{conversation}', [MessageController::class, 'show'])->name('operations.messages.show');
    Route::post('/messages/{conversation}', [MessageController::class, 'store'])->name('operations.messages.store');
    Route::patch('/messages/{conversation}/read', [MessageController::class, 'markRead'])->name('operations.messages.read');
    Route::post('/messages/react/{message}', [MessageController::class, 'toggleReaction'])->name('operations.messages.react');
    Route::post('/messages/pin/{message}', [MessageController::class, 'togglePin'])->name('operations.messages.pin');
    Route::get('/messages-search', [MessageController::class, 'searchMessages'])->name('operations.messages.search');
    Route::delete('/messages/archive/{message}', [MessageController::class, 'archiveMessage'])->name('operations.messages.archive');

    // -------------------------------------------------------------------------
    // Reports (NEW)
    // -------------------------------------------------------------------------

    Route::middleware('permission:operations.reports.view')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('operations.reports.index');
        Route::get('/reports/shifts', [ShiftReportController::class, 'index'])->name('operations.reports.shifts.index');
        Route::get('/reports/shifts/export', [ShiftReportController::class, 'export'])->name('operations.reports.shifts.export');
        Route::get('/reports/{type}', [ReportController::class, 'show'])->name('operations.reports.show');
    });

    // -------------------------------------------------------------------------
    // Price Books (Phase 7)
    // -------------------------------------------------------------------------

    Route::middleware('permission:price_books.create')->group(function () {
        Route::get('/price-books/create', [PriceBookController::class, 'create'])->name('operations.price_books.create');
        Route::post('/price-books', [PriceBookController::class, 'store'])->name('operations.price_books.store');
    });
    Route::middleware('permission:price_books.viewAny')->group(function () {
        Route::get('/price-books', [PriceBookController::class, 'index'])->name('operations.price_books.index');
        Route::get('/price-books/{priceBook}', [PriceBookController::class, 'show'])->name('operations.price_books.show');
    });
    Route::middleware('permission:price_books.update')->group(function () {
        Route::get('/price-books/{priceBook}/edit', [PriceBookController::class, 'edit'])->name('operations.price_books.edit');
        Route::put('/price-books/{priceBook}', [PriceBookController::class, 'update'])->name('operations.price_books.update');
        // Price book items
        Route::post('/price-books/{priceBook}/items', [PriceBookController::class, 'storeItem'])->name('operations.price_books.items.store');
        Route::put('/price-books/{priceBook}/items/{item}', [PriceBookController::class, 'updateItem'])->name('operations.price_books.items.update');
        Route::delete('/price-books/{priceBook}/items/{item}', [PriceBookController::class, 'destroyItem'])->name('operations.price_books.items.destroy');
    });

    // -------------------------------------------------------------------------
    // Quotes / Proposals (Phase 7)
    // -------------------------------------------------------------------------

    Route::middleware('permission:quotes.create')->group(function () {
        Route::get('/quotes/create', [QuoteController::class, 'create'])->name('operations.quotes.create');
        Route::post('/quotes', [QuoteController::class, 'store'])->name('operations.quotes.store');
    });
    Route::middleware('permission:quotes.viewAny')->group(function () {
        Route::get('/quotes', [QuoteController::class, 'index'])->name('operations.quotes.index');
        Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->name('operations.quotes.show');
    });
    Route::middleware('permission:quotes.update')->group(function () {
        Route::get('/quotes/{quote}/edit', [QuoteController::class, 'edit'])->name('operations.quotes.edit');
        Route::put('/quotes/{quote}', [QuoteController::class, 'update'])->name('operations.quotes.update');
        Route::post('/quotes/{quote}/send', [QuoteController::class, 'send'])->name('operations.quotes.send');
        Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('operations.quotes.accept');
        Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convertToAgreement'])->name('operations.quotes.convert');
    });

    // -------------------------------------------------------------------------
    // Client Onboarding (Phase 7)
    // -------------------------------------------------------------------------

    Route::middleware('permission:clients.viewAny')->group(function () {
        Route::get('/onboarding', [ClientOnboardingWorkflowController::class, 'index'])->name('operations.onboarding.index');
    });
    Route::middleware('permission:clients.create')->group(function () {
        Route::get('/onboarding/create', [ClientOnboardingWorkflowController::class, 'create'])->name('operations.onboarding.create');
        Route::post('/onboarding', [ClientOnboardingWorkflowController::class, 'store'])->name('operations.onboarding.store');
        Route::patch('/onboarding/{workflow}/steps/{step}', [ClientOnboardingWorkflowController::class, 'updateStep'])->name('operations.onboarding.steps.update');
        Route::post('/onboarding/{workflow}/complete', [ClientOnboardingWorkflowController::class, 'complete'])->name('operations.onboarding.complete');
    });
    Route::middleware('permission:clients.viewAny')->group(function () {
        Route::get('/onboarding/{workflow}', [ClientOnboardingWorkflowController::class, 'show'])->name('operations.onboarding.show');
    });

    // -------------------------------------------------------------------------
    // Client Funds (Phase 7)
    // -------------------------------------------------------------------------

    Route::middleware('permission:client_funds.manage')->group(function () {
        Route::get('/client-funds/create', [ClientFundController::class, 'create'])->name('operations.client_funds.create');
        Route::post('/client-funds', [ClientFundController::class, 'store'])->name('operations.client_funds.store');
        Route::put('/client-funds/{fund}', [ClientFundController::class, 'update'])->name('operations.client_funds.update');
        Route::post('/client-funds/{fund}/transactions', [ClientFundController::class, 'addTransaction'])->name('operations.client_funds.transactions.store');
    });
    Route::middleware('permission:clients.viewAny')->group(function () {
        Route::get('/client-funds', [ClientFundController::class, 'index'])->name('operations.client_funds.index');
        Route::get('/client-funds/{fund}', [ClientFundController::class, 'show'])->name('operations.client_funds.show');
    });

    // -------------------------------------------------------------------------
    // Job Board / Open Shifts (Phase 8)
    // -------------------------------------------------------------------------

    Route::get('/job-board', [JobBoardController::class, 'index'])
        ->middleware('permission:job_board.viewAny|shifts.viewAny|shifts.viewAssigned')
        ->name('operations.job_board.index');
    Route::post('/job-board/{position}/claim', [JobBoardController::class, 'claim'])
        ->middleware('permission:job_board.claim|shifts.viewAssigned|shifts.manageAny')
        ->name('operations.job_board.claim');
    Route::post('/job-board/{position}/approve', [JobBoardController::class, 'approve'])
        ->middleware('permission:job_board.approve|shifts.manageAny')
        ->name('operations.job_board.approve');
    Route::post('/shifts/{shift}/open-position', [JobBoardController::class, 'createPosition'])
        ->middleware('permission:job_board.create|shifts.manageAny')
        ->name('operations.job_board.create');

    // -------------------------------------------------------------------------
    // Custom Forms (Phase 8)
    // -------------------------------------------------------------------------

    Route::middleware('permission:custom_forms.create')->group(function () {
        Route::get('/forms/create', [CustomFormController::class, 'create'])->name('operations.forms.create');
        Route::post('/forms', [CustomFormController::class, 'store'])->name('operations.forms.store');
    });
    Route::middleware('permission:custom_forms.viewAny')->group(function () {
        Route::get('/forms', [CustomFormController::class, 'index'])->name('operations.forms.index');
        Route::get('/forms/{form}', [CustomFormController::class, 'show'])->name('operations.forms.show');
        Route::get('/forms/{form}/submissions', [CustomFormController::class, 'submissions'])->name('operations.forms.submissions');
    });
    Route::middleware('permission:custom_forms.update')->group(function () {
        Route::get('/forms/{form}/edit', [CustomFormController::class, 'edit'])->name('operations.forms.edit');
        Route::put('/forms/{form}', [CustomFormController::class, 'update'])->name('operations.forms.update');
    });
    Route::post('/forms/{form}/submit', [CustomFormController::class, 'submitForm'])
        ->middleware('permission:custom_forms.submit')
        ->name('operations.forms.submit');

    // -------------------------------------------------------------------------
    // EVV - Electronic Visit Verification (Phase 8)
    // -------------------------------------------------------------------------

    Route::middleware('permission:evv.viewAny')->group(function () {
        Route::get('/evv', [EvvController::class, 'index'])->name('operations.evv.index');
        Route::get('/evv/{record}', [EvvController::class, 'show'])->name('operations.evv.show');
    });
    Route::post('/evv/check-in', [EvvController::class, 'checkIn'])
        ->middleware('permission:evv.record')
        ->name('operations.evv.check_in');
    Route::post('/evv/check-out', [EvvController::class, 'checkOut'])
        ->middleware('permission:evv.record')
        ->name('operations.evv.check_out');
    Route::patch('/evv/{record}/verify', [EvvController::class, 'verify'])
        ->middleware('permission:evv.verify')
        ->name('operations.evv.verify');

    // -------------------------------------------------------------------------
    // Family Portal Settings (Phase 9)
    // -------------------------------------------------------------------------

    Route::middleware('permission:family_portal.viewAny|clients.update')->group(function () {
        Route::get('/family-portal', [FamilyPortalController::class, 'index'])->name('operations.family_portal.index');
        Route::get('/family-portal/{client}', [FamilyPortalController::class, 'show'])->name('operations.family_portal.show');
    });

    Route::middleware('permission:family_portal.manage|clients.update')->group(function () {
        Route::get('/family-portal/{client}/edit', [FamilyPortalController::class, 'edit'])->name('operations.family_portal.edit');
        Route::put('/family-portal/{client}', [FamilyPortalController::class, 'update'])->name('operations.family_portal.update');
    });

    // -------------------------------------------------------------------------
    // Mileage Claims (Phase 9)
    // -------------------------------------------------------------------------

    Route::get('/mileage', [MileageClaimController::class, 'index'])
        ->middleware('permission:mileage.viewAny|mileage.viewOwn')
        ->name('operations.mileage.index');
    Route::get('/mileage/create', [MileageClaimController::class, 'create'])
        ->middleware('permission:mileage.create')
        ->name('operations.mileage.create');
    Route::post('/mileage', [MileageClaimController::class, 'store'])
        ->middleware('permission:mileage.create')
        ->name('operations.mileage.store');
    Route::post('/mileage/{claim}/submit', [MileageClaimController::class, 'submit'])
        ->middleware('permission:mileage.create')
        ->name('operations.mileage.submit');
    Route::post('/mileage/{claim}/approve', [MileageClaimController::class, 'approve'])
        ->middleware('permission:mileage.approve')
        ->name('operations.mileage.approve');

    // -------------------------------------------------------------------------
    // Recurring Charges (Phase 9)
    // -------------------------------------------------------------------------

    Route::middleware('permission:billing.viewAny')->group(function () {
        Route::get('/recurring-charges', [RecurringChargeController::class, 'index'])->name('operations.recurring_charges.index');
        Route::get('/recurring-charges/create', [RecurringChargeController::class, 'create'])->name('operations.recurring_charges.create');
        Route::post('/recurring-charges', [RecurringChargeController::class, 'store'])->name('operations.recurring_charges.store');
        Route::get('/recurring-charges/{charge}/edit', [RecurringChargeController::class, 'edit'])->name('operations.recurring_charges.edit');
        Route::put('/recurring-charges/{charge}', [RecurringChargeController::class, 'update'])->name('operations.recurring_charges.update');
        Route::delete('/recurring-charges/{charge}', [RecurringChargeController::class, 'destroy'])->name('operations.recurring_charges.destroy');
    });

    // -------------------------------------------------------------------------
    // Qualification Matching (Phase 10)
    // -------------------------------------------------------------------------

    Route::middleware('permission:rostering.viewAny')->group(function () {
        Route::get('/qualifications', [QualificationMatchController::class, 'index'])->name('operations.qualifications.index');
        Route::post('/qualifications', [QualificationMatchController::class, 'store'])->name('operations.qualifications.store');
        Route::put('/qualifications/{requirement}', [QualificationMatchController::class, 'update'])->name('operations.qualifications.update');
        Route::delete('/qualifications/{requirement}', [QualificationMatchController::class, 'destroy'])->name('operations.qualifications.destroy');
        Route::get('/qualifications/check/{shift}', [QualificationMatchController::class, 'checkShift'])->name('operations.qualifications.check');
    });

    // -------------------------------------------------------------------------
    // Notifications (Phase 10)
    // -------------------------------------------------------------------------

    Route::get('/notifications', [OpsNotificationController::class, 'index'])->name('operations.notifications.index');
    Route::patch('/notifications/{notification}/read', [OpsNotificationController::class, 'markRead'])->name('operations.notifications.read');
    Route::post('/notifications/mark-all-read', [OpsNotificationController::class, 'markAllRead'])->name('operations.notifications.read_all');

    // -------------------------------------------------------------------------
    // Care Note Templates (Phase 10)
    // -------------------------------------------------------------------------

    Route::middleware('permission:care_note_templates.viewAny')->group(function () {
        Route::get('/note-templates', [CareNoteTemplateController::class, 'index'])->name('operations.note_templates.index');
        Route::get('/note-templates/create', [CareNoteTemplateController::class, 'create'])->name('operations.note_templates.create');
        Route::post('/note-templates', [CareNoteTemplateController::class, 'store'])->name('operations.note_templates.store');
        Route::get('/note-templates/{template}/edit', [CareNoteTemplateController::class, 'edit'])->name('operations.note_templates.edit');
        Route::put('/note-templates/{template}', [CareNoteTemplateController::class, 'update'])->name('operations.note_templates.update');
        Route::delete('/note-templates/{template}', [CareNoteTemplateController::class, 'destroy'])->name('operations.note_templates.destroy');
    });

    // -------------------------------------------------------------------------
    // Payroll Export (Phase 11)
    // -------------------------------------------------------------------------

    Route::middleware('permission:payroll.export')->group(function () {
        Route::get('/payroll-export', [PayrollExportController::class, 'index'])->name('operations.payroll_export.index');
        Route::get('/payroll-export/create', [PayrollExportController::class, 'create'])->name('operations.payroll_export.create');
        Route::post('/payroll-export', [PayrollExportController::class, 'generate'])->name('operations.payroll_export.generate');
        Route::get('/payroll-export/{export}/download', [PayrollExportController::class, 'download'])->name('operations.payroll_export.download');
        Route::post('/payroll-export/{export}/confirm', [PayrollExportController::class, 'confirm'])->name('operations.payroll_export.confirm');
    });

    // -------------------------------------------------------------------------
    // Calendar Sync (Phase 11)
    // -------------------------------------------------------------------------

    Route::get('/calendar-sync', [CalendarSyncController::class, 'index'])->name('operations.calendar_sync.index');
    Route::get('/calendar-sync/create', [CalendarSyncController::class, 'create'])->name('operations.calendar_sync.create');
    Route::post('/calendar-sync', [CalendarSyncController::class, 'store'])->name('operations.calendar_sync.store');
    Route::delete('/calendar-sync/{sync}', [CalendarSyncController::class, 'destroy'])->name('operations.calendar_sync.destroy');
    Route::post('/calendar-sync/{sync}/trigger', [CalendarSyncController::class, 'triggerSync'])->name('operations.calendar_sync.trigger');

    // -------------------------------------------------------------------------
    // Geofence Zones (Phase 12)
    // -------------------------------------------------------------------------

    Route::middleware('permission:evv.viewAny')->group(function () {
        Route::get('/geofences', [GeofenceController::class, 'index'])->name('operations.geofences.index');
        Route::get('/geofences/create', [GeofenceController::class, 'create'])->name('operations.geofences.create');
        Route::post('/geofences', [GeofenceController::class, 'store'])->name('operations.geofences.store');
        Route::put('/geofences/{zone}', [GeofenceController::class, 'update'])->name('operations.geofences.update');
        Route::delete('/geofences/{zone}', [GeofenceController::class, 'destroy'])->name('operations.geofences.destroy');
    });
});
