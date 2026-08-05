<?php

use App\Http\Controllers\HealthSafety\EmergencyDrillController;
use App\Http\Controllers\HealthSafety\FirstAidController;
use App\Http\Controllers\HealthSafety\HazardousSubstanceController;
use App\Http\Controllers\HealthSafety\HealthSafetyDashboardController;
use App\Http\Controllers\HealthSafety\HsCorrectiveActionController;
use App\Http\Controllers\HealthSafety\HsCorrectiveActionEvidenceController;
use App\Http\Controllers\HealthSafety\HsEventController;
use App\Http\Controllers\HealthSafety\HsGovernanceReportController;
use App\Http\Controllers\HealthSafety\HsInvestigationController;
use App\Http\Controllers\HealthSafety\HsRiskAssessmentController;
use App\Http\Controllers\HealthSafety\HsWorksafeDecisionController;
use App\Http\Controllers\HealthSafety\LoneWorkerController;
use App\Http\Controllers\HealthSafety\PpeController;
use App\Http\Controllers\HealthSafety\RestraintController;
use App\Http\Controllers\HealthSafety\ReturnToWorkController;
use App\Http\Controllers\HealthSafety\SafeWorkProcedureController;
use App\Http\Controllers\HealthSafety\WorkerParticipationController;
use App\Http\Controllers\Sites\SiteGeocodingController;
use Illuminate\Support\Facades\Route;

/**
 * Health & Safety Routes
 *
 * Phase 2: Worker participation, hazardous substances, emergency drills,
 *          and return-to-work / injury management.
 * Phase 3: Lone worker safety and PPE management.
 * Phase 4: Dashboard and analytics.
 * Phase 5: First aid register, restraint register, safe work procedures.
 */
Route::middleware(['auth'])->prefix('health-safety')->name('health-safety.')->group(function () {

    // ── Phase 4: Dashboard & Analytics ──────────────────────────────────
    Route::middleware('permission:hazards.view')->group(function () {
        Route::get('/', [HealthSafetyDashboardController::class, 'index'])->name('dashboard');
        Route::get('/analytics', [HealthSafetyDashboardController::class, 'analytics'])->name('analytics');
        Route::get('/analytics/export', [HealthSafetyDashboardController::class, 'analyticsExport'])->name('analytics.export');
        Route::get('/analytics/records', [HealthSafetyDashboardController::class, 'analyticsRecords'])->name('analytics.records');
    });

    // ── PR5: H&S Backbone Views (Events, Actions, Risk Assessments) ──
    Route::middleware('permission:hazards.view')->group(function () {
        Route::get('/events', [HsEventController::class, 'index'])->name('events.index');
        Route::get('/events/{hsEvent}', [HsEventController::class, 'show'])->name('events.show');
        Route::get('/events/{hsEvent}/incident-attachments/{attachment}/download', [HsEventController::class, 'downloadIncidentAttachment'])->name('events.incident-attachments.download');
        Route::get('/events/{hsEvent}/control-room-evidence/{item}/download', [HsEventController::class, 'downloadControlRoomEvidence'])->name('events.control-room-evidence.download');
        Route::get('/corrective-actions', [HsEventController::class, 'correctiveActions'])->name('corrective-actions.index');
        Route::get('/risk-assessments', [HsRiskAssessmentController::class, 'index'])->name('risk-assessments.index');
        Route::get('/risk-assessments/{assessment}', [HsRiskAssessmentController::class, 'show'])->name('risk-assessments.show');
        Route::get('/risk-assessments/{assessment}/attachments/{attachment}/download', [HsRiskAssessmentController::class, 'downloadAttachment'])->name('risk-assessments.attachments.download');
    });

    // Corrective-action evidence is participant-authorized in the controller.
    // The assigned owner can upload/download/remove evidence without receiving
    // governance-wide hazards.manage permission.
    Route::post('/events/{event}/corrective-actions/{action}/evidence', [HsCorrectiveActionEvidenceController::class, 'store'])
        ->name('events.corrective-actions.evidence.store');
    Route::get('/events/{event}/corrective-actions/{action}/evidence/{attachment}', [HsCorrectiveActionEvidenceController::class, 'download'])
        ->name('events.corrective-actions.evidence.download');
    Route::delete('/events/{event}/corrective-actions/{action}/evidence/{attachment}', [HsCorrectiveActionEvidenceController::class, 'destroy'])
        ->name('events.corrective-actions.evidence.destroy');

    // ── Events governance write actions (gated) ──
    Route::middleware('permission:hazards.manage')->group(function () {
        Route::post('/events/{hsEvent}/accept-handover', [HsEventController::class, 'acceptHandover'])->name('events.handover.accept');
        Route::post('/events/{hsEvent}/close', [HsEventController::class, 'close'])->name('events.close');
        Route::post('/events/{hsEvent}/worksafe/decision', HsWorksafeDecisionController::class)->name('events.worksafe.decision');
        Route::post('/events/{hsEvent}/worksafe/notify', [HsEventController::class, 'worksafeNotify'])->name('events.worksafe.notify');
        Route::post('/events/{hsEvent}/worksafe/acknowledge', [HsEventController::class, 'worksafeAcknowledge'])->name('events.worksafe.acknowledge');

        // Investigation workflow (exposes HsInvestigationService)
        Route::post('/events/{event}/investigations', [HsInvestigationController::class, 'store'])->name('events.investigations.store');
        Route::post('/events/{event}/investigations/{investigation}/findings', [HsInvestigationController::class, 'recordFindings'])->name('events.investigations.findings');
        Route::post('/events/{event}/investigations/{investigation}/submit', [HsInvestigationController::class, 'submit'])->name('events.investigations.submit');
        Route::post('/events/{event}/investigations/{investigation}/return', [HsInvestigationController::class, 'returnForRework'])->name('events.investigations.return');
        Route::post('/events/{event}/investigations/{investigation}/complete', [HsInvestigationController::class, 'complete'])->name('events.investigations.complete');
        Route::post('/events/{event}/investigations/{investigation}/recommendations/{recommendationIndex}/disposition', [HsInvestigationController::class, 'disposition'])->name('events.investigations.recommendations.disposition');
        Route::post('/events/{event}/investigations/{investigation}/seed-action', [HsCorrectiveActionController::class, 'seedFromRecommendation'])->name('events.investigations.seed-action');

        // Corrective-action workflow (exposes HsCorrectiveActionService)
        Route::post('/events/{event}/corrective-actions', [HsCorrectiveActionController::class, 'store'])->name('events.corrective-actions.store');
        Route::post('/events/{event}/corrective-actions/{action}/start', [HsCorrectiveActionController::class, 'start'])->name('events.corrective-actions.start');
        Route::post('/events/{event}/corrective-actions/{action}/complete', [HsCorrectiveActionController::class, 'complete'])->name('events.corrective-actions.complete');
        Route::post('/events/{event}/corrective-actions/{action}/verify', [HsCorrectiveActionController::class, 'verify'])->name('events.corrective-actions.verify');
        Route::post('/events/{event}/corrective-actions/{action}/close', [HsCorrectiveActionController::class, 'close'])->name('events.corrective-actions.close');
        Route::post('/events/{event}/corrective-actions/{action}/return', [HsCorrectiveActionController::class, 'returnForRework'])->name('events.corrective-actions.return');
    });

    // ── Risk Assessment write actions (gated) — all delegate to HsRiskAssessmentService ──
    Route::middleware('permission:hazards.manage')->group(function () {
        Route::post('/risk-assessments', [HsRiskAssessmentController::class, 'store'])->name('risk-assessments.store');
        Route::put('/risk-assessments/{assessment}', [HsRiskAssessmentController::class, 'update'])->name('risk-assessments.update');
        Route::post('/risk-assessments/{assessment}/activate', [HsRiskAssessmentController::class, 'activate'])->name('risk-assessments.activate');
        Route::post('/risk-assessments/{assessment}/review', [HsRiskAssessmentController::class, 'markForReview'])->name('risk-assessments.review');
        Route::post('/risk-assessments/{assessment}/residual', [HsRiskAssessmentController::class, 'updateResidual'])->name('risk-assessments.residual');
        Route::post('/risk-assessments/{assessment}/supersede', [HsRiskAssessmentController::class, 'supersede'])->name('risk-assessments.supersede');
        Route::post('/risk-assessments/{assessment}/archive', [HsRiskAssessmentController::class, 'archive'])->name('risk-assessments.archive');

        // Premium evidence upload (SWMS, method statements, photos, SDS, plans, PDFs).
        Route::post('/risk-assessments/{assessment}/attachments', [HsRiskAssessmentController::class, 'uploadAttachment'])->name('risk-assessments.attachments.store');
        Route::delete('/risk-assessments/{assessment}/attachments/{attachment}', [HsRiskAssessmentController::class, 'destroyAttachment'])->name('risk-assessments.attachments.destroy');
    });

    // ── PR6: Governance & Compliance Reports ──
    Route::middleware('permission:governance.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/board-summary', [HsGovernanceReportController::class, 'boardSummary'])->name('board-summary');
        Route::get('/worksafe-register', [HsGovernanceReportController::class, 'worksafeRegister'])->name('worksafe-register');
        Route::get('/investigation-outcomes', [HsGovernanceReportController::class, 'investigationOutcomes'])->name('investigation-outcomes');
        Route::get('/corrective-action-traceability', [HsGovernanceReportController::class, 'correctiveActionTraceability'])->name('corrective-action-traceability');
        Route::get('/risk-assessment-register', [HsGovernanceReportController::class, 'riskAssessmentRegister'])->name('risk-assessment-register');
    });

    // ── Phase 5A: First Aid Register (gold-standard rebuild) ────────────
    Route::prefix('first-aid')->name('first-aid.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [FirstAidController::class, 'index'])->name('index');
            // Static sub-routes before the {record} wildcard so they aren't swallowed.
            Route::get('/export', [FirstAidController::class, 'export'])->name('export');
            Route::get('/{record}/attachments/{attachment}/download', [FirstAidController::class, 'downloadAttachment'])->name('attachments.download');
            Route::get('/{record}', [FirstAidController::class, 'show'])->name('show');
        });

        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::post('/', [FirstAidController::class, 'store'])->name('store');
            Route::put('/{record}', [FirstAidController::class, 'update'])->name('update');
            Route::post('/{record}/link-incident', [FirstAidController::class, 'linkIncident'])->name('link-incident');
            Route::post('/{record}/followups', [FirstAidController::class, 'addFollowup'])->name('followups.add');
            Route::patch('/{record}/followups/{followup}/complete', [FirstAidController::class, 'completeFollowup'])->name('followups.complete');
            Route::post('/{record}/attachments', [FirstAidController::class, 'uploadAttachment'])->name('attachments.upload');
            Route::delete('/{record}/attachments/{attachment}', [FirstAidController::class, 'destroyAttachment'])->name('attachments.destroy');
        });

        Route::middleware('permission:hazards.manage')->group(function () {
            Route::delete('/{record}', [FirstAidController::class, 'destroy'])->name('destroy');
        });
    });

    // ── Phase 5B: Restraint Register ────────────────────────────────────
    Route::prefix('restraints')->name('restraints.')->group(function () {

        // View register + detail (detail-as-modal via ?event= / ?plan=) + export + downloads.
        Route::middleware('permission:restraints.view')->group(function () {
            Route::get('/', [RestraintController::class, 'index'])->name('index');
            Route::get('/export', [RestraintController::class, 'export'])->name('export');
            Route::get('/clients/{client}/summary', [RestraintController::class, 'clientSummary'])->name('clients.summary');
            Route::get('/events/{event}/attachments/{attachment}/download', [RestraintController::class, 'downloadAttachment'])->name('events.attachments.download');
        });

        // Capture — record events, create plans, upload evidence.
        Route::middleware('permission:restraints.create|restraints.manage')->group(function () {
            Route::post('/events', [RestraintController::class, 'storeEvent'])->name('events.store');
            Route::post('/plans', [RestraintController::class, 'storePlan'])->name('plans.store');
            Route::post('/events/{event}/attachments', [RestraintController::class, 'storeAttachment'])->name('events.attachments.store');
        });

        // Review — event review + plan review sign-off + post-hoc incident link.
        Route::middleware('permission:restraints.review|restraints.manage')->group(function () {
            Route::put('/events/{event}', [RestraintController::class, 'updateEvent'])->name('events.update');
            Route::post('/events/{event}/link-incident', [RestraintController::class, 'linkIncident'])->name('events.link-incident');
            Route::post('/plans/{plan}/review', [RestraintController::class, 'reviewPlan'])->name('plans.review');
        });

        // Manage — plan edit + lifecycle + attachment removal.
        Route::middleware('permission:restraints.manage')->group(function () {
            Route::put('/plans/{plan}', [RestraintController::class, 'updatePlan'])->name('plans.update');
            Route::post('/plans/{plan}/activate', [RestraintController::class, 'activatePlan'])->name('plans.activate');
            Route::post('/plans/{plan}/submit-review', [RestraintController::class, 'submitPlanReview'])->name('plans.submit-review');
            Route::post('/plans/{plan}/archive', [RestraintController::class, 'archivePlan'])->name('plans.archive');
            Route::delete('/events/{event}/attachments/{attachment}', [RestraintController::class, 'destroyAttachment'])->name('events.attachments.destroy');
        });
    });

    // ── Phase 5C: Safe Work Procedures ──────────────────────────────────
    Route::prefix('procedures')->name('procedures.')->group(function () {

        Route::middleware('permission:procedures.view')->group(function () {
            Route::get('/', [SafeWorkProcedureController::class, 'index'])->name('index');
            // Static export before the {procedure} wildcard so it isn't swallowed.
            Route::get('/export', [SafeWorkProcedureController::class, 'export'])->name('export');
        });

        Route::middleware('permission:procedures.create|procedures.manage')->group(function () {
            Route::get('/create', [SafeWorkProcedureController::class, 'create'])->name('create');
            Route::post('/', [SafeWorkProcedureController::class, 'store'])->name('store');
            Route::get('/{procedure}/edit', [SafeWorkProcedureController::class, 'edit'])->name('edit');
            Route::put('/{procedure}', [SafeWorkProcedureController::class, 'update'])->name('update');
            Route::post('/{procedure}/submit-for-review', [SafeWorkProcedureController::class, 'submitForReview'])->name('submit-for-review');
        });

        Route::middleware('permission:procedures.manage')->group(function () {
            Route::post('/{procedure}/request-changes', [SafeWorkProcedureController::class, 'requestChanges'])->name('request-changes');
            Route::post('/{procedure}/record-review', [SafeWorkProcedureController::class, 'recordReview'])->name('record-review');
            Route::post('/{procedure}/archive', [SafeWorkProcedureController::class, 'archive'])->name('archive');
            Route::post('/{procedure}/restore', [SafeWorkProcedureController::class, 'restore'])->name('restore');

            // Controlled-document library (premium upload — reuses polymorphic HsAttachment)
            Route::post('/{procedure}/attachments', [SafeWorkProcedureController::class, 'uploadAttachment'])->name('attachments.store');
            Route::delete('/{procedure}/attachments/{attachment}', [SafeWorkProcedureController::class, 'destroyAttachment'])->name('attachments.destroy');
        });

        Route::middleware('permission:procedures.approve')->group(function () {
            Route::post('/{procedure}/approve', [SafeWorkProcedureController::class, 'approve'])->name('approve');
        });

        // View-gated reads — download lets register-only roles read the master document.
        Route::middleware('permission:procedures.view')->group(function () {
            Route::get('/{procedure}/attachments/{attachment}/download', [SafeWorkProcedureController::class, 'downloadAttachment'])->name('attachments.download');

            // Any viewer can acknowledge they've read & understood the procedure.
            Route::post('/{procedure}/acknowledge', [SafeWorkProcedureController::class, 'acknowledge'])->name('acknowledge');

            // Show route LAST to avoid the /{procedure} wildcard swallowing /create etc.
            Route::get('/{procedure}', [SafeWorkProcedureController::class, 'show'])->name('show');
        });
    });

    // ── Worker Participation ──────────────────────────────────────────
    Route::prefix('worker-participation')->name('worker-participation.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [WorkerParticipationController::class, 'index'])->name('index');
            Route::get('/export', [WorkerParticipationController::class, 'export'])->name('export');

            // Document downloads are read operations — a register viewer must be
            // able to read its attached documents (minutes / consultation docs).
            Route::get('/consultations/{consultation}/documents/{type}', [WorkerParticipationController::class, 'downloadConsultationDocument'])->name('consultations.documents.download');
            Route::get('/meetings/{meeting}/minutes/download', [WorkerParticipationController::class, 'downloadMeetingMinutes'])->name('meetings.minutes.download');
        });

        Route::middleware('permission:hazards.manage')->group(function () {
            // Representatives
            Route::post('/representatives', [WorkerParticipationController::class, 'storeRepresentative'])->name('representatives.store');
            Route::put('/representatives/{representative}', [WorkerParticipationController::class, 'updateRepresentative'])->name('representatives.update');

            // Committees
            Route::post('/committees', [WorkerParticipationController::class, 'storeCommittee'])->name('committees.store');

            // Committee Meetings
            Route::post('/committees/{committee}/meetings', [WorkerParticipationController::class, 'storeMeeting'])->name('committees.meetings.store');
            Route::put('/meetings/{meeting}', [WorkerParticipationController::class, 'updateMeeting'])->name('meetings.update');

            // Consultations
            Route::post('/consultations', [WorkerParticipationController::class, 'storeConsultation'])->name('consultations.store');
            Route::put('/consultations/{consultation}', [WorkerParticipationController::class, 'updateConsultation'])->name('consultations.update');

            // Consultation workflow
            Route::put('/consultations/{consultation}/status', [WorkerParticipationController::class, 'updateConsultationStatus'])->name('consultations.status');
            Route::post('/consultations/{consultation}/documents', [WorkerParticipationController::class, 'uploadConsultationDocument'])->name('consultations.documents.upload');

            // Meeting workflow
            Route::post('/meetings/{meeting}/attendees', [WorkerParticipationController::class, 'addMeetingAttendees'])->name('meetings.attendees');
            Route::put('/meetings/{meeting}/complete', [WorkerParticipationController::class, 'completeMeeting'])->name('meetings.complete');
            Route::put('/meetings/{meeting}/cancel', [WorkerParticipationController::class, 'cancelMeeting'])->name('meetings.cancel');
            Route::post('/meetings/{meeting}/minutes', [WorkerParticipationController::class, 'uploadMeetingMinutes'])->name('meetings.minutes.upload');
        });
    });

    // ── Hazardous Substances ──────────────────────────────────────────
    Route::prefix('substances')->name('substances.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [HazardousSubstanceController::class, 'index'])->name('index');
            Route::get('/{substance}/sds/{sds}/download', [HazardousSubstanceController::class, 'downloadSds'])->name('sds.download');
        });

        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::get('/create', [HazardousSubstanceController::class, 'create'])->name('create');
            Route::post('/', [HazardousSubstanceController::class, 'store'])->name('store');
        });
        Route::middleware('permission:hazards.manage')->group(function () {
            Route::put('/{substance}', [HazardousSubstanceController::class, 'update'])->name('update');
            Route::patch('/{substance}/status', [HazardousSubstanceController::class, 'updateStatus'])
                ->whereNumber('substance')->name('status');
        });
        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {

            // SDS Documents
            Route::post('/{substance}/sds', [HazardousSubstanceController::class, 'storeSds'])->name('sds.store');

            // Storage Locations
            Route::post('/{substance}/storage-locations', [HazardousSubstanceController::class, 'storeStorageLocation'])->name('storage-locations.store');

            // Exposure Records
            Route::post('/{substance}/exposure-records', [HazardousSubstanceController::class, 'storeExposureRecord'])->name('exposure-records.store');
            Route::post('/{substance}/exposures', [HazardousSubstanceController::class, 'storeExposureRecord'])->name('exposures.store');
        });

        // Show route after /create to avoid wildcard conflict
        Route::get('/{substance}', [HazardousSubstanceController::class, 'show'])
            ->middleware('permission:hazards.view')
            ->name('show');
    });

    // ── Emergency Drills ──────────────────────────────────────────────
    Route::prefix('drills')->name('drills.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [EmergencyDrillController::class, 'index'])->name('index');
        });

        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::get('/create', [EmergencyDrillController::class, 'create'])->name('create');
            Route::post('/', [EmergencyDrillController::class, 'store'])->name('store');
        });
        Route::middleware('permission:hazards.manage')->group(function () {
            Route::put('/{drill}', [EmergencyDrillController::class, 'update'])->name('update');

            // Lifecycle
            Route::post('/{drill}/start', [EmergencyDrillController::class, 'start'])->name('start');
            Route::post('/{drill}/complete', [EmergencyDrillController::class, 'complete'])->name('complete');
            Route::post('/{drill}/cancel', [EmergencyDrillController::class, 'cancel'])->name('cancel');

            // Participants
            Route::post('/{drill}/participants', [EmergencyDrillController::class, 'addParticipant'])->name('participants.store');

            // Findings
            Route::post('/{drill}/findings', [EmergencyDrillController::class, 'addFinding'])->name('findings.store');
            Route::put('/findings/{finding}', [EmergencyDrillController::class, 'updateFinding'])->name('findings.update');
            Route::post('/{drill}/findings/{finding}/resolve', [EmergencyDrillController::class, 'resolveFinding'])->name('findings.resolve');

            // Evidence (premium upload)
            Route::post('/{drill}/attachments', [EmergencyDrillController::class, 'uploadAttachment'])->name('attachments.store');
            Route::delete('/{drill}/attachments/{attachment}', [EmergencyDrillController::class, 'destroyAttachment'])->name('attachments.destroy');
        });

        // Evidence download (read)
        Route::get('/{drill}/attachments/{attachment}/download', [EmergencyDrillController::class, 'downloadAttachment'])
            ->middleware('permission:hazards.view')
            ->name('attachments.download');

        // Show route after /create + static sub-routes to avoid wildcard conflict
        Route::get('/{drill}', [EmergencyDrillController::class, 'show'])
            ->middleware('permission:hazards.view')
            ->name('show');
    });

    // ── Workplace Injuries & Return to Work ───────────────────────────
    Route::prefix('injuries')->name('injuries.')->group(function () {

        // Read is open to HR wellbeing too — staff injury / RTW is an HR-wellbeing
        // function (fixes the nav-vs-route 403: the sidebar already shows this to
        // hr.wellbeing.view). Writes stay hazards.manage; the UI is read-only for
        // non-managers via the can.manage flag.
        Route::middleware('permission:hazards.view|hr.wellbeing.view')->group(function () {
            Route::get('/', [ReturnToWorkController::class, 'index'])->name('index');
            Route::get('/export', [ReturnToWorkController::class, 'export'])->name('export');
        });

        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::get('/create', [ReturnToWorkController::class, 'create'])->name('create');
            Route::post('/', [ReturnToWorkController::class, 'store'])->name('store');
        });
        Route::middleware('permission:hazards.manage')->group(function () {
            Route::put('/{injury}', [ReturnToWorkController::class, 'update'])->name('update');

            // Explicit lifecycle transition (Start treatment / Begin RTW / Mark recovered / Close)
            Route::post('/{injury}/status', [ReturnToWorkController::class, 'transitionStatus'])->name('status');

            // Return to Work Plans
            Route::post('/{injury}/rtw-plans', [ReturnToWorkController::class, 'storeRtwPlan'])->name('rtw-plans.store');
            Route::put('/rtw-plans/{rtwPlan}', [ReturnToWorkController::class, 'updateRtwPlan'])->name('rtw-plans.update');

            // Capacity Assessments
            Route::post('/{injury}/capacity-assessments', [ReturnToWorkController::class, 'storeCapacityAssessment'])->name('capacity-assessments.store');

            // Modified Duties
            Route::post('/rtw-plans/{rtwPlan}/modified-duties', [ReturnToWorkController::class, 'storeModifiedDuty'])->name('modified-duties.store');

            // Evidence (premium document upload)
            Route::post('/{injury}/attachments', [ReturnToWorkController::class, 'uploadAttachment'])->name('attachments.store');
            Route::delete('/{injury}/attachments/{attachment}', [ReturnToWorkController::class, 'destroyAttachment'])->name('attachments.destroy');
        });

        // Evidence download (read)
        Route::get('/{injury}/attachments/{attachment}/download', [ReturnToWorkController::class, 'downloadAttachment'])
            ->middleware('permission:hazards.view|hr.wellbeing.view')
            ->name('attachments.download');

        // Show route after /create + static sub-routes to avoid wildcard conflict
        Route::get('/{injury}', [ReturnToWorkController::class, 'show'])
            ->middleware('permission:hazards.view|hr.wellbeing.view')
            ->name('show');
    });

    // ── Lone Worker Safety (Phase 3) ──────────────────────────────────
    Route::prefix('lone-workers')->name('lone-workers.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [LoneWorkerController::class, 'index'])->name('index');
            // OpenStreetMap (Nominatim) address autocomplete for the Start-session
            // wizard — reuses the shared geocoder, gated to anyone who can view the page
            // (the sites/clients geocode proxies require sites.update/clients.update which
            // an H&S coordinator may not hold).
            Route::get('/geocode/search', [SiteGeocodingController::class, 'search'])->name('geocode.search');
        });

        // Worker self check-in (auth-only). The 3-actor model puts the lone
        // WORKER's one-tap check-in on My Day, not the coordinator watch-tower.
        // Frontline support workers have NO hazards.* permission, so this route
        // must sit OUTSIDE the hazards.manage group or worker self-check-in would
        // 403. Authorization is enforced inside checkIn(): the session's own
        // worker, or a coordinator with hazards.manage, may record a check-in.
        Route::post('/sessions/{session}/check-in', [LoneWorkerController::class, 'checkIn'])->name('sessions.check-in');

        Route::middleware('permission:hazards.manage')->group(function () {
            Route::post('/sessions', [LoneWorkerController::class, 'startSession'])->name('sessions.store');
            Route::patch('/sessions/{session}', [LoneWorkerController::class, 'updateSession'])->name('sessions.update');
            Route::post('/sessions/{session}/end', [LoneWorkerController::class, 'endSession'])->name('sessions.end');
            Route::delete('/sessions/{session}', [LoneWorkerController::class, 'destroy'])->name('sessions.destroy');
            Route::post('/sessions/{session}/emergency', [LoneWorkerController::class, 'triggerEmergency'])->name('sessions.emergency');
            // Queclink GPS tracker — Locate-now + acknowledge panic (staff-paired device).
            Route::post('/sessions/{session}/locate', [LoneWorkerController::class, 'locateNow'])->name('sessions.locate');
            Route::post('/sessions/{session}/acknowledge-panic', [LoneWorkerController::class, 'acknowledgePanic'])->name('sessions.acknowledge-panic');

            // Alerts
            Route::post('/alerts/{alert}/acknowledge', [LoneWorkerController::class, 'acknowledgeAlert'])->name('alerts.acknowledge');
            Route::post('/alerts/{alert}/resolve', [LoneWorkerController::class, 'resolveAlert'])->name('alerts.resolve');
        });
    });

    // ── PPE Management (Phase 3) ──────────────────────────────────────
    Route::prefix('ppe')->name('ppe.')->group(function () {

        // Worker self-acknowledgement from My Day (auth-only — support workers have no
        // hazards.* perms). Authorisation is ownership, enforced inside acknowledgeOwn().
        // Must sit OUTSIDE the hazards.manage group or worker self-ack would 403.
        Route::post('/allocations/{allocation}/acknowledge-own', [PpeController::class, 'acknowledgeOwn'])->name('allocations.acknowledge-own');

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [PpeController::class, 'index'])->name('index');
            Route::get('/export', [PpeController::class, 'export'])->name('export');

            // Evidence downloads (read)
            Route::get('/inventory/{inventory}/attachments/{attachment}/download', [PpeController::class, 'downloadInventoryAttachment'])->name('inventory.attachments.download');
            Route::get('/allocations/{allocation}/attachments/{attachment}/download', [PpeController::class, 'downloadAllocationAttachment'])->name('allocations.attachments.download');
            Route::get('/inspections/{inspection}/attachments/{attachment}/download', [PpeController::class, 'downloadInspectionAttachment'])->name('inspections.attachments.download');
        });

        Route::middleware('permission:hazards.manage')->group(function () {
            // PPE Types
            Route::post('/types', [PpeController::class, 'storeType'])->name('types.store');
            Route::put('/types/{type}', [PpeController::class, 'updateType'])->name('types.update');
            Route::patch('/types/{type}/activate', [PpeController::class, 'activateType'])->name('types.activate');
            Route::patch('/types/{type}/deactivate', [PpeController::class, 'deactivateType'])->name('types.deactivate');

            // PPE Inventory
            Route::post('/inventory', [PpeController::class, 'storeInventory'])->name('inventory.store');
            Route::put('/inventory/{inventory}', [PpeController::class, 'updateInventory'])->name('inventory.update');
            Route::post('/inventory/{inventory}/condemn', [PpeController::class, 'condemn'])->name('inventory.condemn');
            Route::post('/inventory/{inventory}/dispose', [PpeController::class, 'dispose'])->name('inventory.dispose');

            // Allocations
            Route::post('/inventory/{inventory}/allocate', [PpeController::class, 'allocate'])->name('inventory.allocate');
            Route::post('/allocations/{allocation}/acknowledge', [PpeController::class, 'acknowledge'])->name('allocations.acknowledge');
            Route::post('/allocations/{allocation}/return', [PpeController::class, 'returnPpe'])->name('allocations.return');

            // Inspections
            Route::post('/inventory/{inventory}/inspections', [PpeController::class, 'storeInspection'])->name('inventory.inspections.store');

            // Evidence (premium document upload) — uploads + deletes
            Route::post('/inventory/{inventory}/attachments', [PpeController::class, 'uploadInventoryAttachment'])->name('inventory.attachments.store');
            Route::delete('/inventory/{inventory}/attachments/{attachment}', [PpeController::class, 'destroyInventoryAttachment'])->name('inventory.attachments.destroy');
            Route::post('/allocations/{allocation}/attachments', [PpeController::class, 'uploadAllocationAttachment'])->name('allocations.attachments.store');
            Route::delete('/allocations/{allocation}/attachments/{attachment}', [PpeController::class, 'destroyAllocationAttachment'])->name('allocations.attachments.destroy');
            Route::post('/inspections/{inspection}/attachments', [PpeController::class, 'uploadInspectionAttachment'])->name('inspections.attachments.store');
            Route::delete('/inspections/{inspection}/attachments/{attachment}', [PpeController::class, 'destroyInspectionAttachment'])->name('inspections.attachments.destroy');
        });
    });
});
