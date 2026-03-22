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
use App\Http\Controllers\Hr\CompensationController;
use App\Http\Controllers\Hr\HrReportController;
use App\Http\Controllers\Hr\MyHrController;
use App\Http\Controllers\Hr\PositionController;
use App\Http\Controllers\Hr\OrgChartController;
use App\Http\Controllers\Hr\DirectoryController;
use App\Http\Controllers\Hr\TimeTrackingController;
use App\Http\Controllers\Hr\BenefitsController;
use App\Http\Controllers\Hr\GoalController;
use App\Http\Controllers\Hr\TrainingController;
use App\Http\Controllers\Hr\AssetController;
use App\Http\Controllers\Hr\TimeOffCalendarController;
use App\Http\Controllers\Hr\AnalyticsDashboardController;
use App\Http\Controllers\Hr\SurveyController;
use App\Http\Controllers\Hr\ExpenseController;
use App\Http\Controllers\Hr\SkillsController;
use App\Http\Controllers\Hr\CalendarController;
use App\Http\Controllers\Hr\AnnouncementController;
use App\Http\Controllers\Hr\ExitInterviewController;
use App\Http\Controllers\Hr\ReportBuilderController;
use App\Http\Controllers\Hr\ApprovalController;
use App\Http\Controllers\Hr\ESignatureController;
use App\Http\Controllers\Hr\PayslipController;
use App\Http\Controllers\Hr\JobPostingController;
use App\Http\Controllers\Hr\WellbeingController;
use App\Http\Controllers\Hr\FeedController;
use App\Http\Controllers\Hr\FeedbackController;
use App\Http\Controllers\Hr\ICalController;
use App\Http\Controllers\Hr\WebhookController;
use App\Http\Controllers\Hr\CustomFieldController;
use App\Http\Controllers\Hr\AuditController;
use App\Http\Controllers\Hr\LeaveReportController;
use App\Http\Controllers\Hr\PipController;
use App\Http\Controllers\Hr\CompetencyController;
use App\Http\Controllers\Hr\ImportExportController;
use App\Http\Controllers\Hr\ScorecardController;
use App\Http\Controllers\Hr\ComplianceCalendarController;
use App\Http\Controllers\Hr\OnboardingEmailController;
use App\Http\Controllers\Hr\SuccessionController;
use App\Http\Controllers\Hr\HeadcountController;
use App\Http\Controllers\Hr\BonusController;
use Illuminate\Support\Facades\Route;

/**
 * HR Module Routes
 */

// Public iCal feed (no auth — uses token)
Route::get('/hr/ical/{token}.ics', [ICalController::class, 'feed'])->name('hr.ical.feed');

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
        Route::get('/time', [MyHrController::class, 'timeTracking'])->name('time');
        Route::post('/time/clock-in', [MyHrController::class, 'clockIn'])->name('time.clockin');
        Route::post('/time/clock-out', [MyHrController::class, 'clockOut'])->name('time.clockout');
        Route::put('/profile', [MyHrController::class, 'updateProfile'])->name('profile.update');
        Route::get('/goals', [MyHrController::class, 'goals'])->name('goals');
        Route::get('/expenses', [MyHrController::class, 'expenses'])->name('expenses');
        Route::post('/expenses', [MyHrController::class, 'storeExpense'])->name('expenses.store');
        Route::get('/payslips', [PayslipController::class, 'myPayslips'])->name('payslips');
        Route::post('/check-in', [MyHrController::class, 'checkIn'])->name('checkin');
        Route::post('/ical-token', [ICalController::class, 'generateToken'])->name('ical.generate');
    });

    /*
    |--------------------------------------------------------------------------
    | Recruitment
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.recruitment.view')->group(function () {
        Route::get('/recruitment', [RecruitmentController::class, 'index'])->name('recruitment.index');
        Route::get('/recruitment/kanban', [RecruitmentController::class, 'kanban'])->name('recruitment.kanban');
        Route::get('/recruitment/analytics', [RecruitmentController::class, 'analytics'])->name('recruitment.analytics');

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

        // Interview Scorecards
        Route::get('/recruitment/interviews/{interview}/scorecard', [ScorecardController::class, 'create'])->name('scorecards.create');
        Route::post('/recruitment/interviews/{interview}/scorecard', [ScorecardController::class, 'store'])->name('scorecards.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::get('/recruitment/applications/{application}/scorecards', [ScorecardController::class, 'summary'])->name('scorecards.summary');

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
    | Employee Import / Export
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.employees.manage')->prefix('import-export')->name('import-export.')->group(function () {
        Route::get('/', [ImportExportController::class, 'index'])->name('index');
        Route::post('/export', [ImportExportController::class, 'export'])->name('export');
        Route::get('/template', [ImportExportController::class, 'template'])->name('template');
        Route::post('/import', [ImportExportController::class, 'import'])->name('import');
    });

    /*
    |--------------------------------------------------------------------------
    | Organisation Chart
    |--------------------------------------------------------------------------
    */
    Route::get('/orgchart', [OrgChartController::class, 'index'])->name('orgchart.index');
    Route::put('/orgchart/{profile}', [OrgChartController::class, 'update'])->name('orgchart.update');

    /*
    |--------------------------------------------------------------------------
    | Employee Directory
    |--------------------------------------------------------------------------
    */
    Route::get('/directory', [DirectoryController::class, 'index'])->name('directory.index');
    Route::get('/directory/{profile}', [DirectoryController::class, 'show'])->name('directory.show');
    Route::post('/directory/{profile}/photo', [DirectoryController::class, 'uploadPhoto'])->name('directory.photo')
        ->middleware('permission:hr.employees.manage');

    /*
    |--------------------------------------------------------------------------
    | Time Tracking
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.time.view')->prefix('time')->name('time.')->group(function () {
        Route::get('/', [TimeTrackingController::class, 'index'])->name('index');
        Route::get('/timesheets', [TimeTrackingController::class, 'timesheets'])->name('timesheets');

        Route::middleware('permission:hr.time.manage')->group(function () {
            Route::post('/entries', [TimeTrackingController::class, 'store'])->name('entries.store');
            Route::post('/clock-in', [TimeTrackingController::class, 'clockIn'])->name('clockin');
            Route::post('/clock-out', [TimeTrackingController::class, 'clockOut'])->name('clockout');
            Route::post('/timesheets/submit', [TimeTrackingController::class, 'submitTimesheet'])->name('timesheets.submit');
        });

        Route::middleware('permission:hr.time.approve')->group(function () {
            Route::post('/timesheets/{timesheet}/approve', [TimeTrackingController::class, 'approveTimesheet'])->name('timesheets.approve');
            Route::post('/timesheets/{timesheet}/reject', [TimeTrackingController::class, 'rejectTimesheet'])->name('timesheets.reject');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Compliance
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.compliance.view')->group(function () {
        Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');
        Route::get('/compliance/calendar', [ComplianceCalendarController::class, 'index'])->name('compliance.calendar');
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
        Route::get('/leave/reports', [LeaveReportController::class, 'index'])->name('leave.reports');
        Route::get('/leave/holidays', [LeaveController::class, 'holidays'])->name('leave.holidays');

        Route::middleware('permission:hr.leave.manage')->group(function () {
            Route::get('/leave/create', [LeaveController::class, 'create'])->name('leave.create');
            Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
            Route::post('/leave/holidays', [LeaveController::class, 'storeHoliday'])->name('leave.holidays.store');
            Route::delete('/leave/holidays/{holiday}', [LeaveController::class, 'destroyHoliday'])->name('leave.holidays.destroy');
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

        Route::middleware('permission:hr.onboarding.manage')->group(function () {
            Route::get('/onboarding/create', [OnboardingController::class, 'create'])->name('onboarding.create');
            Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
            Route::post('/onboarding/tasks/{task}/complete', [OnboardingController::class, 'completeTask'])->name('onboarding.tasks.complete');
            Route::put('/onboarding/templates', [OnboardingController::class, 'updateTemplates'])->name('onboarding.templates.update');

            // Onboarding Email Sequences
            Route::get('/onboarding/emails', [OnboardingEmailController::class, 'index'])->name('onboarding.emails');
            Route::post('/onboarding/emails', [OnboardingEmailController::class, 'store'])->name('onboarding.emails.store');
            Route::put('/onboarding/emails/{email}', [OnboardingEmailController::class, 'update'])->name('onboarding.emails.update');
            Route::delete('/onboarding/emails/{email}', [OnboardingEmailController::class, 'destroy'])->name('onboarding.emails.destroy');
            Route::get('/onboarding/emails/{email}/preview', [OnboardingEmailController::class, 'preview'])->name('onboarding.emails.preview');
            Route::get('/onboarding/emails/log', [OnboardingEmailController::class, 'log'])->name('onboarding.emails.log');
        });

        Route::get('/onboarding/{checklist}', [OnboardingController::class, 'show'])->name('onboarding.show');
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
        Route::get('/documents/expiring', [HrDocumentController::class, 'expiring'])->name('documents.expiring');

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
    | Compensation Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.compensation.view')->prefix('compensation')->name('compensation.')->group(function () {
        Route::get('/bands', [CompensationController::class, 'bands'])->name('bands.index');
        Route::get('/history/{profile}', [CompensationController::class, 'history'])->name('history');
        Route::get('/reviews', [CompensationController::class, 'reviews'])->name('reviews.index');
        Route::get('/reviews/{review}', [CompensationController::class, 'showReview'])->name('reviews.show');

        Route::middleware('permission:hr.compensation.manage')->group(function () {
            Route::post('/bands', [CompensationController::class, 'storeBand'])->name('bands.store');
            Route::put('/bands/{band}', [CompensationController::class, 'updateBand'])->name('bands.update');
            Route::get('/reviews/create', [CompensationController::class, 'createReview'])->name('reviews.create');
            Route::post('/reviews', [CompensationController::class, 'storeReview'])->name('reviews.store');
            Route::post('/reviews/{review}/apply', [CompensationController::class, 'applyReview'])->name('reviews.apply');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Payroll Export
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.payroll.view')->group(function () {
        Route::get('/payroll', [PayrollExportController::class, 'index'])->name('payroll.index');
        Route::get('/payroll/payslips', [PayslipController::class, 'index'])->name('payroll.payslips');
        Route::get('/payroll/payslips/{payslip}', [PayslipController::class, 'show'])->name('payroll.payslips.show');
        Route::get('/payroll/payslips/{payslip}/download', [PayslipController::class, 'download'])->name('payroll.payslips.download');

        Route::middleware('permission:hr.payroll.export')->group(function () {
            Route::post('/payroll/runs', [PayrollExportController::class, 'createRun'])->name('payroll.runs.store');
            Route::post('/payroll/runs/{run}/lock', [PayrollExportController::class, 'lockRun'])->name('payroll.runs.lock');
            Route::post('/payroll/runs/{run}/export', [PayrollExportController::class, 'export'])->name('payroll.runs.export');
            Route::post('/payroll/runs/{run}/export-formatted', [PayrollExportController::class, 'exportFormatted'])->name('payroll.runs.export-formatted');
            Route::post('/payroll/payslips/generate', [PayslipController::class, 'generate'])->name('payroll.payslips.generate');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Positions / Job Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.positions.view')->prefix('positions')->name('positions.')->group(function () {
        Route::get('/', [PositionController::class, 'index'])->name('index');

        Route::middleware('permission:hr.positions.manage')->group(function () {
            Route::get('/create', [PositionController::class, 'create'])->name('create');
            Route::post('/', [PositionController::class, 'store'])->name('store');
            Route::get('/{position}/edit', [PositionController::class, 'edit'])->name('edit');
            Route::put('/{position}', [PositionController::class, 'update'])->name('update');
        });

        Route::get('/{position}', [PositionController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Benefits Administration
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.benefits.view')->prefix('benefits')->name('benefits.')->group(function () {
        Route::get('/', [BenefitsController::class, 'index'])->name('index');
        Route::get('/plans', [BenefitsController::class, 'plans'])->name('plans');

        Route::middleware('permission:hr.benefits.manage')->group(function () {
            Route::post('/plans', [BenefitsController::class, 'storePlan'])->name('plans.store');
            Route::post('/enroll', [BenefitsController::class, 'enroll'])->name('enroll');
            Route::put('/enrollments/{enrollment}', [BenefitsController::class, 'updateEnrollment'])->name('enrollments.update');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Goals & OKRs
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.goals.view')->prefix('goals')->name('goals.')->group(function () {
        Route::get('/', [GoalController::class, 'index'])->name('index');

        Route::middleware('permission:hr.goals.manage')->group(function () {
            Route::get('/create', [GoalController::class, 'create'])->name('create');
            Route::post('/', [GoalController::class, 'store'])->name('store');
            Route::put('/{goal}', [GoalController::class, 'update'])->name('update');
            Route::post('/{goal}/progress', [GoalController::class, 'updateProgress'])->name('progress');
        });

        Route::get('/{goal}', [GoalController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Training / Learning Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.training.view')->group(function () {
        Route::get('/training/catalog', [TrainingController::class, 'catalog'])->name('training.catalog');
        Route::get('/training/courses/{course}', [TrainingController::class, 'showCourse'])->name('training.courses.show');

        Route::middleware('permission:hr.training.manage')->group(function () {
            Route::post('/training/courses', [TrainingController::class, 'storeCourse'])->name('training.courses.store');
            Route::post('/training/courses/{course}/sessions', [TrainingController::class, 'storeSession'])->name('training.sessions.store');
        });

        Route::middleware('permission:hr.training.enroll')->group(function () {
            Route::post('/training/enroll/{course}', [TrainingController::class, 'enroll'])->name('training.enroll');
            Route::post('/training/enrollments/{enrollment}/complete', [TrainingController::class, 'completeEnrollment'])->name('training.enrollments.complete');
        });

        Route::get('/training/enrollments/{enrollment}/certificate', [TrainingController::class, 'downloadCertificate'])->name('training.certificate');
    });

    /*
    |--------------------------------------------------------------------------
    | Asset Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.assets.view')->prefix('assets')->name('assets.')->group(function () {
        Route::get('/', [AssetController::class, 'index'])->name('index');

        Route::middleware('permission:hr.assets.manage')->group(function () {
            Route::get('/create', [AssetController::class, 'create'])->name('create');
            Route::post('/', [AssetController::class, 'store'])->name('store');
            Route::post('/{asset}/assign', [AssetController::class, 'assign'])->name('assign');
            Route::post('/assignments/{assignment}/return', [AssetController::class, 'returnAsset'])->name('return');
        });

        Route::get('/{asset}', [AssetController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Time Off Calendar
    |--------------------------------------------------------------------------
    */
    Route::get('/calendar/time-off', [TimeOffCalendarController::class, 'index'])->name('calendar.timeoff');

    /*
    |--------------------------------------------------------------------------
    | Workforce Analytics
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.analytics.view')->group(function () {
        Route::get('/analytics', [AnalyticsDashboardController::class, 'index'])->name('analytics.index');
    });

    /*
    |--------------------------------------------------------------------------
    | Employee Surveys
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.surveys.view')->prefix('surveys')->name('surveys.')->group(function () {
        Route::get('/', [SurveyController::class, 'index'])->name('index');

        Route::middleware('permission:hr.surveys.manage')->group(function () {
            Route::get('/create', [SurveyController::class, 'create'])->name('create');
            Route::post('/', [SurveyController::class, 'store'])->name('store');
        });

        Route::get('/{survey}', [SurveyController::class, 'show'])->name('show');
        Route::get('/{survey}/respond', [SurveyController::class, 'respond'])->name('respond');
        Route::post('/{survey}/respond', [SurveyController::class, 'submitResponse'])->name('respond.store');
    });

    /*
    |--------------------------------------------------------------------------
    | Expense Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.expenses.view')->prefix('expenses')->name('expenses.')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');

        Route::middleware('permission:hr.expenses.manage')->group(function () {
            Route::get('/create', [ExpenseController::class, 'create'])->name('create');
            Route::post('/', [ExpenseController::class, 'store'])->name('store');
            Route::post('/{claim}/submit', [ExpenseController::class, 'submit'])->name('submit');
        });

        Route::middleware('permission:hr.expenses.approve')->group(function () {
            Route::post('/{claim}/approve', [ExpenseController::class, 'approve'])->name('approve');
            Route::post('/{claim}/reject', [ExpenseController::class, 'reject'])->name('reject');
        });

        Route::get('/{claim}', [ExpenseController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Skills Matrix
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.skills.view')->prefix('skills')->name('skills.')->group(function () {
        Route::get('/', [SkillsController::class, 'index'])->name('index');
        Route::get('/matrix', [SkillsController::class, 'matrix'])->name('matrix');

        Route::middleware('permission:hr.skills.manage')->group(function () {
            Route::post('/', [SkillsController::class, 'storeSkill'])->name('store');
            Route::post('/assess', [SkillsController::class, 'assessEmployee'])->name('assess');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Community Feed & Kudos
    |--------------------------------------------------------------------------
    */
    Route::prefix('feed')->name('feed.')->group(function () {
        Route::get('/', [FeedController::class, 'index'])->name('index');
        Route::post('/', [FeedController::class, 'store'])->name('store');
        Route::post('/kudos', [FeedController::class, 'sendKudos'])->name('kudos');
    });

    /*
    |--------------------------------------------------------------------------
    | 360-Degree Feedback
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->prefix('feedback')->name('feedback.')->group(function () {
        Route::get('/', [FeedbackController::class, 'index'])->name('index');
        Route::get('/summary/{user}', [FeedbackController::class, 'summary'])->name('summary');
        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::get('/request', [FeedbackController::class, 'request'])->name('request');
            Route::post('/request', [FeedbackController::class, 'storeRequest'])->name('request.store');
        });
        Route::get('/{feedbackRequest}/respond', [FeedbackController::class, 'respond'])->name('respond');
        Route::post('/{feedbackRequest}/respond', [FeedbackController::class, 'submitResponse'])->name('respond.store');
    });

    /*
    |--------------------------------------------------------------------------
    | HR Reports
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.reports.view')->group(function () {
        Route::get('/reports', [HrReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [HrReportController::class, 'generate'])->name('reports.generate');

        // Report Builder
        Route::get('/reports/builder', [ReportBuilderController::class, 'create'])->name('reports.builder');
        Route::post('/reports/preview', [ReportBuilderController::class, 'preview'])->name('reports.preview');
        Route::post('/reports/save', [ReportBuilderController::class, 'store'])->name('reports.save');
        Route::get('/reports/saved', [ReportBuilderController::class, 'index'])->name('reports.saved');
        Route::post('/reports/{report}/run', [ReportBuilderController::class, 'run'])->name('reports.run');
        Route::delete('/reports/{report}', [ReportBuilderController::class, 'destroy'])->name('reports.destroy');
        Route::post('/reports/{report}/schedule', [ReportBuilderController::class, 'schedule'])->name('reports.schedule');

        Route::middleware('permission:hr.reports.export')->group(function () {
            Route::post('/reports/export', [HrReportController::class, 'export'])->name('reports.export');
            Route::post('/reports/{report}/export', [ReportBuilderController::class, 'export'])->name('reports.builder.export');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Company Calendar
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.calendar.view')->prefix('calendar')->name('calendar.')->group(function () {
        Route::get('/', [CalendarController::class, 'index'])->name('index');

        Route::middleware('permission:hr.calendar.manage')->group(function () {
            Route::post('/', [CalendarController::class, 'store'])->name('store');
            Route::put('/{event}', [CalendarController::class, 'update'])->name('update');
            Route::delete('/{event}', [CalendarController::class, 'destroy'])->name('destroy');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.announcements.view')->prefix('announcements')->name('announcements.')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index'])->name('index');

        Route::middleware('permission:hr.announcements.manage')->group(function () {
            Route::get('/create', [AnnouncementController::class, 'create'])->name('create');
            Route::post('/', [AnnouncementController::class, 'store'])->name('store');
        });

        Route::get('/{announcement}', [AnnouncementController::class, 'show'])->name('show');
        Route::post('/{announcement}/acknowledge', [AnnouncementController::class, 'acknowledge'])->name('acknowledge');
    });

    /*
    |--------------------------------------------------------------------------
    | Exit Interviews
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.exit-interviews.view')->prefix('exit-interviews')->name('exit-interviews.')->group(function () {
        Route::get('/', [ExitInterviewController::class, 'index'])->name('index');
        Route::get('/trends', [ExitInterviewController::class, 'trends'])->name('trends');

        Route::middleware('permission:hr.exit-interviews.manage')->group(function () {
            Route::get('/create', [ExitInterviewController::class, 'create'])->name('create');
            Route::post('/', [ExitInterviewController::class, 'store'])->name('store');
        });

        Route::get('/{exitInterview}', [ExitInterviewController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Approval Workflows
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.approvals.view')->prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/pending', [ApprovalController::class, 'pending'])->name('pending');
        Route::post('/{instance}/action', [ApprovalController::class, 'action'])->name('action');
        Route::middleware('permission:hr.approvals.manage')->group(function () {
            Route::get('/chains', [ApprovalController::class, 'chains'])->name('chains');
            Route::post('/chains', [ApprovalController::class, 'storeChain'])->name('chains.store');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | E-Signatures
    |--------------------------------------------------------------------------
    */
    Route::prefix('signatures')->name('signatures.')->group(function () {
        Route::get('/pending', [ESignatureController::class, 'pending'])->name('pending');
        Route::get('/{signature}', [ESignatureController::class, 'show'])->name('show');
        Route::post('/{signature}/sign', [ESignatureController::class, 'sign'])->name('sign');
        Route::post('/{signature}/decline', [ESignatureController::class, 'decline'])->name('decline');
        Route::middleware('permission:hr.documents.manage')->group(function () {
            Route::post('/request', [ESignatureController::class, 'request'])->name('request');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Job Postings
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.recruitment.view')->prefix('job-postings')->name('job-postings.')->group(function () {
        Route::get('/', [JobPostingController::class, 'index'])->name('index');
        Route::middleware('permission:hr.recruitment.manage')->group(function () {
            Route::get('/create', [JobPostingController::class, 'create'])->name('create');
            Route::post('/', [JobPostingController::class, 'store'])->name('store');
            Route::get('/{posting}/edit', [JobPostingController::class, 'edit'])->name('edit');
            Route::put('/{posting}', [JobPostingController::class, 'update'])->name('update');
            Route::post('/{posting}/publish', [JobPostingController::class, 'publish'])->name('publish');
            Route::post('/{posting}/close', [JobPostingController::class, 'close'])->name('close');
        });
        Route::get('/{posting}', [JobPostingController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Wellbeing Dashboard
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.analytics.view')->group(function () {
        Route::get('/wellbeing', [WellbeingController::class, 'index'])->name('wellbeing.index');
    });

    /*
    |--------------------------------------------------------------------------
    | Performance Improvement Plans (PIPs)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.manage')->prefix('performance/pips')->name('performance.pips.')->group(function () {
        Route::get('/', [PipController::class, 'index'])->name('index');
        Route::get('/create', [PipController::class, 'create'])->name('create');
        Route::post('/', [PipController::class, 'store'])->name('store');
        Route::get('/{pip}', [PipController::class, 'show'])->name('show');
        Route::post('/{pip}/complete', [PipController::class, 'complete'])->name('complete');
        Route::put('/milestones/{milestone}', [PipController::class, 'updateMilestone'])->name('milestones.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Competencies
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->prefix('performance/competencies')->name('performance.competencies.')->group(function () {
        Route::get('/', [CompetencyController::class, 'index'])->name('index');
        Route::get('/profile/{profile}', [CompetencyController::class, 'employeeProfile'])->name('profile');
        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::post('/', [CompetencyController::class, 'store'])->name('store');
            Route::put('/{competency}', [CompetencyController::class, 'update'])->name('update');
            Route::post('/assess', [CompetencyController::class, 'assess'])->name('assess');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Settings — Webhooks, Custom Fields, Audit Log
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.settings.manage')->prefix('settings')->name('settings.')->group(function () {
        // Webhooks
        Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks');
        Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
        Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
        Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
        Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->name('webhooks.test');

        // Custom Fields
        Route::get('/custom-fields', [CustomFieldController::class, 'definitions'])->name('custom-fields');
        Route::post('/custom-fields', [CustomFieldController::class, 'storeDefinition'])->name('custom-fields.store');
        Route::put('/custom-fields/{definition}', [CustomFieldController::class, 'updateDefinition'])->name('custom-fields.update');
        Route::delete('/custom-fields/{definition}', [CustomFieldController::class, 'destroyDefinition'])->name('custom-fields.destroy');

        // Audit Log
        Route::get('/audit-log', [AuditController::class, 'index'])->name('audit-log');
        Route::get('/audit-log/{type}/{id}', [AuditController::class, 'show'])->name('audit-trail');
    });

    // Custom fields for employee profiles (accessible to employees managers)
    Route::middleware('permission:hr.employees.viewAny')->group(function () {
        Route::get('/employees/{profile}/custom-fields', [CustomFieldController::class, 'employeeFields'])->name('employees.custom-fields');
    });
    Route::middleware('permission:hr.employees.manage')->group(function () {
        Route::put('/employees/{profile}/custom-fields', [CustomFieldController::class, 'updateEmployeeFields'])->name('employees.custom-fields.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Succession Planning
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.manage')->prefix('succession')->name('succession.')->group(function () {
        Route::get('/', [SuccessionController::class, 'index'])->name('index');
        Route::get('/create', [SuccessionController::class, 'create'])->name('create');
        Route::post('/', [SuccessionController::class, 'store'])->name('store');
        Route::get('/{plan}', [SuccessionController::class, 'show'])->name('show');
        Route::put('/{plan}', [SuccessionController::class, 'update'])->name('update');
        Route::post('/{plan}/candidates', [SuccessionController::class, 'addCandidate'])->name('candidates.store');
        Route::put('/candidates/{candidate}', [SuccessionController::class, 'updateCandidate'])->name('candidates.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Headcount Forecasting
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.analytics.view')->group(function () {
        Route::get('/headcount', [HeadcountController::class, 'index'])->name('headcount.index');
    });

    /*
    |--------------------------------------------------------------------------
    | Bonus / Incentive Tracking
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.compensation.view')->group(function () {
        Route::get('/compensation/bonuses', [BonusController::class, 'index'])->name('compensation.bonuses');
        Route::middleware('permission:hr.compensation.manage')->group(function () {
            Route::post('/compensation/bonuses', [BonusController::class, 'store'])->name('compensation.bonuses.store');
            Route::post('/compensation/bonuses/{bonus}/approve', [BonusController::class, 'approve'])->name('compensation.bonuses.approve');
        });
        Route::get('/compensation/bonuses/{bonus}', [BonusController::class, 'show'])->name('compensation.bonuses.show');
    });
});
