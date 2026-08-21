<?php

use App\Http\Controllers\BreakGlassController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ClientAssessmentController;
use App\Http\Controllers\ClientAssignmentController;
// Existing controllers (root namespace)
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientDocumentController;
use App\Http\Controllers\ClientIncidentController;
use App\Http\Controllers\ClientMarController;
use App\Http\Controllers\ClientMedicalController;
use App\Http\Controllers\ClientNoteController;
use App\Http\Controllers\ClientOnboardingController;
use App\Http\Controllers\ClientPersonalAssetController;
use App\Http\Controllers\ClientPhotoMediaController;
use App\Http\Controllers\ClientPortalUserController;
use App\Http\Controllers\ClientRagController;
use App\Http\Controllers\ClientRiskController;
use App\Http\Controllers\ClientSupportPlanController;
use App\Http\Controllers\Clinical\ClientBowelChartController;
use App\Http\Controllers\Clinical\ClientFluidChartController;
use App\Http\Controllers\Clinical\ClientSeizureChartController;
use App\Http\Controllers\Clinical\ClientSleepChartController;
use App\Http\Controllers\CoverageGapController;
use App\Http\Controllers\CoverageReservationController;
use App\Http\Controllers\MedicationAdministrationCorrectionController;
use App\Http\Controllers\Operations\ActivityFeedController;
use App\Http\Controllers\Operations\CalendarSyncController;
// New Operations controllers
use App\Http\Controllers\Operations\CareNoteTemplateController;
use App\Http\Controllers\Operations\CarePlanController;
use App\Http\Controllers\Operations\CarePlanGoalController;
use App\Http\Controllers\Operations\ClientActionsController;
use App\Http\Controllers\Operations\ClientConsentController;
use App\Http\Controllers\Operations\ClientDailyNoteController;
use App\Http\Controllers\Operations\ClientFamilyChatController;
use App\Http\Controllers\Operations\ClientFundController;
use App\Http\Controllers\Operations\ClientLeaveExcursionController;
use App\Http\Controllers\Operations\ClientMealLogController;
use App\Http\Controllers\Operations\ClientOnboardingWorkflowController;
use App\Http\Controllers\Operations\ClientPathPlanController;
use App\Http\Controllers\Operations\ClientRoutineController;
use App\Http\Controllers\Operations\ClientTransportBookingController;
use App\Http\Controllers\Operations\ConsentRequestController;
use App\Http\Controllers\Operations\CustomFormController;
use App\Http\Controllers\Operations\DashboardController;
use App\Http\Controllers\Operations\EvvController;
use App\Http\Controllers\Operations\FamilyPortalController;
use App\Http\Controllers\Operations\FundingClaimController;
use App\Http\Controllers\Operations\FundingController;
use App\Http\Controllers\Operations\GeofenceController;
use App\Http\Controllers\Operations\HandoverController;
use App\Http\Controllers\Operations\JobBoardController;
use App\Http\Controllers\Operations\MessageController;
use App\Http\Controllers\Operations\MileageClaimController;
use App\Http\Controllers\Operations\OpsNotificationController;
use App\Http\Controllers\Operations\ProgressNoteController;
use App\Http\Controllers\Operations\QualificationMatchController;
use App\Http\Controllers\Operations\ReportController;
use App\Http\Controllers\Operations\ReviewQueueController;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Operations Module Routes
 *
 * Centralises client management, shifts, rostering, timesheets,
 * care plans, funding, and messaging.
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
            ->middleware([
                'permission:assets.telemetry.view',
                'permission:fleet.viewAny|assets.viewAny|assets.viewAssigned',
            ])
            ->name('operations.clients.location.history');
        Route::get('/clients/{client}/location/privacy-status', [ClientController::class, 'locationPrivacyStatus'])
            ->whereNumber('client')
            ->middleware([
                'permission:assets.telemetry.view',
                'permission:fleet.viewAny|assets.viewAny|assets.viewAssigned',
            ])
            ->name('operations.clients.location.privacy-status');
        Route::post('/clients/{client}/location/export', [ClientController::class, 'exportLocationHistory'])
            ->whereNumber('client')
            ->middleware([
                'permission:assets.telemetry.view',
                'permission:assets.telemetry.export',
                'permission:fleet.viewAny|assets.viewAny|assets.viewAssigned',
            ])
            ->name('operations.clients.location.export');
        Route::post('/clients/{client}/location/locate-now', [ClientController::class, 'locateNow'])
            ->whereNumber('client')
            ->middleware('permission:fleet.manage|assets.trackers.manage')
            ->name('operations.clients.location.locate-now');
        Route::post('/clients/{client}/location/acknowledge-panic', [ClientController::class, 'acknowledgePanic'])
            ->whereNumber('client')
            ->middleware('permission:fleet.manage|assets.trackers.manage')
            ->name('operations.clients.location.acknowledge-panic');

        // Documents
        Route::get('/clients/{client}/documents', [ClientDocumentController::class, 'index'])
            ->whereNumber('client')
            ->name('operations.clients.documents.index');
        Route::get('/clients/{client}/documents/{document}/download', [ClientDocumentController::class, 'download'])
            ->whereNumber('client')
            ->name('operations.clients.documents.download');

        // Client gallery bytes are private and always pass through the same
        // client policy + profile-section authorization as the Photos tab.
        Route::get('/clients/{client}/gallery-photos/{photo}/media', [ClientPhotoMediaController::class, 'staffMedia'])
            ->whereNumber('client')
            ->whereNumber('photo')
            ->name('operations.clients.gallery-photos.media');
        Route::get('/clients/{client}/gallery-photos/{photo}/thumbnail', [ClientPhotoMediaController::class, 'staffThumbnail'])
            ->whereNumber('client')
            ->whereNumber('photo')
            ->name('operations.clients.gallery-photos.thumbnail');

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
            ->middleware('permission:consents.viewAny')
            ->whereNumber('client')
            ->name('operations.clients.consents.index');
        Route::get('/clients/{client}/consents/{consent}/evidence', [ClientConsentController::class, 'downloadEvidence'])
            ->whereNumber('client')
            ->whereNumber('consent')
            ->name('operations.clients.consents.evidence.download');

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

        // Family chat (staff side of the portal whānau thread)
        Route::get('/clients/{client}/family-chat', [ClientFamilyChatController::class, 'show'])
            ->whereNumber('client')
            ->name('operations.clients.family-chat.show');
        Route::post('/clients/{client}/family-chat', [ClientFamilyChatController::class, 'store'])
            ->whereNumber('client')
            ->name('operations.clients.family-chat.store');

        // RAG/AI queries have their own exact capabilities in the controller.
        Route::post('/clients/{client}/rag/ask', [ClientRagController::class, 'ask'])
            ->whereNumber('client')
            ->middleware('throttle:ai-queries')
            ->name('operations.clients.rag.ask');

        // The former mobile "care" page is retired (this is a web-only app).
        // Redirect the old URL to the full client profile so stale bookmarks or
        // any missed link still land somewhere useful. The route name is kept so
        // remaining route('operations.clients.care', ...) callers keep resolving.
        Route::redirect('/clients/{client}/care', '/operations/clients/{client}', 302)
            ->whereNumber('client')
            ->name('operations.clients.care');
    });

    // Client creation
    Route::middleware('permission:clients.create')->group(function () {
        Route::get('/clients/create', [ClientController::class, 'create'])->name('operations.clients.create');
        Route::post('/clients', [ClientController::class, 'store'])->name('operations.clients.store');
    });

    // Client onboarding workflow (from client profile)
    Route::post('/clients/{client}/onboarding-workflow', [ClientOnboardingWorkflowController::class, 'storeForClient'])
        ->middleware('permission:onboarding.create|clients.create|clients.update')
        ->name('operations.clients.onboarding_workflow.store')
        ->whereNumber('client');

    Route::post('/clients/{client}/onboarding/{key}', [ClientOnboardingController::class, 'toggle'])
        ->middleware('permission:clients.onboarding.manage|clients.update')
        ->whereNumber('client')
        ->name('operations.clients.onboarding.toggle');

    // Client updates
    Route::middleware('permission:clients.update')->group(function () {
        Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('operations.clients.edit');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('operations.clients.update');
        Route::patch('/clients/{client}/quick-update', [ClientController::class, 'quickUpdate'])->name('operations.clients.quick_update');

        // Archive (soft-delete) / restore — drives the index "Archive client" action
        // and the "Show archived" / "Archived" saved view + restore.
        Route::delete('/clients/{client}', [ClientController::class, 'archive'])
            ->whereNumber('client')
            ->name('operations.clients.archive');
        Route::patch('/clients/{client}/restore', [ClientController::class, 'restore'])
            ->whereNumber('client')
            ->name('operations.clients.restore');

        Route::post('/clients/{client}/photo', [ClientController::class, 'updatePhoto'])
            ->name('operations.clients.photo.update');
        Route::delete('/clients/{client}/photo', [ClientController::class, 'destroyPhoto'])
            ->name('operations.clients.photo.destroy');

        // Gallery photos
        Route::post('/clients/{client}/gallery-photos', [ClientController::class, 'storeGalleryPhoto'])
            ->name('operations.clients.gallery-photos.store');
        Route::delete('/clients/{client}/gallery-photos/{photo}', [ClientController::class, 'destroyGalleryPhoto'])
            ->name('operations.clients.gallery-photos.destroy');

        // Transport bookings (client profile Transport tab)
        Route::post('/clients/{client}/transport-bookings', [ClientTransportBookingController::class, 'store'])
            ->whereNumber('client')
            ->name('operations.clients.transport-bookings.store');
        Route::put('/clients/{client}/transport-bookings/{booking}', [ClientTransportBookingController::class, 'update'])
            ->whereNumber('client')
            ->name('operations.clients.transport-bookings.update');
        Route::delete('/clients/{client}/transport-bookings/{booking}', [ClientTransportBookingController::class, 'destroy'])
            ->whereNumber('client')
            ->name('operations.clients.transport-bookings.destroy');

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

    });

    // Consent mutations use the consent domain capabilities, not broad client
    // editing. Controllers also authorize the parent client and nested record.
    Route::post('/clients/{client}/consents', [ClientConsentController::class, 'store'])
        ->whereNumber('client')
        ->name('operations.clients.consents.store');
    Route::post('/clients/{client}/consents/{consent}/withdraw', [ClientConsentController::class, 'withdraw'])
        ->middleware('permission:consents.withdraw|consents.manage')
        ->whereNumber('client')
        ->whereNumber('consent')
        ->name('operations.clients.consents.withdraw');

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

    Route::get('/clients/{client}/daily-notes', [ClientDailyNoteController::class, 'index'])
        ->middleware('permission:progress_notes.viewAny')
        ->whereNumber('client')
        ->name('operations.clients.daily-notes.index');
    Route::post('/clients/{client}/daily-notes', [ClientDailyNoteController::class, 'store'])
        ->middleware('permission:progress_notes.create')
        ->whereNumber('client')
        ->name('operations.clients.daily-notes.store');
    Route::put('/clients/{client}/daily-notes/{note}', [ClientDailyNoteController::class, 'update'])
        ->middleware('permission:progress_notes.create|progress_notes.update')
        ->whereNumber('client')
        ->whereNumber('note')
        ->name('operations.clients.daily-notes.update');
    Route::delete('/clients/{client}/daily-notes/{note}', [ClientDailyNoteController::class, 'destroy'])
        ->middleware('permission:progress_notes.create|progress_notes.delete|progress_notes.update')
        ->whereNumber('client')
        ->whereNumber('note')
        ->name('operations.clients.daily-notes.destroy');
    Route::post('/clients/{client}/daily-notes/{note}/flag', [ClientDailyNoteController::class, 'flag'])
        ->middleware('permission:progress_notes.update|progress_notes.review')
        ->whereNumber('client')
        ->whereNumber('note')
        ->name('operations.clients.daily-notes.flag');
    Route::get('/clients/{client}/daily-notes/review-queue', [ClientDailyNoteController::class, 'reviewQueue'])
        ->middleware('permission:progress_notes.review')
        ->whereNumber('client')
        ->name('operations.clients.daily-notes.review-queue');
    Route::post('/clients/{client}/daily-notes/{note}/review', [ClientDailyNoteController::class, 'review'])
        ->middleware('permission:progress_notes.review')
        ->whereNumber('client')
        ->whereNumber('note')
        ->name('operations.clients.daily-notes.review');

    Route::post('/clients/{client}/meal-logs', [ClientMealLogController::class, 'store'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->name('operations.clients.meal-logs.store');
    Route::put('/clients/{client}/meal-logs/{mealLog}', [ClientMealLogController::class, 'update'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('mealLog')
        ->name('operations.clients.meal-logs.update');
    Route::delete('/clients/{client}/meal-logs/{mealLog}', [ClientMealLogController::class, 'destroy'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('mealLog')
        ->name('operations.clients.meal-logs.destroy');

    Route::get('/clients/{client}/health/bowel', [ClientBowelChartController::class, 'index'])
        ->middleware('permission:medications.view')
        ->whereNumber('client')
        ->name('operations.clients.health.bowel.index');
    Route::post('/clients/{client}/health/bowel', [ClientBowelChartController::class, 'store'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->name('operations.clients.health.bowel.store');
    Route::put('/clients/{client}/health/bowel/{entry}', [ClientBowelChartController::class, 'update'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('entry')
        ->name('operations.clients.health.bowel.update');
    Route::delete('/clients/{client}/health/bowel/{entry}', [ClientBowelChartController::class, 'destroy'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('entry')
        ->name('operations.clients.health.bowel.destroy');
    Route::get('/clients/{client}/health/fluid', [ClientFluidChartController::class, 'index'])
        ->middleware('permission:medications.view')
        ->whereNumber('client')
        ->name('operations.clients.health.fluid.index');
    Route::post('/clients/{client}/health/fluid', [ClientFluidChartController::class, 'store'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->name('operations.clients.health.fluid.store');
    Route::put('/clients/{client}/health/fluid/{entry}', [ClientFluidChartController::class, 'update'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('entry')
        ->name('operations.clients.health.fluid.update');
    Route::delete('/clients/{client}/health/fluid/{entry}', [ClientFluidChartController::class, 'destroy'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('entry')
        ->name('operations.clients.health.fluid.destroy');
    Route::get('/clients/{client}/health/seizure', [ClientSeizureChartController::class, 'index'])
        ->middleware('permission:medications.view')
        ->whereNumber('client')
        ->name('operations.clients.health.seizure.index');
    Route::post('/clients/{client}/health/seizure', [ClientSeizureChartController::class, 'store'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->name('operations.clients.health.seizure.store');
    Route::put('/clients/{client}/health/seizure/{entry}', [ClientSeizureChartController::class, 'update'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('entry')
        ->name('operations.clients.health.seizure.update');
    Route::delete('/clients/{client}/health/seizure/{entry}', [ClientSeizureChartController::class, 'destroy'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('entry')
        ->name('operations.clients.health.seizure.destroy');
    Route::get('/clients/{client}/health/sleep', [ClientSleepChartController::class, 'index'])
        ->middleware('permission:medications.view')
        ->whereNumber('client')
        ->name('operations.clients.health.sleep.index');
    Route::post('/clients/{client}/health/sleep', [ClientSleepChartController::class, 'store'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->name('operations.clients.health.sleep.store');
    Route::put('/clients/{client}/health/sleep/{entry}', [ClientSleepChartController::class, 'update'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('entry')
        ->name('operations.clients.health.sleep.update');
    Route::delete('/clients/{client}/health/sleep/{entry}', [ClientSleepChartController::class, 'destroy'])
        ->middleware('permission:medications.administer.record|clients.update')
        ->whereNumber('client')
        ->whereNumber('entry')
        ->name('operations.clients.health.sleep.destroy');

    Route::get('/clients/{client}/routines', [ClientRoutineController::class, 'index'])
        ->whereNumber('client')
        ->name('operations.clients.routines.index');
    Route::post('/clients/{client}/routines/reorder', [ClientRoutineController::class, 'reorder'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->name('operations.clients.routines.reorder');
    Route::post('/clients/{client}/routines/{block}', [ClientRoutineController::class, 'upsertBlock'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->name('operations.clients.routines.upsert');

    Route::get('/clients/{client}/actions', [ClientActionsController::class, 'index'])
        ->whereNumber('client')
        ->name('operations.clients.actions.index');

    // Cross-client manager review dashboard (Phase 3 C2)
    Route::get('/review-queue', [ReviewQueueController::class, 'index'])
        ->middleware('permission:progress_notes.review')
        ->name('operations.review_queue.index');

    // PATH plan upsert + delete (Phase 3 follow-up)
    Route::post('/clients/{client}/path-plan', [ClientPathPlanController::class, 'upsert'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->name('operations.clients.path_plan.upsert');
    Route::delete('/clients/{client}/path-plan/{plan}', [ClientPathPlanController::class, 'destroy'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->whereNumber('plan')
        ->name('operations.clients.path_plan.destroy');

    // Leave & excursion requests
    Route::post('/clients/{client}/leave', [ClientLeaveExcursionController::class, 'storeLeave'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->name('operations.clients.leave.store');
    Route::put('/clients/{client}/leave/{leave}', [ClientLeaveExcursionController::class, 'updateLeave'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->whereNumber('leave')
        ->name('operations.clients.leave.update');
    Route::delete('/clients/{client}/leave/{leave}', [ClientLeaveExcursionController::class, 'destroyLeave'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->whereNumber('leave')
        ->name('operations.clients.leave.destroy');
    Route::post('/clients/{client}/excursions', [ClientLeaveExcursionController::class, 'storeExcursion'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->name('operations.clients.excursions.store');
    Route::put('/clients/{client}/excursions/{excursion}', [ClientLeaveExcursionController::class, 'updateExcursion'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->whereNumber('excursion')
        ->name('operations.clients.excursions.update');
    Route::delete('/clients/{client}/excursions/{excursion}', [ClientLeaveExcursionController::class, 'destroyExcursion'])
        ->middleware('permission:clients.update')
        ->whereNumber('client')
        ->whereNumber('excursion')
        ->name('operations.clients.excursions.destroy');

    // Medication stock updates
    Route::post('/clients/{client}/medical/medications/{medication}/discontinue', [ClientMedicalController::class, 'discontinueMedication'])
        ->middleware('permission:clients.update|medications.orders.manage')
        ->name('operations.clients.medical.medications.discontinue');

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
        Route::get('/care-plans/{carePlan}/pdf', [CarePlanController::class, 'exportPdf'])->name('operations.care_plans.pdf');
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
        Route::get('/care-plans/{carePlan}/goals/{goal}', [CarePlanGoalController::class, 'show'])
            ->name('operations.care_plans.goals.show');
        // Sub-goals (steps) — progress auto-recalculates from these
        Route::post('/care-plans/{carePlan}/goals/{goal}/steps', [CarePlanGoalController::class, 'storeStep'])
            ->name('operations.care_plans.goals.steps.store');
        Route::put('/care-plans/{carePlan}/goals/{goal}/steps/{step}', [CarePlanGoalController::class, 'updateStep'])
            ->name('operations.care_plans.goals.steps.update');
        Route::delete('/care-plans/{carePlan}/goals/{goal}/steps/{step}', [CarePlanGoalController::class, 'destroyStep'])
            ->name('operations.care_plans.goals.steps.destroy');
        // Hurdles / issues (stored as goal-linked progress notes)
        Route::post('/care-plans/{carePlan}/goals/{goal}/hurdles', [CarePlanGoalController::class, 'addHurdle'])
            ->name('operations.care_plans.goals.hurdles.store');
        Route::patch('/care-plans/{carePlan}/goals/{goal}/hurdles/{note}/resolve', [CarePlanGoalController::class, 'resolveHurdle'])
            ->name('operations.care_plans.goals.hurdles.resolve');

        Route::post('/care-plans/{carePlan}/start-review', [CarePlanController::class, 'startReview'])->name('operations.care_plans.start_review');
        Route::post('/care-plans/{carePlan}/complete-review', [CarePlanController::class, 'completeReview'])->name('operations.care_plans.complete_review');

        // Plan agreement / sign-off (who agreed: client, whānau, EOR/guardian, etc.)
        Route::post('/care-plans/{carePlan}/sign-offs', [CarePlanController::class, 'storeSignOff'])
            ->name('operations.care_plans.sign_offs.store');
        Route::delete('/care-plans/{carePlan}/sign-offs/{signOff}', [CarePlanController::class, 'destroySignOff'])
            ->name('operations.care_plans.sign_offs.destroy');
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
    // Progress Notes — the standalone index page is retired (client-profile
    // redesign): progress notes live on each client profile's Daily Notes tab
    // with a type filter. Old links/bookmarks land on the clients index; the
    // write endpoints below stay (used by care-plan quick notes).
    // -------------------------------------------------------------------------

    Route::get('/progress-notes', function (Request $request) {
        if ($client = $request->query('client_id')) {
            return redirect("/operations/clients/{$client}?tab=progress_notes&type=progress", 301);
        }

        return redirect('/operations/clients', 301);
    })->name('operations.progress_notes.index');
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
    Route::post('/shifts/{shift}/duplicate', [ShiftController::class, 'duplicate'])
        ->middleware('permission:shifts.create')
        ->name('operations.shifts.duplicate');
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
    Route::get('/shifts/{shift}/editable', [ShiftController::class, 'editable'])
        ->middleware('permission:shifts.update')
        ->name('operations.shifts.editable');
    Route::put('/shifts/{shift}', [ShiftController::class, 'update'])
        ->middleware('permission:shifts.update')
        ->name('operations.shifts.update');

    // Roster planning (assign/unassign)
    Route::get('/shifts/{shift}/candidates', [ShiftController::class, 'candidates'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.candidates');
    Route::post('/shifts/{shift}/assign', [ShiftController::class, 'assign'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.assign');
    Route::post('/shifts/{shift}/unassign', [ShiftController::class, 'unassign'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.unassign');
    Route::post('/shifts/{shift}/auto-fill', [ShiftController::class, 'autoFill'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.autoFill');

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
    Route::patch('/shifts/{shift}/publish', [ShiftController::class, 'publishShift'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.publishShift');
    Route::post('/shifts/{shift}/promote-to-series', [ShiftController::class, 'promoteToSeries'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.promoteToSeries');
    Route::post('/shifts/{shift}/broadcast', [ShiftController::class, 'broadcastNeedsCover'])
        ->middleware('permission:shifts.manageAny')
        ->name('operations.shifts.broadcast');
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
    // Manager/scheduler surface: frontline staff are redirected to /my-day by
    // role_scope, and everyone else needs shifts.viewAny to match the sidebar.

    Route::middleware(['role_scope:my-day', 'permission:shifts.viewAny'])->group(function () {
        Route::get('/shift-notes', [ShiftNoteController::class, 'index'])->name('operations.shift_notes.index');
        Route::get('/shift-notes/export', [ShiftNoteController::class, 'export'])->name('operations.shift_notes.export');
    });

    Route::middleware('permission:shifts.viewAny')->group(function () {
        Route::post('/shift-notes', [ShiftNoteController::class, 'store'])->name('operations.shift_notes.store');
        Route::put('/shift-notes/{note}', [ShiftNoteController::class, 'update'])->name('operations.shift_notes.update');
        Route::patch('/shift-notes/{note}/flag', [ShiftNoteController::class, 'flag'])->name('operations.shift_notes.flag');
        Route::patch('/shift-notes/{note}/review', [ShiftNoteController::class, 'markReviewed'])->name('operations.shift_notes.review');
    });

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
    Route::post('/handovers', [HandoverController::class, 'store'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny')
        ->name('operations.handovers.store');
    Route::put('/handovers/{handover}', [HandoverController::class, 'update'])
        ->middleware('permission:handovers.create|shifts.update|shifts.manageAny')
        ->name('operations.handovers.update');
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

        // Calendar tab — the FullCalendar view embedded in the Rostering
        // workspace (re-homed from the removed standalone /scheduling page).
        // Shares the rostering.viewAny gate; writes keep the shift gates.
        Route::get('/rostering/calendar/events', [CalendarController::class, 'events'])
            ->name('operations.rostering.calendar.events');
        Route::post('/rostering/calendar/shifts', [CalendarController::class, 'storeShift'])
            ->middleware('permission:shifts.create')
            ->name('operations.rostering.calendar.shifts.store');
        Route::patch('/rostering/calendar/shifts/{shift}', [CalendarController::class, 'updateShift'])
            ->middleware('permission:shifts.update')
            ->name('operations.rostering.calendar.shifts.update');
    });

    // Roster templates — now a tab inside the Rostering workspace. The list,
    // create, edit, view and apply all happen in pop-ups on /operations/rostering;
    // only the mutation endpoints live here. The old index URL 302s to the tab so
    // existing bookmarks keep working.
    Route::get('/rostering/templates', fn () => redirect()->route('operations.rostering.index', ['tab' => 'templates']))
        ->middleware('permission:roster_templates.viewAny|rostering.viewAny')
        ->name('operations.rostering.templates.index');

    Route::post('/rostering/templates', [RosterTemplateController::class, 'store'])
        ->middleware('permission:roster_templates.create')
        ->name('operations.rostering.templates.store');

    Route::post('/rostering/templates/{template}/duplicate', [RosterTemplateController::class, 'duplicate'])
        ->middleware('permission:roster_templates.create')
        ->name('operations.rostering.templates.duplicate');

    Route::middleware('permission:roster_templates.update')->group(function () {
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

    // Availability now lives inside Rostering so there is one scheduler surface.
    Route::get('/availability', fn () => redirect()->route('operations.rostering.index', ['tab' => 'availability']))
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
    //
    // The standalone approvals page has been replaced by the Pending tab on
    // the unified index — the GET route now redirects with ?tab=submitted so
    // bookmarks and emails keep working.
    Route::middleware(['role_scope:my-day', 'permission:timesheets.approve|timesheets.manageAny'])->group(function () {
        Route::get('/timesheets/approvals', function () {
            return redirect()->route('operations.timesheets.index', ['tab' => 'submitted']);
        })->name('operations.timesheets.approvals');
        Route::get('/timesheets/payroll-adjustments', [TimesheetController::class, 'payrollAdjustmentsPending'])->name('operations.timesheets.payrollAdjustments');
        Route::post('/timesheets/amendments/{amendment}/mark-processed', [TimesheetController::class, 'markPayrollAdjustmentProcessed'])->name('operations.timesheets.markPayrollProcessed');
        Route::post('/timesheets/bulk-approve', [TimesheetController::class, 'bulkApprove'])->name('operations.timesheets.bulkApprove');
        Route::post('/timesheets/bulk-return', [TimesheetController::class, 'bulkReturnForChanges'])->name('operations.timesheets.bulkReturn');
        Route::post('/timesheets/bulk-reject', [TimesheetController::class, 'bulkReject'])->name('operations.timesheets.bulkReject');
    });

    // Timesheet creation
    // The standalone /create page has been retired — every Create flow funnels
    // through the CreateTimesheetDialog on the index page. The GET route now
    // redirects to /operations/timesheets?create=1 (the dialog auto-opens on
    // that query param), preserving any `shift_id` passed by callers like the
    // shift detail page.
    Route::get('/timesheets/create', function (Request $request) {
        $query = ['create' => '1'];
        if ($request->query('shift_id')) {
            $query['shift_id'] = $request->query('shift_id');
        }

        return redirect()->route('operations.timesheets.index', $query);
    })
        ->middleware('permission:timesheets.create')
        ->name('operations.timesheets.create');
    Route::post('/timesheets', [TimesheetController::class, 'store'])
        ->middleware('permission:timesheets.create')
        ->name('operations.timesheets.store');

    // Archive / restore (used by the row context menu on the index page).
    Route::post('/timesheets/{timesheet}/archive', [TimesheetController::class, 'archive'])
        ->middleware('permission:timesheets.manageAny')
        ->name('operations.timesheets.archive');
    Route::post('/timesheets/{timesheet}/restore', [TimesheetController::class, 'restore'])
        ->middleware('permission:timesheets.manageAny')
        ->name('operations.timesheets.restore');

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
    Route::post('/funding/claims/{claim}/retry-posting', [FundingClaimController::class, 'retryPosting'])
        ->middleware('permission:funding.claims.retryPosting')
        ->name('operations.funding.claims.retry-posting');

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
    // Client Onboarding (Phase 7)
    // -------------------------------------------------------------------------

    Route::middleware('permission:onboarding.viewAny|onboarding.view|clients.viewAny')->group(function () {
        Route::get('/onboarding', [ClientOnboardingWorkflowController::class, 'index'])->name('operations.onboarding.index');
    });
    Route::middleware('permission:onboarding.create|clients.create|clients.update')->group(function () {
        Route::get('/onboarding/create', [ClientOnboardingWorkflowController::class, 'create'])->name('operations.onboarding.create');
        Route::post('/onboarding', [ClientOnboardingWorkflowController::class, 'store'])->name('operations.onboarding.store');
    });
    Route::middleware('permission:onboarding.edit|clients.create|clients.update')->group(function () {
        Route::post('/onboarding/{workflow}/steps', [ClientOnboardingWorkflowController::class, 'storeStep'])->name('operations.onboarding.steps.store');
        Route::patch('/onboarding/{workflow}/steps/{step}', [ClientOnboardingWorkflowController::class, 'updateStep'])->name('operations.onboarding.steps.update');
        Route::post('/onboarding/{workflow}/complete', [ClientOnboardingWorkflowController::class, 'complete'])->name('operations.onboarding.complete');
    });
    Route::middleware('permission:onboarding.viewAny|onboarding.view|clients.viewAny')->group(function () {
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
        Route::post('/client-funds/{fund}/transactions/{transaction}/reverse', [ClientFundController::class, 'reverseTransaction'])->name('operations.client_funds.transactions.reverse');
    });
    Route::middleware('permission:client_funds.approve')->group(function () {
        Route::post('/client-funds/{fund}/transactions/{transaction}/approve', [ClientFundController::class, 'approveTransaction'])->name('operations.client_funds.transactions.approve');
        Route::post('/client-funds/{fund}/transactions/{transaction}/reject', [ClientFundController::class, 'rejectTransaction'])->name('operations.client_funds.transactions.reject');
    });
    Route::middleware('permission:client_funds.manage|client_funds.approve')->group(function () {
        Route::get('/client-funds', [ClientFundController::class, 'index'])->name('operations.client_funds.index');
        Route::get('/client-funds/{fund}', [ClientFundController::class, 'show'])->name('operations.client_funds.show');
    });

    // -------------------------------------------------------------------------
    // Job Board / Open Shifts (Phase 8)
    // -------------------------------------------------------------------------

    Route::get('/job-board', [JobBoardController::class, 'index'])
        ->middleware('permission:job_board.viewAny|job_board.claim|shifts.viewAny|shifts.viewAssigned')
        ->name('operations.job_board.index');
    Route::post('/job-board/alerts/toggle', [JobBoardController::class, 'toggleAlerts'])
        ->middleware('permission:job_board.viewAny|job_board.claim|shifts.viewAny|shifts.viewAssigned')
        ->name('operations.job_board.alerts.toggle');
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
    // Calendar Sync (Phase 11)
    // -------------------------------------------------------------------------

    Route::get('/calendar-sync', [CalendarSyncController::class, 'index'])->name('operations.calendar_sync.index');
    Route::get('/calendar-sync/create', [CalendarSyncController::class, 'create'])->name('operations.calendar_sync.create');
    Route::post('/calendar-sync', [CalendarSyncController::class, 'store'])->name('operations.calendar_sync.store');
    Route::delete('/calendar-sync/{sync}', [CalendarSyncController::class, 'destroy'])->name('operations.calendar_sync.destroy');
    Route::post('/calendar-sync/{sync}/trigger', [CalendarSyncController::class, 'triggerSync'])->name('operations.calendar_sync.trigger');

    // -------------------------------------------------------------------------
    // Geofence compatibility routes. The canonical register is asset_geofences;
    // these routes remain only for old bookmarks and never write geofence_zones.
    // -------------------------------------------------------------------------

    Route::middleware('permission:evv.viewAny|geofences.viewAny|fleet.viewAny|assets.viewAny|assets.geofences.manage')->group(function () {
        Route::get('/geofences', [GeofenceController::class, 'index'])->name('operations.geofences.index');
        Route::get('/geofences/create', [GeofenceController::class, 'create'])->name('operations.geofences.create');
        Route::post('/geofences', [GeofenceController::class, 'store'])->name('operations.geofences.store');
        Route::put('/geofences/{zone}', [GeofenceController::class, 'update'])->whereNumber('zone')->name('operations.geofences.update');
        Route::delete('/geofences/{zone}', [GeofenceController::class, 'destroy'])->whereNumber('zone')->name('operations.geofences.destroy');
    });
});
