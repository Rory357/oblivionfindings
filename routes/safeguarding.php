<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SafeguardingConcernController;

/**
 * Safeguarding & Allegations Management Routes
 *
 * Handles safeguarding concerns, investigations, external reports,
 * risk assessments, and action plans for protecting vulnerable adults.
 */

Route::middleware(['auth'])->group(function () {
    // Safeguarding concerns
    Route::middleware('permission:safeguarding.viewAny')->group(function () {
        Route::get('/safeguarding', [SafeguardingConcernController::class, 'index'])
            ->name('safeguarding.index');
        Route::get('/safeguarding/{concern}', [SafeguardingConcernController::class, 'show'])
            ->name('safeguarding.show');
    });

    Route::middleware('permission:safeguarding.create')->group(function () {
        Route::get('/safeguarding/create', [SafeguardingConcernController::class, 'create'])
            ->name('safeguarding.create');
        Route::post('/safeguarding', [SafeguardingConcernController::class, 'store'])
            ->name('safeguarding.store');
    });

    Route::middleware('permission:safeguarding.update')->group(function () {
        Route::get('/safeguarding/{concern}/edit', [SafeguardingConcernController::class, 'edit'])
            ->name('safeguarding.edit');
        Route::put('/safeguarding/{concern}', [SafeguardingConcernController::class, 'update'])
            ->name('safeguarding.update');

        // Workflow actions
        Route::post('/safeguarding/{concern}/assign', [SafeguardingConcernController::class, 'assign'])
            ->name('safeguarding.assign');
        Route::patch('/safeguarding/{concern}/status', [SafeguardingConcernController::class, 'updateStatus'])
            ->name('safeguarding.updateStatus');
        Route::post('/safeguarding/{concern}/close', [SafeguardingConcernController::class, 'close'])
            ->name('safeguarding.close');
        Route::post('/safeguarding/{concern}/subject-informed', [SafeguardingConcernController::class, 'markSubjectInformed'])
            ->name('safeguarding.markSubjectInformed');
    });

    // Investigations (require investigate permission)
    // Route::middleware('permission:safeguarding.investigate')->group(function () {
    //     Route::get('/safeguarding/{concern}/investigations', [SafeguardingInvestigationController::class, 'index'])
    //         ->name('safeguarding.investigations.index');
    //     Route::post('/safeguarding/{concern}/investigations', [SafeguardingInvestigationController::class, 'store'])
    //         ->name('safeguarding.investigations.store');
    //     Route::put('/safeguarding/{concern}/investigations/{investigation}', [SafeguardingInvestigationController::class, 'update'])
    //         ->name('safeguarding.investigations.update');
    // });

    // External reports (require external reporting permission)
    // Route::middleware('permission:safeguarding.report.external')->group(function () {
    //     Route::post('/safeguarding/{concern}/external-reports', [SafeguardingExternalReportController::class, 'store'])
    //         ->name('safeguarding.externalReports.store');
    //     Route::put('/safeguarding/{concern}/external-reports/{report}', [SafeguardingExternalReportController::class, 'update'])
    //         ->name('safeguarding.externalReports.update');
    // });

    // Risk assessments
    // Route::middleware('permission:safeguarding.update')->group(function () {
    //     Route::post('/safeguarding/{concern}/risk-assessments', [SafeguardingRiskAssessmentController::class, 'store'])
    //         ->name('safeguarding.riskAssessments.store');
    // });

    // Action plans
    // Route::middleware('permission:safeguarding.update')->group(function () {
    //     Route::post('/safeguarding/{concern}/action-plans', [SafeguardingActionPlanController::class, 'store'])
    //         ->name('safeguarding.actionPlans.store');
    //     Route::put('/safeguarding/{concern}/action-plans/{actionPlan}', [SafeguardingActionPlanController::class, 'update'])
    //         ->name('safeguarding.actionPlans.update');
    //     Route::post('/safeguarding/{concern}/action-plans/{actionPlan}/complete', [SafeguardingActionPlanController::class, 'complete'])
    //         ->name('safeguarding.actionPlans.complete');
    // });
});
