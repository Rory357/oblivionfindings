<?php

use App\Http\Controllers\HealthSafety\EmergencyDrillController;
use App\Http\Controllers\HealthSafety\FirstAidController;
use App\Http\Controllers\HealthSafety\HazardousSubstanceController;
use App\Http\Controllers\HealthSafety\HealthSafetyDashboardController;
use App\Http\Controllers\HealthSafety\HsCorrectiveActionController;
use App\Http\Controllers\HealthSafety\HsEventController;
use App\Http\Controllers\HealthSafety\HsGovernanceReportController;
use App\Http\Controllers\HealthSafety\HsInvestigationController;
use App\Http\Controllers\HealthSafety\LoneWorkerController;
use App\Http\Controllers\HealthSafety\PpeController;
use App\Http\Controllers\HealthSafety\RestraintController;
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
        Route::get('/corrective-actions', [HsEventController::class, 'correctiveActions'])->name('corrective-actions.index');
        Route::get('/risk-assessments', [HsEventController::class, 'riskAssessments'])->name('risk-assessments.index');
    });

    // ── Events governance write actions (gated) ──
    Route::middleware('permission:hazards.manage')->group(function () {
        Route::post('/events/{hsEvent}/close', [HsEventController::class, 'close'])->name('events.close');
        Route::post('/events/{hsEvent}/worksafe/notify', [HsEventController::class, 'worksafeNotify'])->name('events.worksafe.notify');
        Route::post('/events/{hsEvent}/worksafe/acknowledge', [HsEventController::class, 'worksafeAcknowledge'])->name('events.worksafe.acknowledge');

        // Investigation workflow (exposes HsInvestigationService)
        Route::post('/events/{event}/investigations', [HsInvestigationController::class, 'store'])->name('events.investigations.store');
        Route::post('/events/{event}/investigations/{investigation}/findings', [HsInvestigationController::class, 'recordFindings'])->name('events.investigations.findings');
        Route::post('/events/{event}/investigations/{investigation}/submit', [HsInvestigationController::class, 'submit'])->name('events.investigations.submit');
        Route::post('/events/{event}/investigations/{investigation}/return', [HsInvestigationController::class, 'returnForRework'])->name('events.investigations.return');
        Route::post('/events/{event}/investigations/{investigation}/complete', [HsInvestigationController::class, 'complete'])->name('events.investigations.complete');
        Route::post('/events/{event}/investigations/{investigation}/seed-action', [HsCorrectiveActionController::class, 'seedFromRecommendation'])->name('events.investigations.seed-action');

        // Corrective-action workflow (exposes HsCorrectiveActionService)
        Route::post('/events/{event}/corrective-actions', [HsCorrectiveActionController::class, 'store'])->name('events.corrective-actions.store');
        Route::post('/events/{event}/corrective-actions/{action}/start', [HsCorrectiveActionController::class, 'start'])->name('events.corrective-actions.start');
        Route::post('/events/{event}/corrective-actions/{action}/complete', [HsCorrectiveActionController::class, 'complete'])->name('events.corrective-actions.complete');
        Route::post('/events/{event}/corrective-actions/{action}/verify', [HsCorrectiveActionController::class, 'verify'])->name('events.corrective-actions.verify');
        Route::post('/events/{event}/corrective-actions/{action}/close', [HsCorrectiveActionController::class, 'close'])->name('events.corrective-actions.close');
        Route::post('/events/{event}/corrective-actions/{action}/return', [HsCorrectiveActionController::class, 'returnForRework'])->name('events.corrective-actions.return');
    });

    // ── PR6: Governance & Compliance Reports ──
    Route::middleware('permission:governance.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/board-summary', [HsGovernanceReportController::class, 'boardSummary'])->name('board-summary');
        Route::get('/worksafe-register', [HsGovernanceReportController::class, 'worksafeRegister'])->name('worksafe-register');
        Route::get('/investigation-outcomes', [HsGovernanceReportController::class, 'investigationOutcomes'])->name('investigation-outcomes');
        Route::get('/corrective-action-traceability', [HsGovernanceReportController::class, 'correctiveActionTraceability'])->name('corrective-action-traceability');
        Route::get('/risk-assessment-register', [HsGovernanceReportController::class, 'riskAssessmentRegister'])->name('risk-assessment-register');
    });

    // ── Phase 5A: First Aid Register ────────────────────────────────────
    Route::prefix('first-aid')->name('first-aid.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [FirstAidController::class, 'index'])->name('index');
        });

        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::post('/', [FirstAidController::class, 'store'])->name('store');
        });
    });

    // ── Phase 5B: Restraint Register ────────────────────────────────────
    Route::prefix('restraints')->name('restraints.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [RestraintController::class, 'index'])->name('index');
        });

        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            // Restraint Events
            Route::post('/events', [RestraintController::class, 'storeEvent'])->name('events.store');
            Route::post('/plans', [RestraintController::class, 'storePlan'])->name('plans.store');
        });
        Route::middleware('permission:hazards.manage')->group(function () {
            Route::put('/events/{event}', [RestraintController::class, 'updateEvent'])->name('events.update');
            Route::put('/plans/{plan}', [RestraintController::class, 'updatePlan'])->name('plans.update');
        });
    });

    // ── Phase 5C: Safe Work Procedures ──────────────────────────────────
    Route::prefix('procedures')->name('procedures.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [SafeWorkProcedureController::class, 'index'])->name('index');
        });

        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::get('/create', [SafeWorkProcedureController::class, 'create'])->name('create');
            Route::post('/', [SafeWorkProcedureController::class, 'store'])->name('store');
        });
        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::get('/{procedure}/edit', [SafeWorkProcedureController::class, 'edit'])->name('edit');
            Route::put('/{procedure}', [SafeWorkProcedureController::class, 'update'])->name('update');
            Route::post('/{procedure}/submit-for-review', [SafeWorkProcedureController::class, 'submitForReview'])->name('submit-for-review');
        });
        Route::middleware('permission:hazards.manage')->group(function () {
            Route::post('/{procedure}/approve', [SafeWorkProcedureController::class, 'approve'])->name('approve');
        });

        // Show route after /create and /edit to avoid wildcard conflict
        Route::get('/{procedure}', [SafeWorkProcedureController::class, 'show'])
            ->middleware('permission:hazards.view')
            ->name('show');
    });

    // ── Worker Participation ──────────────────────────────────────────
    Route::prefix('worker-participation')->name('worker-participation.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [WorkerParticipationController::class, 'index'])->name('index');
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
            Route::get('/consultations/{consultation}/documents/{type}', [WorkerParticipationController::class, 'downloadConsultationDocument'])->name('consultations.documents.download');

            // Meeting workflow
            Route::post('/meetings/{meeting}/attendees', [WorkerParticipationController::class, 'addMeetingAttendees'])->name('meetings.attendees');
            Route::put('/meetings/{meeting}/complete', [WorkerParticipationController::class, 'completeMeeting'])->name('meetings.complete');
            Route::put('/meetings/{meeting}/cancel', [WorkerParticipationController::class, 'cancelMeeting'])->name('meetings.cancel');
            Route::post('/meetings/{meeting}/minutes', [WorkerParticipationController::class, 'uploadMeetingMinutes'])->name('meetings.minutes.upload');
            Route::get('/meetings/{meeting}/minutes/download', [WorkerParticipationController::class, 'downloadMeetingMinutes'])->name('meetings.minutes.download');
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

            // Participants
            Route::post('/{drill}/participants', [EmergencyDrillController::class, 'addParticipant'])->name('participants.store');

            // Findings
            Route::post('/{drill}/findings', [EmergencyDrillController::class, 'addFinding'])->name('findings.store');
            Route::put('/findings/{finding}', [EmergencyDrillController::class, 'updateFinding'])->name('findings.update');
        });

        // Show route after /create to avoid wildcard conflict
        Route::get('/{drill}', [EmergencyDrillController::class, 'show'])
            ->middleware('permission:hazards.view')
            ->name('show');
    });

    // ── Workplace Injuries & Return to Work ───────────────────────────
    Route::prefix('injuries')->name('injuries.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [ReturnToWorkController::class, 'index'])->name('index');
        });

        Route::middleware('permission:hazards.manage|hazards.create')->group(function () {
            Route::get('/create', [ReturnToWorkController::class, 'create'])->name('create');
            Route::post('/', [ReturnToWorkController::class, 'store'])->name('store');
        });
        Route::middleware('permission:hazards.manage')->group(function () {
            Route::put('/{injury}', [ReturnToWorkController::class, 'update'])->name('update');

            // Return to Work Plans
            Route::post('/{injury}/rtw-plans', [ReturnToWorkController::class, 'storeRtwPlan'])->name('rtw-plans.store');
            Route::put('/rtw-plans/{rtwPlan}', [ReturnToWorkController::class, 'updateRtwPlan'])->name('rtw-plans.update');

            // Capacity Assessments
            Route::post('/{injury}/capacity-assessments', [ReturnToWorkController::class, 'storeCapacityAssessment'])->name('capacity-assessments.store');

            // Modified Duties
            Route::post('/rtw-plans/{rtwPlan}/modified-duties', [ReturnToWorkController::class, 'storeModifiedDuty'])->name('modified-duties.store');
        });

        // Show route after /create to avoid wildcard conflict
        Route::get('/{injury}', [ReturnToWorkController::class, 'show'])
            ->middleware('permission:hazards.view')
            ->name('show');
    });

    // ── Lone Worker Safety (Phase 3) ──────────────────────────────────
    Route::prefix('lone-workers')->name('lone-workers.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [LoneWorkerController::class, 'index'])->name('index');
        });

        Route::middleware('permission:hazards.manage')->group(function () {
            Route::post('/sessions', [LoneWorkerController::class, 'startSession'])->name('sessions.store');
            Route::patch('/sessions/{session}', [LoneWorkerController::class, 'updateSession'])->name('sessions.update');
            Route::post('/sessions/{session}/check-in', [LoneWorkerController::class, 'checkIn'])->name('sessions.check-in');
            Route::post('/sessions/{session}/end', [LoneWorkerController::class, 'endSession'])->name('sessions.end');
            Route::post('/sessions/{session}/emergency', [LoneWorkerController::class, 'triggerEmergency'])->name('sessions.emergency');

            // Alerts
            Route::post('/alerts/{alert}/acknowledge', [LoneWorkerController::class, 'acknowledgeAlert'])->name('alerts.acknowledge');
            Route::post('/alerts/{alert}/resolve', [LoneWorkerController::class, 'resolveAlert'])->name('alerts.resolve');
        });
    });

    // ── PPE Management (Phase 3) ──────────────────────────────────────
    Route::prefix('ppe')->name('ppe.')->group(function () {

        Route::middleware('permission:hazards.view')->group(function () {
            Route::get('/', [PpeController::class, 'index'])->name('index');
        });

        Route::middleware('permission:hazards.manage')->group(function () {
            // PPE Types
            Route::post('/types', [PpeController::class, 'storeType'])->name('types.store');

            // PPE Inventory
            Route::post('/inventory', [PpeController::class, 'storeInventory'])->name('inventory.store');
            Route::put('/inventory/{inventory}', [PpeController::class, 'updateInventory'])->name('inventory.update');

            // Allocations
            Route::post('/inventory/{inventory}/allocate', [PpeController::class, 'allocate'])->name('inventory.allocate');
            Route::post('/allocations/{allocation}/return', [PpeController::class, 'returnPpe'])->name('allocations.return');

            // Inspections
            Route::post('/inventory/{inventory}/inspections', [PpeController::class, 'storeInspection'])->name('inventory.inspections.store');
        });
    });
});
