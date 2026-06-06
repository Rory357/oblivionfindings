<?php

use App\Http\Controllers\Respite\RespiteBookingController;
use App\Http\Controllers\Respite\RespiteBookingRequestController;
use App\Http\Controllers\Respite\RespiteCommunicationLogController;
use App\Http\Controllers\Respite\RespiteDailyNoteController;
use App\Http\Controllers\Respite\RespiteEvidencePackController;
use App\Http\Controllers\Respite\RespiteHandoverNoteController;
use App\Http\Controllers\Respite\RespiteProcedureRunController;
use App\Http\Controllers\Respite\RespiteProcedureTemplateController;
use App\Http\Controllers\Respite\RespiteReferralController;
use App\Http\Controllers\Respite\RespiteResourceAllocationController;
use App\Http\Controllers\Respite\RespiteRiskPlanActivationController;
use App\Http\Controllers\Respite\RespiteStayController;
use App\Http\Controllers\Respite\RespiteTaskController;
use App\Http\Controllers\Respite\RespiteWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::middleware('permission:respite.viewAny')->group(function () {
        // The single tabbed workspace (Overview / Referrals / Requests / Bookings / Calendar / Stays).
        Route::get('/respite', [RespiteWorkspaceController::class, 'index'])->name('respite.index');
    });

    // Legacy index routes now redirect into the workspace tabs so old links/bookmarks
    // survive. GET-only (a closure, not Route::redirect which registers ANY) so they
    // never shadow the POST store routes that share these URIs.
    Route::get('/respite/calendar', fn () => redirect('/respite?tab=calendar'))->name('respite.calendar');
    Route::get('/respite/requests', fn () => redirect('/respite?tab=requests'))->name('respite.requests.index');
    Route::get('/respite/bookings', fn () => redirect('/respite?tab=bookings'))->name('respite.bookings.index');
    Route::get('/respite/stays', fn () => redirect('/respite?tab=stays'))->name('respite.stays.index');

    // Referrals
    Route::middleware('permission:respite.create')->group(function () {
        Route::get('/respite/referrals/create', [RespiteReferralController::class, 'create'])->name('respite.referrals.create');
        Route::post('/respite/referrals', [RespiteReferralController::class, 'store'])->name('respite.referrals.store');
    });
    Route::get('/respite/referrals/{referral}', [RespiteReferralController::class, 'show'])
        ->middleware('permission:respite.viewAny')
        ->name('respite.referrals.show');
    Route::put('/respite/referrals/{referral}', [RespiteReferralController::class, 'update'])
        ->middleware('permission:respite.update')
        ->name('respite.referrals.update');

    // Booking requests
    Route::middleware('permission:respite.create')->group(function () {
        Route::get('/respite/requests/create', [RespiteBookingRequestController::class, 'create'])->name('respite.requests.create');
        Route::post('/respite/requests', [RespiteBookingRequestController::class, 'store'])->name('respite.requests.store');
    });
    // index handled by the workspace; legacy /respite/requests redirects above.
    Route::get('/respite/requests/{request}', [RespiteBookingRequestController::class, 'show'])
        ->middleware('permission:respite.viewAny')
        ->name('respite.requests.show');
    Route::put('/respite/requests/{request}', [RespiteBookingRequestController::class, 'update'])
        ->middleware('permission:respite.update')
        ->name('respite.requests.update');
    Route::post('/respite/requests/{request}/approve', [RespiteBookingRequestController::class, 'approve'])
        ->middleware('permission:respite.bookings.manage')
        ->name('respite.requests.approve');
    Route::post('/respite/requests/{request}/promote', [RespiteBookingRequestController::class, 'promote'])
        ->middleware('permission:respite.bookings.manage')
        ->name('respite.requests.promote');

    // Bookings
    Route::middleware('permission:respite.bookings.manage')->group(function () {
        // index handled by the workspace; legacy /respite/bookings redirects above.
        Route::get('/respite/bookings/create', [RespiteBookingController::class, 'create'])->name('respite.bookings.create');
        Route::post('/respite/bookings', [RespiteBookingController::class, 'store'])->name('respite.bookings.store');
        Route::post('/respite/bookings/{booking}/confirm', [RespiteBookingController::class, 'confirm'])->name('respite.bookings.confirm');
    });
    Route::get('/respite/bookings/{booking}', [RespiteBookingController::class, 'show'])
        ->middleware('permission:respite.viewAny')
        ->name('respite.bookings.show');
    Route::put('/respite/bookings/{booking}', [RespiteBookingController::class, 'update'])
        ->middleware('permission:respite.update')
        ->name('respite.bookings.update');

    // Stays — index handled by the workspace; legacy /respite/stays redirects above.
    Route::middleware('permission:respite.stays.manage')->group(function () {
        Route::post('/respite/stays', [RespiteStayController::class, 'store'])->name('respite.stays.store');
        Route::post('/respite/stays/{stay}/check-in', [RespiteStayController::class, 'checkIn'])->name('respite.stays.checkin');
        Route::post('/respite/stays/{stay}/extend', [RespiteStayController::class, 'extend'])->name('respite.stays.extend');
        Route::post('/respite/stays/{stay}/discharge', [RespiteStayController::class, 'discharge'])->name('respite.stays.discharge');
        Route::post('/respite/stays/{stay}/restraints', [RespiteStayController::class, 'recordRestraint'])->name('respite.stays.restraints.store');
        Route::post('/respite/stays/{stay}/incidents', [RespiteStayController::class, 'recordIncident'])->name('respite.stays.incidents.store');
    });
    Route::get('/respite/stays/{stay}', [RespiteStayController::class, 'show'])
        ->middleware('permission:respite.viewAny')
        ->name('respite.stays.show');

    // Resources
    Route::middleware('permission:respite.resources.manage')->group(function () {
        Route::get('/respite/resources', [RespiteResourceAllocationController::class, 'index'])->name('respite.resources.index');
        Route::post('/respite/resources', [RespiteResourceAllocationController::class, 'store'])->name('respite.resources.store');
        Route::delete('/respite/resources/{allocation}', [RespiteResourceAllocationController::class, 'destroy'])->name('respite.resources.destroy');
    });

    // Procedure templates
    Route::middleware('permission:respite.procedures.manage')->group(function () {
        Route::get('/respite/procedures', [RespiteProcedureTemplateController::class, 'index'])->name('respite.procedures.index');
        Route::get('/respite/procedures/create', [RespiteProcedureTemplateController::class, 'create'])->name('respite.procedures.create');
        Route::post('/respite/procedures', [RespiteProcedureTemplateController::class, 'store'])->name('respite.procedures.store');
        Route::get('/respite/procedures/{template}', [RespiteProcedureTemplateController::class, 'show'])->name('respite.procedures.show');
        Route::put('/respite/procedures/{template}', [RespiteProcedureTemplateController::class, 'update'])->name('respite.procedures.update');
    });

    // Procedure Runs
    Route::middleware('permission:respite.procedures.run')->group(function () {
        Route::get('/respite/procedure-runs', [RespiteProcedureRunController::class, 'index'])->name('respite.procedure-runs.index');
        Route::get('/respite/procedure-runs/my-active', [RespiteProcedureRunController::class, 'myActive'])->name('respite.procedure-runs.my-active');
        Route::get('/respite/procedure-runs/overdue', [RespiteProcedureRunController::class, 'overdue'])->name('respite.procedure-runs.overdue');
        Route::get('/respite/procedure-runs/create', [RespiteProcedureRunController::class, 'create'])->name('respite.procedure-runs.create');
        Route::post('/respite/procedure-runs', [RespiteProcedureRunController::class, 'store'])->name('respite.procedure-runs.store');
        Route::get('/respite/procedure-runs/{procedureRun}', [RespiteProcedureRunController::class, 'show'])->name('respite.procedure-runs.show');
        Route::post('/respite/procedure-runs/{procedureRun}/start', [RespiteProcedureRunController::class, 'start'])->name('respite.procedure-runs.start');
        Route::post('/respite/procedure-runs/{procedureRun}/complete', [RespiteProcedureRunController::class, 'complete'])->name('respite.procedure-runs.complete');
        Route::post('/respite/procedure-runs/{procedureRun}/fail', [RespiteProcedureRunController::class, 'fail'])->name('respite.procedure-runs.fail');
        Route::post('/respite/procedure-runs/{procedureRun}/cancel', [RespiteProcedureRunController::class, 'cancel'])->name('respite.procedure-runs.cancel');
        Route::post('/respite/procedure-runs/{procedureRun}/escalate', [RespiteProcedureRunController::class, 'escalate'])->name('respite.procedure-runs.escalate');
    });

    // Tasks
    Route::middleware('permission:respite.tasks.view')->group(function () {
        Route::get('/respite/tasks', [RespiteTaskController::class, 'index'])->name('respite.tasks.index');
        Route::get('/respite/tasks/my-tasks', [RespiteTaskController::class, 'myTasks'])->name('respite.tasks.my-tasks');
        Route::get('/respite/tasks/awaiting-approval', [RespiteTaskController::class, 'awaitingApproval'])->name('respite.tasks.awaiting-approval');
        Route::get('/respite/tasks/overdue', [RespiteTaskController::class, 'overdue'])->name('respite.tasks.overdue');
        Route::get('/respite/tasks/{task}', [RespiteTaskController::class, 'show'])->name('respite.tasks.show');
    });
    Route::middleware('permission:respite.tasks.manage')->group(function () {
        Route::post('/respite/tasks/{task}/assign', [RespiteTaskController::class, 'assign'])->name('respite.tasks.assign');
        Route::post('/respite/tasks/{task}/start', [RespiteTaskController::class, 'start'])->name('respite.tasks.start');
        Route::post('/respite/tasks/{task}/complete', [RespiteTaskController::class, 'complete'])->name('respite.tasks.complete');
        Route::post('/respite/tasks/{task}/submit-for-approval', [RespiteTaskController::class, 'submitForApproval'])->name('respite.tasks.submit-for-approval');
        Route::post('/respite/tasks/{task}/add-evidence', [RespiteTaskController::class, 'addEvidence'])->name('respite.tasks.add-evidence');
        Route::post('/respite/tasks/{task}/update-checklist', [RespiteTaskController::class, 'updateChecklist'])->name('respite.tasks.update-checklist');
    });
    Route::middleware('permission:respite.tasks.approve')->group(function () {
        Route::post('/respite/tasks/{task}/approve', [RespiteTaskController::class, 'approve'])->name('respite.tasks.approve');
        Route::post('/respite/tasks/{task}/reject', [RespiteTaskController::class, 'reject'])->name('respite.tasks.reject');
    });

    // Handover Notes
    Route::middleware('permission:respite.handovers.view')->group(function () {
        Route::get('/respite/handover-notes', [RespiteHandoverNoteController::class, 'index'])->name('respite.handover-notes.index');
        Route::get('/respite/handover-notes/unacknowledged', [RespiteHandoverNoteController::class, 'unacknowledged'])->name('respite.handover-notes.unacknowledged');
        Route::get('/respite/handover-notes/create', [RespiteHandoverNoteController::class, 'create'])->name('respite.handover-notes.create');
        Route::get('/respite/handover-notes/{handoverNote}', [RespiteHandoverNoteController::class, 'show'])->name('respite.handover-notes.show');
        Route::get('/respite/stays/{stay}/handover-notes', [RespiteHandoverNoteController::class, 'forStay'])->name('respite.stays.handover-notes');
    });
    Route::middleware('permission:respite.handovers.manage')->group(function () {
        Route::post('/respite/handover-notes', [RespiteHandoverNoteController::class, 'store'])->name('respite.handover-notes.store');
        Route::put('/respite/handover-notes/{handoverNote}', [RespiteHandoverNoteController::class, 'update'])->name('respite.handover-notes.update');
        Route::post('/respite/handover-notes/{handoverNote}/acknowledge', [RespiteHandoverNoteController::class, 'acknowledge'])->name('respite.handover-notes.acknowledge');
    });

    // Communication Logs
    Route::middleware('permission:respite.communications.view')->group(function () {
        Route::get('/respite/communication-logs', [RespiteCommunicationLogController::class, 'index'])->name('respite.communication-logs.index');
        Route::get('/respite/communication-logs/create', [RespiteCommunicationLogController::class, 'create'])->name('respite.communication-logs.create');
        Route::get('/respite/communication-logs/{communicationLog}', [RespiteCommunicationLogController::class, 'show'])->name('respite.communication-logs.show');
        Route::get('/respite/stays/{stay}/communication-logs', [RespiteCommunicationLogController::class, 'forStay'])->name('respite.stays.communication-logs');
    });
    Route::middleware('permission:respite.communications.manage')->group(function () {
        Route::post('/respite/communication-logs', [RespiteCommunicationLogController::class, 'store'])->name('respite.communication-logs.store');
        Route::put('/respite/communication-logs/{communicationLog}', [RespiteCommunicationLogController::class, 'update'])->name('respite.communication-logs.update');
        Route::post('/respite/communication-logs/{communicationLog}/add-evidence', [RespiteCommunicationLogController::class, 'addEvidence'])->name('respite.communication-logs.add-evidence');
    });

    // Evidence Packs
    Route::middleware('permission:respite.evidence.view')->group(function () {
        Route::get('/respite/evidence-packs', [RespiteEvidencePackController::class, 'index'])->name('respite.evidence-packs.index');
        Route::get('/respite/evidence-packs/create', [RespiteEvidencePackController::class, 'create'])->name('respite.evidence-packs.create');
        Route::get('/respite/evidence-packs/{evidencePack}', [RespiteEvidencePackController::class, 'show'])->name('respite.evidence-packs.show');
        Route::get('/respite/evidence-packs/{evidencePack}/export', [RespiteEvidencePackController::class, 'export'])->name('respite.evidence-packs.export');
        Route::get('/respite/stays/{stay}/evidence-pack', [RespiteEvidencePackController::class, 'forStay'])->name('respite.stays.evidence-pack');
    });
    Route::middleware('permission:respite.evidence.manage')->group(function () {
        Route::post('/respite/evidence-packs', [RespiteEvidencePackController::class, 'store'])->name('respite.evidence-packs.store');
        Route::put('/respite/evidence-packs/{evidencePack}', [RespiteEvidencePackController::class, 'update'])->name('respite.evidence-packs.update');
        Route::post('/respite/evidence-packs/{evidencePack}/add-item', [RespiteEvidencePackController::class, 'addItem'])->name('respite.evidence-packs.add-item');
        Route::post('/respite/evidence-packs/{evidencePack}/remove-item', [RespiteEvidencePackController::class, 'removeItem'])->name('respite.evidence-packs.remove-item');
    });
    Route::middleware('permission:respite.evidence.seal')->group(function () {
        Route::post('/respite/evidence-packs/{evidencePack}/seal', [RespiteEvidencePackController::class, 'seal'])->name('respite.evidence-packs.seal');
    });

    // Daily Notes
    Route::middleware('permission:respite.daily-notes.view')->group(function () {
        Route::get('/respite/daily-notes', [RespiteDailyNoteController::class, 'index'])->name('respite.daily-notes.index');
        Route::get('/respite/daily-notes/with-concerns', [RespiteDailyNoteController::class, 'withConcerns'])->name('respite.daily-notes.with-concerns');
        Route::get('/respite/daily-notes/with-incidents', [RespiteDailyNoteController::class, 'withIncidents'])->name('respite.daily-notes.with-incidents');
        Route::get('/respite/daily-notes/create', [RespiteDailyNoteController::class, 'create'])->name('respite.daily-notes.create');
        Route::get('/respite/daily-notes/{dailyNote}', [RespiteDailyNoteController::class, 'show'])->name('respite.daily-notes.show');
        Route::get('/respite/stays/{stay}/daily-notes', [RespiteDailyNoteController::class, 'forStay'])->name('respite.stays.daily-notes');
    });
    Route::middleware('permission:respite.daily-notes.manage')->group(function () {
        Route::post('/respite/daily-notes', [RespiteDailyNoteController::class, 'store'])->name('respite.daily-notes.store');
        Route::put('/respite/daily-notes/{dailyNote}', [RespiteDailyNoteController::class, 'update'])->name('respite.daily-notes.update');
    });

    // Risk Plan Activations
    Route::middleware('permission:respite.risk-plans.view')->group(function () {
        Route::get('/respite/risk-plan-activations', [RespiteRiskPlanActivationController::class, 'index'])->name('respite.risk-plan-activations.index');
        Route::get('/respite/risk-plan-activations/needing-acknowledgment', [RespiteRiskPlanActivationController::class, 'needingAcknowledgment'])->name('respite.risk-plan-activations.needing-acknowledgment');
        Route::get('/respite/risk-plan-activations/create', [RespiteRiskPlanActivationController::class, 'create'])->name('respite.risk-plan-activations.create');
        Route::get('/respite/risk-plan-activations/{riskPlanActivation}', [RespiteRiskPlanActivationController::class, 'show'])->name('respite.risk-plan-activations.show');
        Route::get('/respite/stays/{stay}/risk-plan-activations', [RespiteRiskPlanActivationController::class, 'forStay'])->name('respite.stays.risk-plan-activations');
        Route::get('/respite/clients/{clientId}/risk-plan-activations', [RespiteRiskPlanActivationController::class, 'forClient'])->name('respite.clients.risk-plan-activations');
    });
    Route::middleware('permission:respite.risk-plans.manage')->group(function () {
        Route::post('/respite/risk-plan-activations', [RespiteRiskPlanActivationController::class, 'store'])->name('respite.risk-plan-activations.store');
        Route::put('/respite/risk-plan-activations/{riskPlanActivation}', [RespiteRiskPlanActivationController::class, 'update'])->name('respite.risk-plan-activations.update');
        Route::post('/respite/risk-plan-activations/{riskPlanActivation}/review', [RespiteRiskPlanActivationController::class, 'review'])->name('respite.risk-plan-activations.review');
        Route::post('/respite/risk-plan-activations/{riskPlanActivation}/activate', [RespiteRiskPlanActivationController::class, 'activate'])->name('respite.risk-plan-activations.activate');
        Route::post('/respite/risk-plan-activations/{riskPlanActivation}/deactivate', [RespiteRiskPlanActivationController::class, 'deactivate'])->name('respite.risk-plan-activations.deactivate');
        Route::post('/respite/risk-plan-activations/{riskPlanActivation}/suspend', [RespiteRiskPlanActivationController::class, 'suspend'])->name('respite.risk-plan-activations.suspend');
        Route::post('/respite/risk-plan-activations/{riskPlanActivation}/acknowledge', [RespiteRiskPlanActivationController::class, 'acknowledge'])->name('respite.risk-plan-activations.acknowledge');
    });
});
