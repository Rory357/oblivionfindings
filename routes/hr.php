<?php

use App\Http\Controllers\Hr\RecruitmentController;
use App\Http\Controllers\Hr\CandidateController;
use App\Http\Controllers\Hr\EmployeeProfileController;
use App\Http\Controllers\Hr\ComplianceController;
use App\Http\Controllers\Hr\ComplianceMatrixController;
use App\Http\Controllers\Hr\TrainingDashboardController;
use App\Http\Controllers\Hr\VettingController;
use App\Http\Controllers\Hr\DriverEligibilityController;
use App\Http\Controllers\Hr\LeaveController;
use App\Http\Controllers\Hr\OnboardingController;
use App\Http\Controllers\Hr\SupervisionController;
use App\Http\Controllers\Hr\PerformanceReviewController;
use App\Http\Controllers\Hr\HrCaseController;
use App\Http\Controllers\Hr\DisciplinaryController;
use App\Http\Controllers\Hr\PolicyController;
use App\Http\Controllers\Hr\PolicyAttestationController;
use App\Http\Controllers\Hr\HrDocumentController;
use App\Http\Controllers\Hr\PayrollExportController;
use App\Http\Controllers\Hr\HrReportController;
use App\Http\Controllers\Hr\MyHrController;
use Illuminate\Support\Facades\Route;

/**
 * HR Module Routes
 */

Route::middleware(['auth'])->prefix('hr')->name('hr.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | My HR (Self-Service) — accessible to all authenticated users
    |--------------------------------------------------------------------------
    */
    Route::prefix('my')->name('my.')->group(function () {
        Route::get('/', [MyHrController::class, 'index'])->name('index');
        Route::get('/leave', [MyHrController::class, 'leave'])->name('leave');
        Route::post('/leave', [MyHrController::class, 'submitLeave'])->name('leave.store');
        Route::delete('/leave/{leaveRequest}', [MyHrController::class, 'cancelLeave'])->name('leave.cancel');
        Route::get('/training', [MyHrController::class, 'training'])->name('training');
        Route::get('/policies', [MyHrController::class, 'policies'])->name('policies');
        Route::post('/policies/{policy}/attest', [MyHrController::class, 'attestPolicy'])->name('policies.attest');
        Route::get('/profile', [MyHrController::class, 'profile'])->name('profile');
        Route::put('/profile', [MyHrController::class, 'updateProfile'])->name('profile.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Recruitment
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.recruitment.view')->group(function () {
        Route::get('/recruitment', [RecruitmentController::class, 'index'])->name('recruitment.index');

        Route::get('/recruitment/candidates/create', [CandidateController::class, 'create'])->name('candidates.create')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/candidates', [CandidateController::class, 'store'])->name('candidates.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::get('/recruitment/candidates/{candidate}', [CandidateController::class, 'show'])->name('candidates.show');
        Route::put('/recruitment/candidates/{candidate}', [CandidateController::class, 'update'])->name('candidates.update')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/candidates/{candidate}/advance', [CandidateController::class, 'advance'])->name('candidates.advance')
            ->middleware('permission:hr.recruitment.manage');

        // Interviews
        Route::post('/recruitment/applications/{application}/interviews', [CandidateController::class, 'storeInterview'])->name('interviews.store')
            ->middleware('permission:hr.recruitment.manage');

        // Reference Checks
        Route::post('/recruitment/applications/{application}/references', [CandidateController::class, 'storeReference'])->name('references.store')
            ->middleware('permission:hr.recruitment.manage');

        // Application Actions
        Route::post('/recruitment/applications/{application}/reject', [CandidateController::class, 'rejectApplication'])->name('applications.reject')
            ->middleware('permission:hr.recruitment.manage');

        // Offers
        Route::get('/recruitment/offers/create/{application}', [CandidateController::class, 'createOffer'])->name('offers.create')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/offers', [CandidateController::class, 'storeOffer'])->name('offers.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/offers/{offer}/send', [CandidateController::class, 'sendOffer'])->name('offers.send')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/offers/{offer}/convert', [CandidateController::class, 'convertToEmployee'])->name('offers.convert')
            ->middleware('permission:hr.recruitment.manage');
    });

    /*
    |--------------------------------------------------------------------------
    | People (Employee Profiles)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.employees.viewAny')->group(function () {
        Route::get('/people', [EmployeeProfileController::class, 'index'])->name('people.index');
        Route::get('/people/{profile}', [EmployeeProfileController::class, 'show'])->name('people.show');

        Route::middleware('permission:hr.employees.manage')->group(function () {
            Route::get('/people/{profile}/edit', [EmployeeProfileController::class, 'edit'])->name('people.edit');
            Route::put('/people/{profile}', [EmployeeProfileController::class, 'update'])->name('people.update');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Compliance
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.compliance.view')->group(function () {
        Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');
        Route::get('/compliance/staff/{user}', [ComplianceController::class, 'staffDetail'])->name('compliance.staff');

        Route::middleware('permission:hr.compliance.manage')->group(function () {
            Route::get('/compliance/matrix', [ComplianceMatrixController::class, 'index'])->name('compliance.matrix');
            Route::post('/compliance/requirements', [ComplianceMatrixController::class, 'storeRequirement'])->name('compliance.requirements.store');
            Route::put('/compliance/requirements/{requirement}', [ComplianceMatrixController::class, 'updateRequirement'])->name('compliance.requirements.update');
            Route::delete('/compliance/requirements/{requirement}', [ComplianceMatrixController::class, 'destroyRequirement'])->name('compliance.requirements.destroy');
            Route::post('/compliance/matrix', [ComplianceMatrixController::class, 'updateMatrix'])->name('compliance.matrix.update');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Training Dashboard
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.training.view')->group(function () {
        Route::get('/compliance/training', [TrainingDashboardController::class, 'index'])->name('training.index');
    });

    /*
    |--------------------------------------------------------------------------
    | Vetting Register
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.vetting.view')->group(function () {
        Route::get('/compliance/vetting', [VettingController::class, 'index'])->name('vetting.index');
        Route::get('/compliance/vetting/{check}', [VettingController::class, 'show'])->name('vetting.show');

        Route::middleware('permission:hr.vetting.manage')->group(function () {
            Route::get('/compliance/vetting/create', [VettingController::class, 'create'])->name('vetting.create');
            Route::post('/compliance/vetting', [VettingController::class, 'store'])->name('vetting.store');
            Route::get('/compliance/vetting/{check}/edit', [VettingController::class, 'edit'])->name('vetting.edit');
            Route::put('/compliance/vetting/{check}', [VettingController::class, 'update'])->name('vetting.update');
            Route::delete('/compliance/vetting/{check}', [VettingController::class, 'destroy'])->name('vetting.destroy');
            Route::post('/compliance/vetting/{check}/clear', [VettingController::class, 'clear'])->name('vetting.clear');
            Route::post('/compliance/vetting/{check}/renew', [VettingController::class, 'renew'])->name('vetting.renew');
            Route::post('/compliance/vetting/{check}/consent', [VettingController::class, 'captureConsent'])->name('vetting.consent');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Driver Eligibility
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.driver.view')->group(function () {
        Route::get('/compliance/drivers', [DriverEligibilityController::class, 'index'])->name('drivers.index');

        Route::middleware('permission:hr.driver.manage')->group(function () {
            Route::post('/compliance/drivers', [DriverEligibilityController::class, 'store'])->name('drivers.store');
            Route::put('/compliance/drivers/{eligibility}', [DriverEligibilityController::class, 'update'])->name('drivers.update');
            Route::post('/compliance/drivers/{eligibility}/approve', [DriverEligibilityController::class, 'approve'])->name('drivers.approve');
            Route::post('/compliance/drivers/{eligibility}/suspend', [DriverEligibilityController::class, 'suspend'])->name('drivers.suspend');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Leave Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.leave.viewAny')->group(function () {
        Route::get('/leave', [LeaveController::class, 'index'])->name('leave.index');
        Route::get('/leave/balances', [LeaveController::class, 'balances'])->name('leave.balances');

        Route::middleware('permission:hr.leave.manage')->group(function () {
            Route::get('/leave/create', [LeaveController::class, 'create'])->name('leave.create');
            Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
        });

        Route::middleware('permission:hr.leave.approve')->group(function () {
            Route::get('/leave/{leaveRequest}', [LeaveController::class, 'show'])->name('leave.show');
            Route::post('/leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
            Route::post('/leave/{leaveRequest}/decline', [LeaveController::class, 'decline'])->name('leave.decline');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Onboarding
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.onboarding.view')->group(function () {
        Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
        Route::get('/onboarding/{checklist}', [OnboardingController::class, 'show'])->name('onboarding.show');

        Route::middleware('permission:hr.onboarding.manage')->group(function () {
            Route::get('/onboarding/create', [OnboardingController::class, 'create'])->name('onboarding.create');
            Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
            Route::post('/onboarding/tasks/{task}/complete', [OnboardingController::class, 'completeTask'])->name('onboarding.tasks.complete');
            Route::put('/onboarding/templates', [OnboardingController::class, 'updateTemplates'])->name('onboarding.templates.update');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Performance & Supervision
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->prefix('performance')->name('performance.')->group(function () {
        Route::get('/', [SupervisionController::class, 'index'])->name('index');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            // Supervision notes
            Route::get('/supervision/create', [SupervisionController::class, 'create'])->name('supervision.create');
            Route::post('/supervision', [SupervisionController::class, 'store'])->name('supervision.store');
            Route::get('/supervision/{note}', [SupervisionController::class, 'show'])->name('supervision.show');
            Route::get('/supervision/{note}/edit', [SupervisionController::class, 'edit'])->name('supervision.edit');
            Route::put('/supervision/{note}', [SupervisionController::class, 'update'])->name('supervision.update');

            // Performance reviews
            Route::get('/reviews', [PerformanceReviewController::class, 'index'])->name('reviews.index');
            Route::get('/reviews/create', [PerformanceReviewController::class, 'create'])->name('reviews.create');
            Route::post('/reviews', [PerformanceReviewController::class, 'store'])->name('reviews.store');
            Route::get('/reviews/{review}', [PerformanceReviewController::class, 'show'])->name('reviews.show');
            Route::get('/reviews/{review}/edit', [PerformanceReviewController::class, 'edit'])->name('reviews.edit');
            Route::put('/reviews/{review}', [PerformanceReviewController::class, 'update'])->name('reviews.update');

            // Probation reviews
            Route::post('/probation', [PerformanceReviewController::class, 'storeProbation'])->name('probation.store');
            Route::put('/probation/{review}', [PerformanceReviewController::class, 'updateProbation'])->name('probation.update');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | HR Cases & Disciplinary
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.cases.view')->prefix('cases')->name('cases.')->group(function () {
        Route::get('/', [HrCaseController::class, 'index'])->name('index');
        Route::get('/create', [HrCaseController::class, 'create'])->name('create')
            ->middleware('permission:hr.cases.manage');
        Route::post('/', [HrCaseController::class, 'store'])->name('store')
            ->middleware('permission:hr.cases.manage');
        Route::get('/{case}', [HrCaseController::class, 'show'])->name('show');
        Route::put('/{case}', [HrCaseController::class, 'update'])->name('update')
            ->middleware('permission:hr.cases.manage');
        Route::post('/{case}/close', [HrCaseController::class, 'close'])->name('close')
            ->middleware('permission:hr.cases.manage');

        // Case Events
        Route::get('/{case}/events/create', [HrCaseController::class, 'createEvent'])->name('events.create')
            ->middleware('permission:hr.cases.manage');
        Route::post('/{case}/events', [HrCaseController::class, 'storeEvent'])->name('events.store')
            ->middleware('permission:hr.cases.manage');

        // Disciplinary Actions
        Route::get('/{case}/disciplinary/create', [DisciplinaryController::class, 'create'])->name('disciplinary.create')
            ->middleware('permission:hr.disciplinary.manage');
        Route::post('/{case}/disciplinary', [DisciplinaryController::class, 'store'])->name('disciplinary.store')
            ->middleware('permission:hr.disciplinary.manage');
        Route::put('/disciplinary/{action}', [DisciplinaryController::class, 'update'])->name('disciplinary.update')
            ->middleware('permission:hr.disciplinary.manage');
        Route::post('/disciplinary/{action}/advance', [DisciplinaryController::class, 'advanceStage'])->name('disciplinary.advance')
            ->middleware('permission:hr.disciplinary.manage');
    });

    /*
    |--------------------------------------------------------------------------
    | Policies & Attestations
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.policies.view')->prefix('policies')->name('policies.')->group(function () {
        Route::get('/', [PolicyController::class, 'index'])->name('index');
        Route::get('/attestations', [PolicyAttestationController::class, 'index'])->name('attestations.index');

        Route::middleware('permission:hr.policies.manage')->group(function () {
            Route::get('/create', [PolicyController::class, 'create'])->name('create');
            Route::post('/', [PolicyController::class, 'store'])->name('store');
            Route::get('/{policy}/edit', [PolicyController::class, 'edit'])->name('edit');
            Route::put('/{policy}', [PolicyController::class, 'update'])->name('update');
            Route::delete('/{policy}', [PolicyController::class, 'destroy'])->name('destroy');
            Route::post('/{policy}/versions', [PolicyController::class, 'storeVersion'])->name('versions.store');
            Route::delete('/{policy}/versions/{version}', [PolicyController::class, 'destroyVersion'])->name('versions.destroy');
        });

        Route::get('/{policy}', [PolicyController::class, 'show'])->name('show');

        Route::middleware('permission:hr.policies.attest')->group(function () {
            Route::post('/{policy}/attest', [PolicyAttestationController::class, 'store'])->name('attestations.store');
            Route::get('/{policy}/download', [PolicyController::class, 'download'])->name('download');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | HR Documents
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.documents.view')->group(function () {
        Route::get('/documents', [HrDocumentController::class, 'index'])->name('documents.index');

        Route::middleware('permission:hr.documents.manage')->group(function () {
            Route::post('/documents', [HrDocumentController::class, 'store'])->name('documents.store');
            Route::post('/documents/generate', [HrDocumentController::class, 'generate'])->name('documents.generate');
            Route::delete('/documents/{document}', [HrDocumentController::class, 'destroy'])->name('documents.destroy');
            Route::get('/documents/templates', [HrDocumentController::class, 'templates'])->name('documents.templates');
            Route::get('/documents/templates/create', [HrDocumentController::class, 'createTemplate'])->name('documents.templates.create');
            Route::post('/documents/templates', [HrDocumentController::class, 'storeTemplate'])->name('documents.templates.store');
            Route::get('/documents/templates/{template}/edit', [HrDocumentController::class, 'editTemplate'])->name('documents.templates.edit');
            Route::put('/documents/templates/{template}', [HrDocumentController::class, 'updateTemplate'])->name('documents.templates.update');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Payroll Export
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.payroll.view')->group(function () {
        Route::get('/payroll', [PayrollExportController::class, 'index'])->name('payroll.index');

        Route::middleware('permission:hr.payroll.export')->group(function () {
            Route::post('/payroll/runs', [PayrollExportController::class, 'createRun'])->name('payroll.runs.store');
            Route::post('/payroll/runs/{run}/lock', [PayrollExportController::class, 'lockRun'])->name('payroll.runs.lock');
            Route::post('/payroll/runs/{run}/export', [PayrollExportController::class, 'export'])->name('payroll.runs.export');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | HR Reports
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.reports.view')->group(function () {
        Route::get('/reports', [HrReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [HrReportController::class, 'generate'])->name('reports.generate');

        Route::middleware('permission:hr.reports.export')->group(function () {
            Route::post('/reports/export', [HrReportController::class, 'export'])->name('reports.export');
        });
    });
});
