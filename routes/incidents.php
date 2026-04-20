<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentFollowupController;
use App\Http\Controllers\ShiftIncidentController;
use App\Http\Controllers\IncidentTemplateController;
use App\Http\Controllers\IncidentReportController;

/**
 * Incident Management Routes
 *
 * Handles incident reporting, follow-ups, templates, and reporting.
 */

Route::middleware(['auth', 'verified'])->group(function () {
    // Incident creation (must come before /incidents/{incident})
    Route::middleware('permission:incidents.create')->group(function () {
        Route::get('/incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
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
    Route::middleware('permission:incidents.viewAny|incidents.viewAssigned')->group(function () {
        Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::get('/incidents/{incident}', [IncidentController::class, 'show'])
            ->whereNumber('incident')
            ->name('incidents.show');

        // Attachments
        Route::get('/incidents/{incident}/attachments/{attachment}/download', [IncidentController::class, 'downloadAttachment'])
            ->whereNumber(['incident', 'attachment'])
            ->name('incidents.attachments.download');
    });

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
