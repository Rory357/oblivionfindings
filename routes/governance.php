<?php

use App\Domain\Governance\Http\Controllers\DashboardController;
use App\Domain\Governance\Http\Controllers\GovernanceMeetingController;
use App\Domain\Governance\Http\Controllers\ResolutionController;
use App\Domain\Governance\Http\Controllers\RiskRegisterController;
use App\Domain\Governance\Http\Controllers\ComplianceController;
use App\Domain\Governance\Http\Controllers\PerformanceReviewController;
use App\Domain\Governance\Http\Controllers\BoardPackController;
use App\Domain\Governance\Http\Controllers\StrategicPlanController;
use App\Domain\Governance\Http\Controllers\BudgetController;
use App\Domain\Governance\Http\Controllers\BoardMemberAdminController;
use Illuminate\Support\Facades\Route;

/**
 * Board & Governance Routes
 */

Route::middleware(['auth'])->prefix('governance')->name('governance.')->group(function () {
    
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('permission:governance.view');
    
    Route::get('/dashboard/data', [DashboardController::class, 'data'])
        ->name('dashboard.data')
        ->middleware('permission:governance.view');
    
    // Meetings
    Route::middleware('permission:governance.meetings.view')->group(function () {
        Route::get('/meetings', [GovernanceMeetingController::class, 'index'])->name('meetings.index');
        Route::get('/meetings/create', [GovernanceMeetingController::class, 'create'])->name('meetings.create');
        Route::get('/meetings/{meeting}', [GovernanceMeetingController::class, 'show'])->name('meetings.show');
        
        Route::middleware('permission:governance.meetings.manage')->group(function () {
            Route::get('/meetings/{meeting}/edit', [GovernanceMeetingController::class, 'edit'])->name('meetings.edit');
            Route::post('/meetings', [GovernanceMeetingController::class, 'store'])->name('meetings.store');
            Route::put('/meetings/{meeting}', [GovernanceMeetingController::class, 'update'])->name('meetings.update');
            Route::delete('/meetings/{meeting}', [GovernanceMeetingController::class, 'destroy'])->name('meetings.destroy');
            
            // Agenda items
            Route::post('/meetings/{meeting}/agenda', [GovernanceMeetingController::class, 'addAgendaItem'])->name('meetings.agenda.add');
            Route::put('/meetings/{meeting}/agenda/{item}', [GovernanceMeetingController::class, 'updateAgendaItem'])->name('meetings.agenda.update');
            Route::delete('/meetings/{meeting}/agenda/{item}', [GovernanceMeetingController::class, 'removeAgendaItem'])->name('meetings.agenda.remove');
            
            // Minutes
            Route::post('/meetings/{meeting}/minutes', [GovernanceMeetingController::class, 'storeMinutes'])->name('meetings.minutes.store');
            Route::put('/meetings/{meeting}/minutes', [GovernanceMeetingController::class, 'updateMinutes'])->name('meetings.minutes.update');
            Route::post('/meetings/{meeting}/minutes/approve', [GovernanceMeetingController::class, 'approveMinutes'])->name('meetings.minutes.approve');
            
            // Attendance
            Route::post('/meetings/{meeting}/attendance', [GovernanceMeetingController::class, 'recordAttendance'])->name('meetings.attendance.record');
        });
    });
    
    // Board Packs
    Route::middleware('permission:governance.packs.view')->group(function () {
        Route::get('/packs/{pack}', [BoardPackController::class, 'show'])->name('packs.show');
        Route::get('/packs/{pack}/download', [BoardPackController::class, 'download'])->name('packs.download');
        
        Route::middleware('permission:governance.packs.manage')->group(function () {
            Route::post('/meetings/{meeting}/packs', [BoardPackController::class, 'generate'])->name('packs.generate');
            Route::post('/packs/{pack}/distribute', [BoardPackController::class, 'distribute'])->name('packs.distribute');
        });
    });
    
    // Resolutions & Voting
    Route::middleware('permission:governance.resolutions.view')->group(function () {
        Route::get('/resolutions', [ResolutionController::class, 'index'])->name('resolutions.index');
        Route::get('/resolutions/create', [ResolutionController::class, 'create'])->name('resolutions.create');
        Route::get('/resolutions/{resolution}', [ResolutionController::class, 'show'])->name('resolutions.show');
        
        Route::middleware('permission:governance.resolutions.vote')->group(function () {
            Route::post('/resolutions/{resolution}/vote', [ResolutionController::class, 'vote'])->name('resolutions.vote');
            Route::post('/resolutions/{resolution}/conflict', [ResolutionController::class, 'declareConflict'])->name('resolutions.conflict.declare');
        });
        
        Route::middleware('permission:governance.resolutions.manage')->group(function () {
            Route::post('/resolutions', [ResolutionController::class, 'store'])->name('resolutions.store');
            Route::put('/resolutions/{resolution}', [ResolutionController::class, 'update'])->name('resolutions.update');
            Route::post('/resolutions/{resolution}/open', [ResolutionController::class, 'openVoting'])->name('resolutions.open');
            Route::post('/resolutions/{resolution}/close', [ResolutionController::class, 'closeVoting'])->name('resolutions.close');
            Route::post('/resolutions/{resolution}/finalize', [ResolutionController::class, 'finalize'])->name('resolutions.finalize');
        });
    });

    // Governance Admin
    Route::middleware('permission:governance.meetings.manage')->group(function () {
        Route::get('/admin/board-members', [BoardMemberAdminController::class, 'index'])->name('admin.board-members.index');
        Route::post('/admin/board-members', [BoardMemberAdminController::class, 'store'])->name('admin.board-members.store');
        Route::put('/admin/board-members/{boardMember}', [BoardMemberAdminController::class, 'update'])->name('admin.board-members.update');
        Route::delete('/admin/board-members/{boardMember}', [BoardMemberAdminController::class, 'destroy'])->name('admin.board-members.destroy');
    });
    
    // Risk Register
    Route::middleware('permission:governance.risks.view')->group(function () {
        Route::get('/risks', [RiskRegisterController::class, 'index'])->name('risks.index');
        Route::get('/risks/create', [RiskRegisterController::class, 'create'])->name('risks.create');
        Route::get('/risks/heatmap', [RiskRegisterController::class, 'heatmap'])->name('risks.heatmap');
        Route::get('/risks/{risk}', [RiskRegisterController::class, 'show'])->name('risks.show');
        
        Route::middleware('permission:governance.risks.manage')->group(function () {
            Route::post('/risks', [RiskRegisterController::class, 'store'])->name('risks.store');
            Route::put('/risks/{risk}', [RiskRegisterController::class, 'update'])->name('risks.update');
            Route::post('/risks/{risk}/accept', [RiskRegisterController::class, 'accept'])->name('risks.accept');
            Route::post('/risks/{risk}/close', [RiskRegisterController::class, 'close'])->name('risks.close');
            Route::post('/risks/{risk}/treatments', [RiskRegisterController::class, 'addTreatment'])->name('risks.treatments.add');
            Route::post('/risks/{risk}/link-event', [RiskRegisterController::class, 'linkEvent'])->name('risks.events.link');
        });
    });
    
    // Compliance
    Route::middleware('permission:governance.compliance.view')->group(function () {
        Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');
        Route::get('/compliance/create', [ComplianceController::class, 'create'])->name('compliance.create');
        Route::get('/compliance/calendar', [ComplianceController::class, 'calendar'])->name('compliance.calendar');
        Route::get('/compliance/{obligation}', [ComplianceController::class, 'show'])->name('compliance.show');
        
        Route::middleware('permission:governance.compliance.manage')->group(function () {
            Route::post('/compliance', [ComplianceController::class, 'store'])->name('compliance.store');
            Route::put('/compliance/{obligation}', [ComplianceController::class, 'update'])->name('compliance.update');
            Route::post('/compliance/{obligation}/complete', [ComplianceController::class, 'complete'])->name('compliance.complete');
            Route::post('/compliance/{obligation}/evidence', [ComplianceController::class, 'uploadEvidence'])->name('compliance.evidence.upload');
        });
    });
    
    // Performance Reviews
    Route::middleware('permission:governance.performance.view')->group(function () {
        Route::get('/performance', [PerformanceReviewController::class, 'index'])->name('performance.index');
        Route::get('/performance/create', [PerformanceReviewController::class, 'create'])->name('performance.create');
        Route::get('/performance/{review}', [PerformanceReviewController::class, 'show'])->name('performance.show');
        
        Route::middleware('permission:governance.performance.manage')->group(function () {
            Route::post('/performance', [PerformanceReviewController::class, 'store'])->name('performance.store');
            Route::put('/performance/{review}', [PerformanceReviewController::class, 'update'])->name('performance.update');
            Route::post('/performance/{review}/goals', [PerformanceReviewController::class, 'addGoal'])->name('performance.goals.add');
            Route::post('/performance/{review}/assess', [PerformanceReviewController::class, 'submitAssessment'])->name('performance.assess');
        });
    });
    
    // Strategic Plans
    Route::middleware('permission:governance.strategy.view')->group(function () {
        Route::get('/strategy', [StrategicPlanController::class, 'index'])->name('strategy.index');
        Route::get('/strategy/create', [StrategicPlanController::class, 'create'])->name('strategy.create');
        Route::get('/strategy/{plan}', [StrategicPlanController::class, 'show'])->name('strategy.show');
        
        Route::middleware('permission:governance.strategy.manage')->group(function () {
            Route::post('/strategy', [StrategicPlanController::class, 'store'])->name('strategy.store');
            Route::put('/strategy/{plan}', [StrategicPlanController::class, 'update'])->name('strategy.update');
            Route::post('/strategy/{plan}/goals', [StrategicPlanController::class, 'addGoal'])->name('strategy.goals.add');
            Route::post('/strategy/{plan}/approve', [StrategicPlanController::class, 'approve'])->name('strategy.approve');
        });
    });
    
    // Budgets
    Route::middleware('permission:governance.budgets.view')->group(function () {
        Route::get('/budgets', [BudgetController::class, 'index'])->name('budgets.index');

        Route::middleware('permission:governance.budgets.manage')->group(function () {
            Route::get('/budgets/create', [BudgetController::class, 'create'])->name('budgets.create');
            Route::post('/budgets', [BudgetController::class, 'store'])->name('budgets.store');
            Route::put('/budgets/{budget}', [BudgetController::class, 'update'])->name('budgets.update');
            Route::post('/budgets/{budget}/propose', [BudgetController::class, 'propose'])->name('budgets.propose');
            Route::post('/budgets/{budget}/adjust', [BudgetController::class, 'requestAdjustment'])->name('budgets.adjust');
        });

        Route::get('/budgets/{budget}', [BudgetController::class, 'show'])->name('budgets.show');
    });
    
    // Action Items
    Route::middleware('permission:governance.actions.view')->group(function () {
        Route::get('/actions', [\App\Domain\Governance\Http\Controllers\ActionItemController::class, 'index'])->name('actions.index');
        Route::get('/actions/{action}', [\App\Domain\Governance\Http\Controllers\ActionItemController::class, 'show'])->name('actions.show');
        Route::post('/actions/{action}/complete', [\App\Domain\Governance\Http\Controllers\ActionItemController::class, 'complete'])->name('actions.complete');
    });
});
