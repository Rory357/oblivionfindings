<?php

use App\Domain\Roadmap\Http\Controllers\BudgetController;
use App\Domain\Roadmap\Http\Controllers\DecisionRequestController;
use App\Domain\Roadmap\Http\Controllers\InitiativeController;
use App\Domain\Roadmap\Http\Controllers\QuarterlyPlanController;
use App\Domain\Roadmap\Http\Controllers\ReportController;
use App\Domain\Roadmap\Http\Controllers\RoadmapDashboardController;
use App\Domain\Roadmap\Http\Controllers\SuggestionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('roadmap')->name('roadmap.')->group(function () {
    Route::get('/dashboard', [RoadmapDashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('permission:roadmap.view|governance.view');

    Route::get('/initiatives', [InitiativeController::class, 'index'])
        ->name('initiatives.index')
        ->middleware('permission:roadmap.view');
    Route::post('/initiatives', [InitiativeController::class, 'store'])
        ->name('initiatives.store')
        ->middleware('permission:roadmap.manage');
    Route::get('/initiatives/{initiative}', [InitiativeController::class, 'show'])
        ->name('initiatives.show')
        ->middleware('permission:roadmap.view');
    Route::put('/initiatives/{initiative}', [InitiativeController::class, 'update'])
        ->name('initiatives.update')
        ->middleware('permission:roadmap.manage');
    Route::post('/initiatives/{initiative}/score', [InitiativeController::class, 'score'])
        ->name('initiatives.score')
        ->middleware('permission:roadmap.manage');
    Route::post('/initiatives/{initiative}/transition', [InitiativeController::class, 'transition'])
        ->name('initiatives.transition')
        ->middleware('permission:roadmap.manage');

    Route::get('/suggestions', [SuggestionController::class, 'index'])
        ->name('suggestions.index')
        ->middleware('permission:roadmap.view');
    Route::post('/suggestions/ingest', [SuggestionController::class, 'ingest'])
        ->name('suggestions.ingest')
        ->middleware('permission:roadmap.manage');
    Route::post('/suggestions/{suggestion}/triage', [SuggestionController::class, 'triage'])
        ->name('suggestions.triage')
        ->middleware('permission:roadmap.manage');
    Route::post('/suggestions/{suggestion}/convert', [SuggestionController::class, 'convert'])
        ->name('suggestions.convert')
        ->middleware('permission:roadmap.manage');

    Route::get('/quarterly-plans', [QuarterlyPlanController::class, 'index'])
        ->name('plans.index')
        ->middleware('permission:roadmap.view');
    Route::post('/quarterly-plans/generate', [QuarterlyPlanController::class, 'generate'])
        ->name('plans.generate')
        ->middleware('permission:roadmap.manage');
    Route::get('/quarterly-plans/{plan}', [QuarterlyPlanController::class, 'show'])
        ->name('plans.show')
        ->middleware('permission:roadmap.view');
    Route::post('/quarterly-plans/{plan}/approve', [QuarterlyPlanController::class, 'approve'])
        ->name('plans.approve')
        ->middleware('permission:roadmap.approve');
    Route::post('/quarterly-plans/{plan}/publish', [QuarterlyPlanController::class, 'publish'])
        ->name('plans.publish')
        ->middleware('permission:roadmap.approve');
    Route::post('/quarterly-plans/{plan}/revise', [QuarterlyPlanController::class, 'revise'])
        ->name('plans.revise')
        ->middleware('permission:roadmap.approve');

    Route::post('/budget/replan', [BudgetController::class, 'replan'])
        ->name('budget.replan')
        ->middleware('permission:roadmap.budget.manage');
    Route::get('/budget/governance-envelope', [BudgetController::class, 'governanceBudget'])
        ->name('budget.governance-envelope')
        ->middleware('permission:roadmap.budget.manage|governance.budgets.view');

    Route::get('/decisions', [DecisionRequestController::class, 'index'])
        ->name('decisions.index')
        ->middleware('permission:roadmap.decisions.view|governance.resolutions.view');
    Route::post('/decisions/{decisionRequest}/resolve', [DecisionRequestController::class, 'resolve'])
        ->name('decisions.resolve')
        ->middleware('permission:roadmap.decisions.manage|governance.resolutions.manage');

    Route::post('/reports/{type}', [ReportController::class, 'generate'])
        ->name('reports.generate')
        ->middleware('permission:roadmap.reports.export');
    Route::get('/reports/snapshots/{snapshot}', [ReportController::class, 'show'])
        ->name('reports.show')
        ->middleware('permission:roadmap.view|roadmap.reports.export');
});
