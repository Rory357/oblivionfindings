<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Staff\StaffBackgroundCheckController;
use App\Http\Controllers\Training\TrainingCourseController;
use App\Http\Controllers\Training\StaffTrainingRecordController;
use App\Http\Controllers\Training\CompetencyFrameworkController;
use App\Http\Controllers\Training\StaffCompetencyController;
use App\Http\Controllers\Training\StaffInductionController;

/**
 * Staff Vetting & Training Routes
 *
 * Handles background checks, training courses, competency assessments,
 * and staff induction tracking.
 */

Route::middleware(['auth'])->group(function () {
    // Background Checks (DBS, Police, References)
    Route::middleware('permission:vetting.viewAny')->group(function () {
        Route::get('/staff/background-checks', [StaffBackgroundCheckController::class, 'index'])
            ->name('staff.background-checks.index');
        Route::get('/staff/{user}/background-checks', [StaffBackgroundCheckController::class, 'userChecks'])
            ->name('staff.background-checks.user');
        Route::get('/staff/background-checks/{check}', [StaffBackgroundCheckController::class, 'show'])
            ->name('staff.background-checks.show');
    });

    Route::middleware('permission:vetting.manage')->group(function () {
        Route::get('/staff/{user}/background-checks/create', [StaffBackgroundCheckController::class, 'create'])
            ->name('staff.background-checks.create');
        Route::post('/staff/{user}/background-checks', [StaffBackgroundCheckController::class, 'store'])
            ->name('staff.background-checks.store');
        Route::get('/staff/background-checks/{check}/edit', [StaffBackgroundCheckController::class, 'edit'])
            ->name('staff.background-checks.edit');
        Route::put('/staff/background-checks/{check}', [StaffBackgroundCheckController::class, 'update'])
            ->name('staff.background-checks.update');
    });

    Route::middleware('permission:vetting.verify')->group(function () {
        Route::post('/staff/background-checks/{check}/verify', [StaffBackgroundCheckController::class, 'verify'])
            ->name('staff.background-checks.verify');
    });

    Route::middleware('permission:vetting.assessRisk')->group(function () {
        Route::post('/staff/background-checks/{check}/assess-risk', [StaffBackgroundCheckController::class, 'assessRisk'])
            ->name('staff.background-checks.assess-risk');
    });

    // Training Courses
    Route::middleware('permission:training.viewAny')->group(function () {
        Route::get('/training/courses', [TrainingCourseController::class, 'index'])
            ->name('training.courses.index');
        Route::get('/training/matrix', fn () => redirect()->route('hr.training.index'))
            ->name('training.matrix');
    });

    Route::middleware('permission:training.manageCourses')->group(function () {
        Route::get('/training/courses/create', [TrainingCourseController::class, 'create'])
            ->name('training.courses.create');
        Route::post('/training/courses', [TrainingCourseController::class, 'store'])
            ->name('training.courses.store');
        Route::get('/training/courses/{course}/edit', [TrainingCourseController::class, 'edit'])
            ->name('training.courses.edit');
        Route::put('/training/courses/{course}', [TrainingCourseController::class, 'update'])
            ->name('training.courses.update');
        Route::delete('/training/courses/{course}', [TrainingCourseController::class, 'destroy'])
            ->name('training.courses.destroy');
    });

    Route::middleware('permission:training.viewAny')->group(function () {
        Route::get('/training/courses/{course}', [TrainingCourseController::class, 'show'])
            ->name('training.courses.show');
    });

    // Staff Training Records — disabled until StaffTrainingRecordController implementation is complete.
    // Route::middleware('permission:training.viewAny')->group(function () {
    //     Route::get('/staff/training', [StaffTrainingRecordController::class, 'index'])
    //         ->name('staff.training.index');
    //     Route::get('/staff/{user}/training', [StaffTrainingRecordController::class, 'userTraining'])
    //         ->name('staff.training.user');
    //     Route::get('/staff/training/{record}', [StaffTrainingRecordController::class, 'show'])
    //         ->name('staff.training.show');
    //     Route::get('/training/matrix', [StaffTrainingRecordController::class, 'matrix'])
    //         ->name('training.matrix');
    // });
    //
    // Route::middleware('permission:training.enrol')->group(function () {
    //     Route::post('/staff/{user}/training/enrol', [StaffTrainingRecordController::class, 'enrol'])
    //         ->name('staff.training.enrol');
    // });
    //
    // Route::middleware('permission:training.record')->group(function () {
    //     Route::put('/staff/training/{record}', [StaffTrainingRecordController::class, 'update'])
    //         ->name('staff.training.update');
    //     Route::post('/staff/training/{record}/complete', [StaffTrainingRecordController::class, 'markComplete'])
    //         ->name('staff.training.complete');
    //     Route::post('/staff/training/{record}/renew', [StaffTrainingRecordController::class, 'renew'])
    //         ->name('staff.training.renew');
    // });
    //
    // Route::middleware('permission:training.exempt')->group(function () {
    //     Route::post('/staff/training/{record}/exempt', [StaffTrainingRecordController::class, 'exempt'])
    //         ->name('staff.training.exempt');
    // });

    // Competency Framework
    // Competency Framework routes — StaffCompetencyController is a stub; disabled until implementation is complete.
    // CompetencyFrameworkController routes are kept active as they are implemented.
    Route::middleware('permission:competency.viewAny')->group(function () {
        Route::get('/competency/frameworks', [CompetencyFrameworkController::class, 'index'])
            ->name('competency.frameworks.index');
        Route::get('/competency/frameworks/{framework}', [CompetencyFrameworkController::class, 'show'])
            ->name('competency.frameworks.show');
        // Route::get('/staff/{user}/competency', [StaffCompetencyController::class, 'userCompetency'])
        //     ->name('staff.competency.show');
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

    // Route::middleware('permission:competency.assess')->group(function () {
    //     Route::post('/staff/{user}/competency/assess', [StaffCompetencyController::class, 'assess'])
    //         ->name('staff.competency.assess');
    //     Route::put('/staff/competency/{assessment}', [StaffCompetencyController::class, 'updateAssessment'])
    //         ->name('staff.competency.update');
    // });

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
