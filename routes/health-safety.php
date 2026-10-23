<?php

use App\Http\Controllers\HealthSafety\BehaviourSupportPlanWorkspaceController;
use App\Http\Controllers\HealthSafety\ChemicalPublicWorkspaceController;
use App\Http\Controllers\HealthSafety\ChemicalExposureWorkspaceController;
use App\Http\Controllers\HealthSafety\EmergencyDrillController;
use App\Http\Controllers\HealthSafety\FirstAidController;
use App\Http\Controllers\HealthSafety\HazardousSubstanceController;
use App\Http\Controllers\HealthSafety\HealthSafetyDashboardController;
use App\Http\Controllers\HealthSafety\HealthSafetySettingsController;
use App\Http\Controllers\HealthSafety\HsCorrectiveActionController;
use App\Http\Controllers\HealthSafety\HsCorrectiveActionEvidenceController;
use App\Http\Controllers\HealthSafety\HsEventController;
use App\Http\Controllers\HealthSafety\HsGovernanceReportController;
use App\Http\Controllers\HealthSafety\HsInvestigationController;
use App\Http\Controllers\HealthSafety\HsRiskAssessmentController;
use App\Http\Controllers\HealthSafety\HsWorksafeDecisionController;
use App\Http\Controllers\HealthSafety\HsWorkspaceController;
use App\Http\Controllers\HealthSafety\LoneWorkerController;
use App\Http\Controllers\HealthSafety\PpeController;
use App\Http\Controllers\HealthSafety\ProcedureReviewRequestController;
use App\Http\Controllers\HealthSafety\RestraintController;
use App\Http\Controllers\HealthSafety\RestraintEventWorkspaceController;
use App\Http\Controllers\HealthSafety\ReturnToWorkController;
use App\Http\Controllers\HealthSafety\SafeWorkProcedureController;
use App\Http\Controllers\HealthSafety\WorkerParticipationController;
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

    // Preserve an explicit route gate with the same grant and current approved staff.
    // The owning settings services independently recheck at read/write acceptance.
    Route::middleware(\App\Http\Middleware\CurrentHealthSafetySettingsAccess::class)->group(function () {
        Route::get('/settings/evidence-windows', [\App\Http\Controllers\HealthSafety\IncidentEvidenceWindowSettingsController::class, 'index'])->name('settings.evidence-windows.index');
        Route::put('/settings/evidence-windows', [\App\Http\Controllers\HealthSafety\IncidentEvidenceWindowSettingsController::class, 'update'])->name('settings.evidence-windows.update');
        Route::get('/settings/evidence-windows/requests/{idempotency_key}', [\App\Http\Controllers\HealthSafety\IncidentEvidenceWindowSettingsController::class, 'recover'])->name('settings.evidence-windows.recover');
        Route::get('/settings', [HealthSafetySettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/restraint-plan-approval', [HealthSafetySettingsController::class, 'update'])->name('settings.restraint-plan-approval.update');
        Route::get('/settings/incident-routing', [\App\Http\Controllers\HealthSafety\IncidentRoutingSettingsController::class, 'index'])->name('settings.incident-routing.index');
        Route::get('/settings/incident-routing/candidates', [\App\Http\Controllers\HealthSafety\IncidentRoutingSettingsController::class, 'candidates'])->name('settings.incident-routing.candidates');
        Route::put('/settings/incident-routing', [\App\Http\Controllers\HealthSafety\IncidentRoutingSettingsController::class, 'update'])->name('settings.incident-routing.update');
        Route::get('/settings/incident-independence', [\App\Http\Controllers\HealthSafety\IncidentIndependenceSettingsController::class, 'index'])->name('settings.incident-independence.index');
        Route::put('/settings/incident-independence', [\App\Http\Controllers\HealthSafety\IncidentIndependenceSettingsController::class, 'update'])->name('settings.incident-independence.update');
    });

    Route::get('/registers', [HsWorkspaceController::class, 'registers'])
        ->middleware('permission:hazards.view|restraints.view|hr.wellbeing.view')->name('registers');
    Route::get('/reports', [HsWorkspaceController::class, 'reports'])
        ->middleware('permission:hazards.view|governance.view|compliance.view|privacy.viewRequests')->name('reports.index');

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
        Route::get('/corrective-actions/{action}', [HsCorrectiveActionController::class, 'show'])->name('corrective-actions.show');
        Route::get('/corrective-actions/{action}/owner-candidates', [HsCorrectiveActionController::class, 'ownerCandidates'])->name('corrective-actions.owner-candidates');
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
        Route::post('/events/{hsEvent}/worksafe/decision', HsWorksafeDecisionController::class)->name('events.worksafe.decision');
        Route::post('/events/{hsEvent}/worksafe/notify', [HsEventController::class, 'worksafeNotify'])->name('events.worksafe.notify');
        Route::post('/events/{hsEvent}/worksafe/acknowledge', [HsEventController::class, 'worksafeAcknowledge'])->name('events.worksafe.acknowledge');
        Route::post('/events/{hsEvent}/worksafe/site-preservation', [HsEventController::class, 'worksafeSitePreservation'])->name('events.worksafe.site-preservation');
        Route::post('/events/{hsEvent}/worksafe/site-preservation/release', [HsEventController::class, 'worksafeSitePreservationRelease'])->name('events.worksafe.site-preservation.release');

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
        Route::post('/corrective-actions/{action}/owner', [HsCorrectiveActionController::class, 'assignOwner'])->name('corrective-actions.owner');
        Route::post('/events/{event}/corrective-actions/{action}/start', [HsCorrectiveActionController::class, 'start'])->name('events.corrective-actions.start');
        Route::post('/events/{event}/corrective-actions/{action}/complete', [HsCorrectiveActionController::class, 'complete'])->name('events.corrective-actions.complete');
        Route::post('/events/{event}/corrective-actions/{action}/verify', [HsCorrectiveActionController::class, 'verify'])->name('events.corrective-actions.verify');
        Route::post('/events/{event}/corrective-actions/{action}/close', [HsCorrectiveActionController::class, 'close'])->name('events.corrective-actions.close');
        Route::post('/events/{event}/corrective-actions/{action}/return', [HsCorrectiveActionController::class, 'returnForRework'])->name('events.corrective-actions.return');
    });

    Route::middleware('permission:healthSafety.events.close')->group(function () {
        Route::post('/events/{hsEvent}/close', [HsEventController::class, 'close'])->name('events.close');
    });
    Route::middleware('permission:healthSafety.closureExceptions.request')->group(function () {
        Route::post('/events/{hsEvent}/closure-exceptions', [HsEventController::class, 'requestClosureException'])
            ->name('events.closure-exceptions.request');
    });
    Route::middleware('permission:healthSafety.closureExceptions.approve')->group(function () {
        Route::post('/events/{hsEvent}/closure-exceptions/{exception}/decision', [HsEventController::class, 'decideClosureException'])
            ->name('events.closure-exceptions.decision');
        Route::post('/events/{hsEvent}/closure-exceptions/{exception}/revoke', [HsEventController::class, 'revokeClosureException'])
            ->name('events.closure-exceptions.revoke');
    });

    // ── Risk Assessment write actions (gated) — all delegate to HsRiskAssessmentService ──
    Route::middleware('permission:hazards.manage')->group(function () {
        Route::post('/risk-assessments', [HsRiskAssessmentController::class, 'store'])->name('risk-assessments.store');
        Route::put('/risk-assessments/{assessment}', [HsRiskAssessmentController::class, 'update'])->name('risk-assessments.update');
        Route::post('/risk-assessments/{assessment}/activate', [HsRiskAssessmentController::class, 'activate'])->name('risk-assessments.activate');
        Route::post('/risk-assessments/{assessment}/review', [HsRiskAssessmentController::class, 'markForReview'])->name('risk-assessments.review');
        Route::post('/risk-assessments/{assessment}/complete-review', [HsRiskAssessmentController::class, 'completeReview'])->name('risk-assessments.complete-review');
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

    // First Aid factual source commands; each service rechecks current actor and exact source.
    Route::prefix('first-aid')->name('first-aid.')->group(function () {
        Route::get('/report', [FirstAidController::class, 'report'])->middleware('permission:hazards.manage|hazards.create')->name('report');
        Route::get('/options', [FirstAidController::class, 'options'])->middleware('permission:hazards.view|hazards.manage|hazards.create')->name('options');
        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [FirstAidController::class, 'index'])->name('index');
            Route::get('/export', [FirstAidController::class, 'export'])->name('export');
            Route::get('/{record}/attachments/{attachment}/download', [FirstAidController::class, 'downloadAttachment'])->withTrashed()->name('attachments.download');
            Route::get('/{record}', [FirstAidController::class, 'show'])->withTrashed()->name('show');
        });
        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::post('/', [FirstAidController::class, 'store'])->name('store');
            Route::put('/{record}', [FirstAidController::class, 'update'])->withTrashed()->name('update');
        });
        Route::middleware('permission:hazards.manage')->group(function () {
            Route::delete('/{record}', [FirstAidController::class, 'destroy'])->withTrashed()->name('destroy');
            Route::post('/{record}/link-incident', [FirstAidController::class, 'linkIncident'])->withTrashed()->name('link-incident');
            Route::post('/{record}/followups', [FirstAidController::class, 'addFollowup'])->withTrashed()->name('followups.add');
            Route::put('/{record}/followups/{followup}', [FirstAidController::class, 'updateFollowup'])->withTrashed()->name('followups.update');
            Route::post('/{record}/attachments', [FirstAidController::class, 'uploadAttachment'])->withTrashed()->name('attachments.upload');
            Route::delete('/{record}/attachments/{attachment}', [FirstAidController::class, 'destroyAttachment'])->withTrashed()->name('attachments.destroy');
        });
        Route::patch('/{record}/followups/{followup}/complete', [FirstAidController::class, 'completeFollowup'])->middleware('permission:hazards.manage|hazards.view')->withTrashed()->name('followups.complete');
    });

    // ── Phase 5B: Restraint Register ────────────────────────────────────
    Route::prefix('restraints')->name('restraints.')->group(function () {

        // The register, source workspaces and bounded review/signoff reads retain separate grants.
        Route::middleware('permission:restraints.view')->group(function () {
            Route::get('/', [RestraintController::class, 'workspaceIndex'])->name('index');
            Route::get('/export', [RestraintController::class, 'workspaceExport'])->name('export');
            Route::get('/clients/{client}/summary', [RestraintController::class, 'workspaceClientSummary'])->name('clients.summary');
            Route::get('/events/{event}', [RestraintEventWorkspaceController::class, 'show'])->name('events.show');
            Route::get('/plans/{plan}', [BehaviourSupportPlanWorkspaceController::class, 'show'])->name('plans.show');
        });
        Route::get('/events/{event}/review', [RestraintEventWorkspaceController::class, 'reviewWorkspace'])->middleware('permission:restraints.review|restraints.manage')->name('events.review-workspace');
        Route::get('/plans/{plan}/review', [BehaviourSupportPlanWorkspaceController::class, 'reviewWorkspace'])->middleware('permission:restraints.review|restraints.manage')->name('plans.review-workspace');
        Route::get('/plans/{plan}/approval', [BehaviourSupportPlanWorkspaceController::class, 'approvalWorkspace'])->middleware('permission:restraints.approve|restraints.review|restraints.manage')->name('plans.approval-workspace');
        Route::post('/plans/{plan}/approvals', [BehaviourSupportPlanWorkspaceController::class, 'decision'])->middleware('permission:restraints.approve|restraints.review|restraints.manage')->name('plans.approvals.store');
        Route::get('/events/{event}/attachments/{attachment}/download', [RestraintEventWorkspaceController::class, 'downloadEvidence'])->middleware('permission:restraints.view|restraints.review|restraints.manage')->name('events.attachments.download');

        // Capture — record events, create plans, upload evidence.
        Route::middleware('permission:restraints.create|restraints.manage')->group(function () {
            Route::get('/report', [RestraintEventWorkspaceController::class, 'report'])->name('report');
            Route::post('/events', [RestraintEventWorkspaceController::class, 'store'])->name('events.store');
            Route::post('/plans', [BehaviourSupportPlanWorkspaceController::class, 'store'])->name('plans.store');
            Route::post('/events/{event}/attachments', [RestraintEventWorkspaceController::class, 'addEvidence'])->name('events.attachments.store');
        });

        // Review — event review + plan review sign-off + post-hoc incident link.
        Route::middleware('permission:restraints.review|restraints.manage')->group(function () {
            Route::put('/events/{event}', [RestraintEventWorkspaceController::class, 'review'])->name('events.update');
            Route::post('/events/{event}/corrections', [RestraintEventWorkspaceController::class, 'correct'])->name('events.correct');
            Route::post('/events/{event}/review-assignments', [RestraintEventWorkspaceController::class, 'assignReview'])->name('events.assign-review');
            Route::post('/events/{event}/link-incident', [RestraintEventWorkspaceController::class, 'linkIncident'])->name('events.link-incident');
            Route::post('/events/{event}/unlink-incident', [RestraintEventWorkspaceController::class, 'unlinkIncident'])->name('events.unlink-incident');
            Route::post('/events/{event}/observations', [RestraintEventWorkspaceController::class, 'addObservation'])->name('events.observations.store');
            Route::post('/events/{event}/observations/{observation}/corrections', [RestraintEventWorkspaceController::class, 'correctObservation'])->whereNumber('observation')->name('events.observations.correct');
            Route::post('/events/{event}/debriefs', [RestraintEventWorkspaceController::class, 'recordDebrief'])->name('events.debriefs.store');
            Route::post('/events/{event}/debriefs/{debrief}/corrections', [RestraintEventWorkspaceController::class, 'correctDebrief'])->whereNumber('debrief')->name('events.debriefs.correct');
            Route::post('/events/{event}/followups', [RestraintEventWorkspaceController::class, 'addFollowup'])->name('events.followups.store');
            Route::post('/events/{event}/followups/{followup}/assignment', [RestraintEventWorkspaceController::class, 'reassignFollowup'])->whereNumber('followup')->name('events.followups.reassign');
            Route::post('/events/{event}/followups/{followup}/corrections', [RestraintEventWorkspaceController::class, 'correctFollowup'])->whereNumber('followup')->name('events.followups.correct');
            Route::post('/events/{event}/followups/{followup}/cancel', [RestraintEventWorkspaceController::class, 'cancelFollowup'])->whereNumber('followup')->name('events.followups.cancel');
            Route::post('/plans/{plan}/review', [BehaviourSupportPlanWorkspaceController::class, 'review'])->name('plans.review');
        });
        Route::post('/events/{event}/followups/{followup}/complete', [RestraintEventWorkspaceController::class, 'completeFollowup'])->middleware('permission:restraints.view|restraints.review|restraints.manage')->whereNumber('followup')->name('events.followups.complete');

        // Manage — plan edit + lifecycle + attachment removal.
        Route::middleware('permission:restraints.manage')->group(function () {
            Route::put('/plans/{plan}', [BehaviourSupportPlanWorkspaceController::class, 'update'])->name('plans.update');
            Route::post('/plans/{plan}/activate', [BehaviourSupportPlanWorkspaceController::class, 'activate'])->name('plans.activate');
            Route::post('/plans/{plan}/submit-review', [BehaviourSupportPlanWorkspaceController::class, 'submitReview'])->name('plans.submit-review');
            Route::post('/plans/{plan}/archive', [BehaviourSupportPlanWorkspaceController::class, 'archive'])->name('plans.archive');
            Route::delete('/events/{event}/attachments/{attachment}', [RestraintEventWorkspaceController::class, 'removeEvidence'])->name('events.attachments.destroy');
        });
    });

    Route::get('/events/{event}/procedure-review-options', [ProcedureReviewRequestController::class, 'options'])->middleware('permission:hazards.view')->name('events.procedure-review-options');
    Route::post('/events/{event}/procedure-review-requests', [ProcedureReviewRequestController::class, 'store'])->middleware('permission:hazards.manage')->name('events.procedure-review-requests.store');

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
            Route::get('/{procedure}/edit', [SafeWorkProcedureController::class, 'edit'])->middleware('permission:procedures.manage')->name('edit');
            Route::put('/{procedure}', [SafeWorkProcedureController::class, 'update'])->middleware('permission:procedures.manage')->name('update');
            Route::post('/{procedure}/submit-for-review', [SafeWorkProcedureController::class, 'submitForReview'])->middleware('permission:procedures.manage')->name('submit-for-review');
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

            Route::post('/{procedure}/review-requests/{reviewRequest}/{command}', [ProcedureReviewRequestController::class, 'command'])->whereIn('command', ['start', 'resolve', 'decline', 'cancel', 'reassign'])->name('review-requests.command');

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

    // Ordinary private exposures: auth is retained; each service consumes fresh exact operation/source authority.
    Route::prefix('chemical-exposures')->name('chemical-exposures.')
        ->middleware(\App\Http\Middleware\ChemicalExposureResponseHeaders::class)->group(function () {
            Route::get('/', [ChemicalExposureWorkspaceController::class, 'index'])->name('index');
            Route::get('/capture', [ChemicalExposureWorkspaceController::class, 'capture'])->name('capture');
            Route::get('/capture-options', [ChemicalExposureWorkspaceController::class, 'captureOptions'])->name('capture-options');
            Route::get('/capture-confirmation', [ChemicalExposureWorkspaceController::class, 'captureReceipt'])->name('capture-confirmation');
            Route::get('/{exposure}', [ChemicalExposureWorkspaceController::class, 'show'])->whereNumber('exposure')->name('show');
            Route::get('/{exposure}/confirmation', [ChemicalExposureWorkspaceController::class, 'receipt'])->whereNumber('exposure')->name('confirmation');
            Route::get('/{exposure}/command-options', [ChemicalExposureWorkspaceController::class, 'commandOptions'])->whereNumber('exposure')->name('command-options');
            Route::get('/{exposure}/evidence/{evidence}/download', [ChemicalExposureWorkspaceController::class, 'downloadEvidence'])->whereNumber(['exposure', 'evidence'])->name('evidence.download');
            Route::get('/{exposure}/delivery-attempts', [ChemicalExposureWorkspaceController::class, 'deliveryAttempts'])->whereNumber('exposure')->name('delivery-attempts');
            Route::put('/{exposure}', [ChemicalExposureWorkspaceController::class, 'correct'])->whereNumber('exposure')->name('correct');
            Route::post('/{exposure}/reviews', [ChemicalExposureWorkspaceController::class, 'review'])->whereNumber('exposure')->name('reviews.store');
            Route::post('/{exposure}/followups', [ChemicalExposureWorkspaceController::class, 'assignFollowup'])->whereNumber('exposure')->name('followups.store');
            Route::put('/{exposure}/followups/{followup}/reassign', [ChemicalExposureWorkspaceController::class, 'reassignFollowup'])->whereNumber(['exposure', 'followup'])->name('followups.reassign');
            Route::put('/{exposure}/followups/{followup}/complete', [ChemicalExposureWorkspaceController::class, 'completeFollowup'])->whereNumber(['exposure', 'followup'])->name('followups.complete');
            Route::post('/{exposure}/evidence', [ChemicalExposureWorkspaceController::class, 'uploadEvidence'])->whereNumber('exposure')->name('evidence.store');
            Route::delete('/{exposure}/evidence/{evidence}', [ChemicalExposureWorkspaceController::class, 'removeEvidence'])->whereNumber(['exposure', 'evidence'])->name('evidence.destroy');
        });

    // Public chemical routes use raw IDs; exposure actions retain their separate owner.
    Route::prefix('substances')->name('substances.')->middleware(\App\Http\Middleware\ChemicalResponseHeaders::class)->group(function () {
        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [ChemicalPublicWorkspaceController::class, 'index'])->name('index');
            Route::get('/{substance}/sds/{sds}/download', [ChemicalPublicWorkspaceController::class, 'downloadSds'])
                ->whereNumber(['substance', 'sds'])->name('sds.download');
            Route::get('/{substance}/history', [ChemicalPublicWorkspaceController::class, 'history'])
                ->whereNumber('substance')->name('history');
        });
        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::get('/create', [ChemicalPublicWorkspaceController::class, 'create'])->name('create');
            Route::get('/capture-options', [ChemicalPublicWorkspaceController::class, 'captureOptions'])->name('capture-options');
            Route::post('/', [ChemicalPublicWorkspaceController::class, 'store'])->name('store');
            Route::post('/{substance}/sds', [ChemicalPublicWorkspaceController::class, 'storeSds'])->whereNumber('substance')->name('sds.store');
            Route::post('/{substance}/storage-locations', [ChemicalPublicWorkspaceController::class, 'storeStorageLocation'])
                ->whereNumber('substance')->name('storage-locations.store');
            Route::get('/{substance}/confirmations/{key}', [ChemicalPublicWorkspaceController::class, 'confirmation'])
                ->whereNumber('substance')->name('confirmation');
            Route::get('/{substance}/sds/recover/{key}', [ChemicalPublicWorkspaceController::class, 'recoverSds'])
                ->whereNumber('substance')->name('sds.recover');

        });
        Route::middleware('permission:hazards.manage')->group(function () {
            Route::put('/{substance}', [ChemicalPublicWorkspaceController::class, 'update'])->whereNumber('substance')->name('update');
            Route::patch('/{substance}/status', [ChemicalPublicWorkspaceController::class, 'updateStatus'])->whereNumber('substance')->name('status');
            Route::put('/{substance}/storage-locations/{storage}', [ChemicalPublicWorkspaceController::class, 'updateStorageLocation'])
                ->whereNumber(['substance', 'storage'])->name('storage-locations.update');
            Route::post('/{substance}/storage-locations/{storage}/inventory-audit', [ChemicalPublicWorkspaceController::class, 'auditStorageLocation'])
                ->whereNumber(['substance', 'storage'])->name('storage-locations.audit');
            Route::delete('/{substance}/storage-locations/{storage}', [ChemicalPublicWorkspaceController::class, 'archiveStorageLocation'])
                ->whereNumber(['substance', 'storage'])->name('storage-locations.archive');
            Route::post('/{substance}/storage-locations/{storage}/restore', [ChemicalPublicWorkspaceController::class, 'restoreStorageLocation'])
                ->whereNumber(['substance', 'storage'])->name('storage-locations.restore');
            Route::put('/{substance}/sds/{sds}', [ChemicalPublicWorkspaceController::class, 'correctSdsMetadata'])
                ->whereNumber(['substance', 'sds'])->name('sds.update');
            Route::post('/{substance}/sds/{sds}/current', [ChemicalPublicWorkspaceController::class, 'setCurrentSds'])
                ->whereNumber(['substance', 'sds'])->name('sds.current');
        });
        Route::get('/{substance}', [ChemicalPublicWorkspaceController::class, 'show'])
            ->whereNumber('substance')->middleware('permission:hazards.view')->name('show');
    });

    // Same two legacy URLs/names, delegating their existing capture grant to fresh canonical acceptance.
    Route::post('/substances/{substance}/exposure-records', [ChemicalExposureWorkspaceController::class, 'storeExposureRecord'])
        ->whereNumber('substance')->middleware(\App\Http\Middleware\ChemicalExposureResponseHeaders::class)->name('substances.exposure-records.store');
    Route::post('/substances/{substance}/exposures', [ChemicalExposureWorkspaceController::class, 'storeExposureRecord'])
        ->whereNumber('substance')->middleware(\App\Http\Middleware\ChemicalExposureResponseHeaders::class)->name('substances.exposures.store');

    // ── Emergency Drills ──────────────────────────────────────────────
    Route::prefix('drills')->name('drills.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [EmergencyDrillController::class, 'index'])->name('index');
            Route::get('/export', [EmergencyDrillController::class, 'export'])->name('export');
            Route::get('/options', [EmergencyDrillController::class, 'options'])->name('options');
            Route::get('/{drill}/participants/export', [EmergencyDrillController::class, 'exportParticipants'])->name('participants.export');
            Route::get('/{drill}/evidence/export', [EmergencyDrillController::class, 'exportEvidence'])->name('evidence.export');
            Route::get('/{drill}/findings/{finding}/corrective-action-options', [EmergencyDrillController::class, 'correctiveActionOptions'])->name('findings.corrective-action-options');
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
            Route::put('/findings/{finding}', [EmergencyDrillController::class, 'updateLegacyFinding'])->name('findings.legacy-update');
            Route::put('/{drill}/findings/{finding}', [EmergencyDrillController::class, 'updateFinding'])->name('findings.update');
            Route::post('/{drill}/findings/{finding}/corrective-action', [EmergencyDrillController::class, 'correctiveAction'])->name('findings.corrective-action');
            Route::post('/{drill}/schedule-next', [EmergencyDrillController::class, 'scheduleNext'])->name('schedule-next');
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
            Route::get('/capture-options', [ReturnToWorkController::class, 'captureOptions'])->name('capture-options');
            Route::get('/create', [ReturnToWorkController::class, 'create'])->name('create');
            Route::post('/', [ReturnToWorkController::class, 'store'])->name('store');
        });
        Route::middleware('permission:hazards.manage')->group(function () {
            Route::get('/{injury}/command-options', [ReturnToWorkController::class, 'commandOptions'])->name('command-options');
            Route::get('/{injury}/recovery-options', [ReturnToWorkController::class, 'recoveryOptions'])->name('recovery-options');
            Route::post('/{injury}/coordinator', [ReturnToWorkController::class, 'assignCoordinator'])->name('coordinator');
            Route::post('/{injury}/reviews', [ReturnToWorkController::class, 'recordReview'])->name('reviews');
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
            Route::delete('/{injury}/attachments/{attachment}', [ReturnToWorkController::class, 'destroyAttachment'])->withTrashed()->name('attachments.destroy');
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
            Route::get('/geocode/search', [LoneWorkerController::class, 'searchAddress'])->name('geocode.search');
        });

        // Worker self check-in (auth-only). The 3-actor model puts the lone
        // WORKER's one-tap check-in on My Day, not the coordinator watch-tower.
        // Frontline support workers have NO hazards.* permission, so this route
        // must sit OUTSIDE the hazards.manage group or worker self-check-in would
        // 403. Authorization is enforced inside checkIn(): the session's own
        // worker, or a coordinator with hazards.manage, may record a check-in.
        Route::post('/sessions/{session}/check-in', [LoneWorkerController::class, 'checkIn'])->name('sessions.check-in');
        Route::get('/options', [LoneWorkerController::class, 'options'])->name('options');
        Route::get('/sessions/{session}', [LoneWorkerController::class, 'show'])->name('sessions.show');
        Route::post('/sessions/start-own', [LoneWorkerController::class, 'startOwnSession'])->name('sessions.start-own');
        Route::post('/sessions/{session}/end-own', [LoneWorkerController::class, 'endOwnSession'])->name('sessions.end-own');
        Route::post('/sessions/{session}/emergency', [LoneWorkerController::class, 'triggerEmergency'])->name('sessions.emergency');

        Route::middleware('permission:hazards.manage')->group(function () {
            Route::post('/sessions', [LoneWorkerController::class, 'startSession'])->name('sessions.store');
            Route::patch('/sessions/{session}', [LoneWorkerController::class, 'updateSession'])->name('sessions.update');
            Route::post('/sessions/{session}/end', [LoneWorkerController::class, 'endSession'])->name('sessions.end');
            Route::delete('/sessions/{session}', [LoneWorkerController::class, 'destroy'])->name('sessions.destroy');
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
        Route::post('/allocations/{allocation}/acknowledge-own', [PpeController::class, 'acknowledgeOwn'])->name('allocations.acknowledge-own')->whereNumber('allocation');
        Route::get('/allocations/{allocation}', [PpeController::class, 'showAllocation'])->whereNumber('allocation')->name('allocations.show');
        Route::get('/command-confirmation', [PpeController::class, 'commandConfirmation'])->name('command-confirmation');

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [PpeController::class, 'index'])->name('index');
            Route::get('/export', [PpeController::class, 'export'])->name('export');
            Route::get('/inventory/{inventory}', [PpeController::class, 'showInventory'])->whereNumber('inventory')->name('inventory.show');
            Route::get('/inspections/{inspection}', [PpeController::class, 'showInspection'])->whereNumber('inspection')->name('inspections.show');

            // Evidence downloads (read)
            Route::get('/inventory/{inventory}/attachments/{attachment}/download', [PpeController::class, 'downloadInventoryAttachment'])->name('inventory.attachments.download')->whereNumber('inventory')->whereNumber('attachment');
            Route::get('/allocations/{allocation}/attachments/{attachment}/download', [PpeController::class, 'downloadAllocationAttachment'])->name('allocations.attachments.download')->whereNumber('allocation')->whereNumber('attachment');
            Route::get('/inspections/{inspection}/attachments/{attachment}/download', [PpeController::class, 'downloadInspectionAttachment'])->name('inspections.attachments.download')->whereNumber('inspection')->whereNumber('attachment');
        });

        Route::middleware('permission:hazards.manage')->group(function () {
            Route::get('/options', [PpeController::class, 'options'])->name('options');
            Route::get('/recovery', [PpeController::class, 'evidenceRecovery'])->name('recovery');
            Route::get('/evidence-staging', [PpeController::class, 'stagingAttempts'])->name('staging.index');
            Route::get('/evidence-staging/{staging}', [PpeController::class, 'stagingAttempt'])->whereNumber('staging')->name('staging.show');
            Route::post('/evidence-staging/{staging}/retry', [PpeController::class, 'resumeStaging'])->whereNumber('staging')->name('staging.retry');
            Route::get('/cleanup', [PpeController::class, 'cleanupAttempts'])->name('cleanup.index');
            Route::get('/relocation-cleanup', [PpeController::class, 'relocationCleanupAttempts'])->name('relocation-cleanup.index');
            Route::get('/relocation-cleanup/{cleanup}', [PpeController::class, 'relocationCleanup'])->whereNumber('cleanup')->name('relocation-cleanup.show');
            Route::post('/relocation-cleanup/{cleanup}/retry', [PpeController::class, 'resumeRelocationCleanup'])->whereNumber('cleanup')->name('relocation-cleanup.retry');
            Route::get('/cleanup/{cleanup}', [PpeController::class, 'cleanup'])->whereNumber('cleanup')->name('cleanup.show');
            Route::post('/cleanup/{cleanup}/retry', [PpeController::class, 'resumeCleanup'])->whereNumber('cleanup')->name('cleanup.retry');
            // PPE Types
            Route::post('/types', [PpeController::class, 'storeType'])->name('types.store');
            Route::put('/types/{type}', [PpeController::class, 'updateType'])->name('types.update')->whereNumber('type');
            Route::patch('/types/{type}/activate', [PpeController::class, 'activateType'])->name('types.activate')->whereNumber('type');
            Route::patch('/types/{type}/deactivate', [PpeController::class, 'deactivateType'])->name('types.deactivate')->whereNumber('type');

            // PPE Inventory
            Route::post('/inventory', [PpeController::class, 'storeInventory'])->name('inventory.store');
            Route::put('/inventory/{inventory}', [PpeController::class, 'updateInventory'])->name('inventory.update')->whereNumber('inventory');
            Route::post('/inventory/{inventory}/condemn', [PpeController::class, 'condemn'])->name('inventory.condemn')->whereNumber('inventory');
            Route::post('/inventory/{inventory}/dispose', [PpeController::class, 'dispose'])->name('inventory.dispose')->whereNumber('inventory');

            // Allocations
            Route::post('/inventory/{inventory}/allocate', [PpeController::class, 'allocate'])->name('inventory.allocate')->whereNumber('inventory');
            Route::post('/allocations/{allocation}/acknowledge', [PpeController::class, 'acknowledge'])->name('allocations.acknowledge')->whereNumber('allocation');
            Route::post('/allocations/{allocation}/return', [PpeController::class, 'returnPpe'])->name('allocations.return')->whereNumber('allocation');

            // Inspections
            Route::post('/inventory/{inventory}/inspections', [PpeController::class, 'storeInspection'])->name('inventory.inspections.store')->whereNumber('inventory');

            // Evidence (premium document upload) — uploads + deletes
            Route::post('/inventory/{inventory}/attachments', [PpeController::class, 'uploadInventoryAttachment'])->name('inventory.attachments.store')->whereNumber('inventory');
            Route::post('/inventory/{inventory}/attachments/{attachment}/relocate', [PpeController::class, 'relocateInventoryAttachment'])->whereNumber('inventory')->whereNumber('attachment')->name('inventory.attachments.relocate');
            Route::post('/allocations/{allocation}/attachments/{attachment}/relocate', [PpeController::class, 'relocateAllocationAttachment'])->whereNumber('allocation')->whereNumber('attachment')->name('allocations.attachments.relocate');
            Route::post('/inspections/{inspection}/attachments/{attachment}/relocate', [PpeController::class, 'relocateInspectionAttachment'])->whereNumber('inspection')->whereNumber('attachment')->name('inspections.attachments.relocate');
            Route::delete('/inventory/{inventory}/attachments/{attachment}', [PpeController::class, 'destroyInventoryAttachment'])->name('inventory.attachments.destroy')->whereNumber('inventory')->whereNumber('attachment');
            Route::post('/allocations/{allocation}/attachments', [PpeController::class, 'uploadAllocationAttachment'])->name('allocations.attachments.store')->whereNumber('allocation');
            Route::delete('/allocations/{allocation}/attachments/{attachment}', [PpeController::class, 'destroyAllocationAttachment'])->name('allocations.attachments.destroy')->whereNumber('allocation')->whereNumber('attachment');
            Route::post('/inspections/{inspection}/attachments', [PpeController::class, 'uploadInspectionAttachment'])->name('inspections.attachments.store')->whereNumber('inspection');
            Route::delete('/inspections/{inspection}/attachments/{attachment}', [PpeController::class, 'destroyInspectionAttachment'])->name('inspections.attachments.destroy')->whereNumber('inspection')->whereNumber('attachment');
        });
    });
});
