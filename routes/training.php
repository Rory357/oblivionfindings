<?php

use App\Http\Controllers\LegacyRouteRedirectController;
use App\Http\Controllers\Staff\StaffBackgroundCheckController;
use App\Http\Controllers\Training\CompetencyFrameworkController;
use App\Http\Controllers\Training\StaffInductionController;
use Illuminate\Support\Facades\Route;

/**
 * Staff Vetting & Training Routes
 *
 * Handles background-check redirects, retired training-course URL redirects,
 * competency frameworks, and staff induction tracking.
 */
Route::middleware(['auth'])->group(function () {
    // Background Checks (DBS, Police, References)
    Route::middleware('permission:hr.vetting.view')->group(function () {
        Route::get('/staff/background-checks', [StaffBackgroundCheckController::class, 'index'])
            ->name('staff.background-checks.index');
        Route::get('/staff/{user}/background-checks', [StaffBackgroundCheckController::class, 'userChecks'])
            ->name('staff.background-checks.user');
        Route::get('/staff/background-checks/{check}', [StaffBackgroundCheckController::class, 'show'])
            ->name('staff.background-checks.show');
    });

    Route::middleware('permission:hr.vetting.manage')->group(function () {
        Route::get('/staff/{user}/background-checks/create', [StaffBackgroundCheckController::class, 'create'])
            ->name('staff.background-checks.create');
        Route::post('/staff/{user}/background-checks', [StaffBackgroundCheckController::class, 'store'])
            ->name('staff.background-checks.store');
        Route::get('/staff/background-checks/{check}/edit', [StaffBackgroundCheckController::class, 'edit'])
            ->name('staff.background-checks.edit');
        Route::put('/staff/background-checks/{check}', [StaffBackgroundCheckController::class, 'update'])
            ->name('staff.background-checks.update');
    });

    Route::middleware('permission:hr.vetting.manage')->group(function () {
        Route::post('/staff/background-checks/{check}/verify', [StaffBackgroundCheckController::class, 'verify'])
            ->name('staff.background-checks.verify');
    });

    Route::middleware('permission:hr.vetting.manage')->group(function () {
        Route::post('/staff/background-checks/{check}/assess-risk', [StaffBackgroundCheckController::class, 'assessRisk'])
            ->name('staff.background-checks.assess-risk');
    });

    // Retired training-course URLs. The HR catalog is canonical.
    Route::get('/training/courses', LegacyRouteRedirectController::class)
        ->defaults('canonical', 'hr.training.catalog');
    Route::redirect('/training/courses/create', '/hr/training/catalog?open=create', 301);
    Route::post('/training/courses', LegacyRouteRedirectController::class)
        ->defaults('canonical', 'hr.training.courses.store')
        ->defaults('status', 308);
    Route::get('/training/courses/{course}/edit', LegacyRouteRedirectController::class)
        ->defaults('canonical', 'hr.training.courses.show');
    Route::match(['PUT', 'DELETE'], '/training/courses/{course}', LegacyRouteRedirectController::class)
        ->defaults('canonical', 'hr.training.courses.show')
        ->defaults('status', 303);
    Route::get('/training/courses/{course}', LegacyRouteRedirectController::class)
        ->defaults('canonical', 'hr.training.courses.show');

    Route::middleware('permission:training.viewAny')->group(function () {
        Route::get('/training/matrix', fn () => redirect()->route('hr.training.index'))
            ->name('training.matrix');
    });

    // Competency Framework
    Route::middleware('permission:competency.viewAny')->group(function () {
        Route::get('/competency/frameworks', [CompetencyFrameworkController::class, 'index'])
            ->name('competency.frameworks.index');
        Route::get('/competency/frameworks/{framework}', [CompetencyFrameworkController::class, 'show'])
            ->name('competency.frameworks.show');
    });

    Route::middleware('permission:competency.manage')->group(function () {
        Route::get('/competency/frameworks/create', [CompetencyFrameworkController::class, 'create'])
            ->name('competency.frameworks.create');
        Route::post('/competency/frameworks', [CompetencyFrameworkController::class, 'store'])
            ->name('competency.frameworks.store');
        Route::get('/competency/frameworks/{framework}/edit', [CompetencyFrameworkController::class, 'edit'])
            ->name('competency.frameworks.edit');
        Route::put('/competency/frameworks/{framework}', [CompetencyFrameworkController::class, 'update'])
            ->name('competency.frameworks.update');
    });

    // Staff Induction
    Route::middleware('permission:training.viewAny')->group(function () {
        Route::get('/staff/{user}/induction', [StaffInductionController::class, 'show'])
            ->name('staff.induction.show');
    });

    Route::middleware('permission:training.record')->group(function () {
        Route::post('/staff/{user}/induction', [StaffInductionController::class, 'create'])
            ->name('staff.induction.create');
        Route::put('/staff/induction/{induction}', [StaffInductionController::class, 'update'])
            ->name('staff.induction.update');
        Route::post('/staff/induction/{induction}/complete', [StaffInductionController::class, 'complete'])
            ->name('staff.induction.complete');
    });
});
