<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SafeguardingConcernController;
use App\Http\Controllers\SafeguardingInvestigationController;
use App\Http\Controllers\SafeguardingExternalReportController;
use App\Http\Controllers\SafeguardingRiskAssessmentController;
use App\Http\Controllers\SafeguardingActionPlanController;
use App\Http\Controllers\SafeguardingAttachmentController;

/**
 * Safeguarding & Allegations Management Routes
 *
 * Handles safeguarding concerns, investigations, external reports,
 * risk assessments, and action plans for protecting vulnerable adults.
 */

Route::middleware(['auth'])->group(function () {
    // Safeguarding concerns - Create routes must come before wildcard routes
    Route::middleware('permission:safeguarding.create')->group(function () {
        Route::get('/safeguarding/create', [SafeguardingConcernController::class, 'create'])
            ->name('safeguarding.create');
        Route::post('/safeguarding', [SafeguardingConcernController::class, 'store'])
            ->name('safeguarding.store');
    });

    Route::middleware('permission:safeguarding.viewAny')->group(function () {
        Route::get('/safeguarding', [SafeguardingConcernController::class, 'index'])
            ->name('safeguarding.index');
    });

    // Show is policy-protected in the controller and can be accessed by
    // reporters/assignees even without global viewAny permission.
    Route::get('/safeguarding/{concern}', [SafeguardingConcernController::class, 'show'])
        ->name('safeguarding.show');

    // Concern-specific management is policy-protected in the controllers so
    // assignees can work their allocated concerns without needing the global
    // safeguarding.update permission.
    Route::get('/safeguarding/{concern}/edit', [SafeguardingConcernController::class, 'edit'])
        ->name('safeguarding.edit');
    Route::put('/safeguarding/{concern}', [SafeguardingConcernController::class, 'update'])
        ->name('safeguarding.update');

    // Workflow actions
    Route::post('/safeguarding/{concern}/assign', [SafeguardingConcernController::class, 'assign'])
        ->name('safeguarding.assign');
    Route::post('/safeguarding/{concern}/triage', [SafeguardingConcernController::class, 'triage'])
        ->name('safeguarding.triage');
    Route::patch('/safeguarding/{concern}/status', [SafeguardingConcernController::class, 'updateStatus'])
        ->name('safeguarding.updateStatus');
    Route::post('/safeguarding/{concern}/close', [SafeguardingConcernController::class, 'close'])
        ->name('safeguarding.close');
    Route::post('/safeguarding/{concern}/subject-informed', [SafeguardingConcernController::class, 'markSubjectInformed'])
        ->name('safeguarding.markSubjectInformed');
    Route::post('/safeguarding/{concern}/sensitivity', [SafeguardingConcernController::class, 'setSensitivity'])
        ->name('safeguarding.setSensitivity');

    // Investigations (require investigate permission)
    Route::middleware('permission:safeguarding.investigate')->group(function () {
        Route::post('/safeguarding/{concern}/investigations', [SafeguardingInvestigationController::class, 'store'])
            ->name('safeguarding.investigations.store');
        Route::put('/safeguarding/{concern}/investigations/{investigation}', [SafeguardingInvestigationController::class, 'update'])
            ->name('safeguarding.investigations.update');
    });

    // External reports (require external reporting permission)
    Route::middleware('permission:safeguarding.report.external')->group(function () {
        Route::post('/safeguarding/{concern}/external-reports', [SafeguardingExternalReportController::class, 'store'])
            ->name('safeguarding.externalReports.store');
        Route::put('/safeguarding/{concern}/external-reports/{report}', [SafeguardingExternalReportController::class, 'update'])
            ->name('safeguarding.externalReports.update');
    });

    // Risk assessments
    Route::post('/safeguarding/{concern}/risk-assessments', [SafeguardingRiskAssessmentController::class, 'store'])
        ->name('safeguarding.riskAssessments.store');

    // Evidence attachments (policy-protected in the controller; sensitive downloads gated by viewSensitive)
    Route::post('/safeguarding/{concern}/attachments', [SafeguardingAttachmentController::class, 'store'])
        ->name('safeguarding.attachments.store');
    Route::get('/safeguarding/{concern}/attachments/{attachment}/download', [SafeguardingAttachmentController::class, 'download'])
        ->name('safeguarding.attachments.download');
    Route::delete('/safeguarding/{concern}/attachments/{attachment}', [SafeguardingAttachmentController::class, 'destroy'])
        ->name('safeguarding.attachments.destroy');

    // Action plans
    Route::post('/safeguarding/{concern}/action-plans', [SafeguardingActionPlanController::class, 'store'])
        ->name('safeguarding.actionPlans.store');
    Route::put('/safeguarding/{concern}/action-plans/{actionPlan}', [SafeguardingActionPlanController::class, 'update'])
        ->name('safeguarding.actionPlans.update');
    Route::post('/safeguarding/{concern}/action-plans/{actionPlan}/complete', [SafeguardingActionPlanController::class, 'complete'])
        ->name('safeguarding.actionPlans.complete');
});
