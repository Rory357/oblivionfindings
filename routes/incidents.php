<?php

use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentRecordingCommandController;
use App\Http\Controllers\IncidentFollowupController;
use App\Http\Controllers\IncidentOwnershipController;
use App\Http\Controllers\IncidentReportController;
use App\Http\Controllers\IncidentReportDraftController;
use App\Http\Controllers\IncidentTemplateController;
use App\Http\Controllers\ShiftIncidentController;
use Illuminate\Support\Facades\Route;

/**
 * Incident Management Routes
 *
 * Handles incident reporting, follow-ups, templates, and reporting.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    // Incident creation (must come before /incidents/{incident})
    Route::middleware('permission:incidents.create')->group(function () {
        Route::get('/incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
        // Standalone report page for reporters who can't open the register.
        Route::get('/incidents/report', [IncidentController::class, 'report'])->name('incidents.report');
        Route::get('/incidents/drafts/{requestUuid}', [IncidentReportDraftController::class, 'show'])
            ->whereUuid('requestUuid')
            ->name('incidents.drafts.show');
        Route::put('/incidents/drafts/{requestUuid}', [IncidentReportDraftController::class, 'update'])
            ->whereUuid('requestUuid')
            ->name('incidents.drafts.update');
        Route::delete('/incidents/drafts/{requestUuid}', [IncidentReportDraftController::class, 'destroy'])
            ->whereUuid('requestUuid')
            ->name('incidents.drafts.destroy');
        Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
    });

    // Incident templates (admin/manager). MUST be registered before any
    // /incidents/{incident} routes, otherwise Laravel matches the catch-all
    // first and treats "templates" as an incident ID → model bind fails → 404.
    Route::middleware('permission:incidents.templates.manage')->group(function () {
        Route::get('/incidents/templates', [IncidentTemplateController::class, 'index'])
            ->name('incidents.templates.index');
        Route::get('/incidents/templates/create', [IncidentTemplateController::class, 'create'])
            ->name('incidents.templates.create');
        Route::post('/incidents/templates', [IncidentTemplateController::class, 'store'])
            ->name('incidents.templates.store');
        Route::get('/incidents/templates/{template}', [IncidentTemplateController::class, 'edit'])
            ->whereNumber('template')
            ->name('incidents.templates.edit');
        Route::put('/incidents/templates/{template}', [IncidentTemplateController::class, 'update'])
            ->whereNumber('template')
            ->name('incidents.templates.update');
    });

    // Incident management (assigned staff + managers).
    // {incident} is constrained to numeric IDs so sibling segments like
    // /incidents/templates or /incidents/create can't be swallowed here.
    Route::get('/incidents', [IncidentController::class, 'index'])
        ->middleware(\App\Http\Middleware\CurrentIncidentRegisterAccess::class)->name('incidents.index');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])
        ->middleware(\App\Http\Middleware\CurrentIncidentRegisterAccess::class)
        ->whereNumber('incident')->name('incidents.show');
    Route::get('/incidents/{incident}/attachments/{attachment}/download', [IncidentController::class, 'downloadAttachment'])
        ->middleware(\App\Http\Middleware\CurrentIncidentRegisterAccess::class)
        ->whereNumber(['incident', 'attachment'])->name('incidents.attachments.download');
    Route::middleware('permission:incidents.viewAny|incidents.viewAssigned')->group(function () {
        // Linked Control Room evidence retains its existing source policy pending its owning current-read seam.
        Route::get('/incidents/{incident}/control-room-evidence/{item}/download', [IncidentController::class, 'downloadControlRoomEvidence'])
            ->whereNumber(['incident', 'item'])
            ->name('incidents.control-room-evidence.download');
    });

    Route::middleware('permission:incidents.owner.assign')->group(function () {
        Route::get('/incidents/{incident}/owners/options', [IncidentOwnershipController::class, 'candidates'])
            ->whereNumber('incident')->name('incidents.owners.options');
        Route::post('/incidents/{incident}/owner', [IncidentOwnershipController::class, 'store'])
            ->whereNumber('incident')->name('incidents.owner.store');
        Route::post('/incidents/{incident}/owner-deliveries/{delivery}/retry', [IncidentOwnershipController::class, 'retry'])
            ->whereNumber('incident')->whereUuid('delivery')->name('incidents.owner-deliveries.retry');
    });

    // NEW recording reconstruction commands; operation/source authority is checked by the current-read service.
    Route::post('/incidents/{incident}/recording-attachments', [IncidentRecordingCommandController::class, 'attach'])
        ->whereNumber('incident')->name('incidents.recording-attachments.store');
    Route::post('/incidents/{incident}/evidence-windows', [IncidentRecordingCommandController::class, 'recordWindow'])
        ->whereNumber('incident')->name('incidents.evidence-windows.store');
    Route::get('/incidents/{incident}/recording-attachment-commands/{idempotencyKey}', [IncidentRecordingCommandController::class, 'recoverAttachment'])
        ->whereNumber('incident')->whereUuid('idempotencyKey')->name('incidents.recording-attachment-commands.recover');
    Route::get('/incidents/{incident}/evidence-window-commands/{idempotencyKey}', [IncidentRecordingCommandController::class, 'recoverWindow'])
        ->whereNumber('incident')->whereUuid('idempotencyKey')->name('incidents.evidence-window-commands.recover');
    // Incident updates
    Route::put('/incidents/{incident}', [IncidentController::class, 'update'])
        ->whereNumber('incident')
        ->middleware('permission:incidents.update')
        ->name('incidents.update');

    // Attachments (uploads/deletes)
    Route::post('/incidents/{incident}/attachments', [IncidentController::class, 'uploadAttachment'])
        ->whereNumber('incident')
        ->middleware('permission:incidents.update')
        ->name('incidents.attachments.store');
    Route::delete('/incidents/{incident}/attachments/{attachment}', [IncidentController::class, 'removeAttachment'])
        ->whereNumber(['incident', 'attachment'])
        ->middleware('permission:incidents.update')
        ->name('incidents.attachments.destroy');
    Route::patch('/incidents/{incident}/attachments/{attachment}', [IncidentController::class, 'updateAttachment'])
        ->whereNumber(['incident', 'attachment'])
        ->middleware('permission:incidents.portal.manage')
        ->name('incidents.attachments.update');

    // Workflow actions
    Route::post('/incidents/{incident}/submit', [IncidentController::class, 'submit'])
        ->whereNumber('incident')
        ->middleware('permission:incidents.submit')
        ->name('incidents.submit');
    Route::post('/incidents/{incident}/review', [IncidentController::class, 'review'])
        ->whereNumber('incident')
        ->middleware('permission:incidents.approve')
        ->name('incidents.review');
    Route::post('/incidents/{incident}/close', [IncidentController::class, 'close'])
        ->whereNumber('incident')
        ->middleware('permission:incidents.approve')
        ->name('incidents.close');
    Route::post('/incidents/{incident}/reopen', [IncidentController::class, 'reopen'])
        ->whereNumber('incident')
        ->middleware('permission:incidents.reopen')
        ->name('incidents.reopen');

    // Follow-ups
    Route::post('/incidents/{incident}/followups', [IncidentFollowupController::class, 'store'])
        ->whereNumber('incident')
        ->middleware('permission:incidents.followups.manage')
        ->name('incidents.followups.store');
    Route::put('/incidents/{incident}/followups/{followup}', [IncidentFollowupController::class, 'update'])
        ->whereNumber(['incident', 'followup'])
        ->middleware('permission:incidents.followups.manage')
        ->name('incidents.followups.update');
    Route::post('/incidents/{incident}/followups/{followup}/complete', [IncidentFollowupController::class, 'complete'])
        ->whereNumber(['incident', 'followup'])
        ->middleware('permission:incidents.followups.complete|incidents.followups.manage')
        ->name('incidents.followups.complete');

    // Corrective actions — Option B: raised from the incident, governed in H&S.
    Route::post('/incidents/{incident}/corrective-actions', [IncidentController::class, 'raiseCorrectiveAction'])
        ->whereNumber('incident')
        ->middleware('permission:incidents.viewAny|compliance.view|hazards.view')
        ->name('incidents.corrective-actions.store');

    // Shift-linked incident creation
    Route::post('/shifts/{shift}/incidents', [ShiftIncidentController::class, 'store'])
        ->whereNumber('shift')
        ->middleware('permission:incidents.create')
        ->name('shifts.incidents.store');

    // Reporting
    Route::middleware('permission:reports.viewAny|incidents.export')->group(function () {
        Route::get('/reports/incidents', [IncidentReportController::class, 'index'])
            ->name('reports.incidents.index');
        Route::get('/reports/incidents/export', [IncidentReportController::class, 'exportCsv'])
            ->middleware('permission:incidents.export')
            ->name('reports.incidents.export');
    });
});
