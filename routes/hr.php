<?php

use App\Http\Controllers\Hr\AnalyticsDashboardController;
use App\Http\Controllers\Hr\AnnouncementController;
use App\Http\Controllers\Hr\ApprovalController;
use App\Http\Controllers\Hr\AssetController;
use App\Http\Controllers\Hr\AuditController;
use App\Http\Controllers\Hr\BenefitsController;
use App\Http\Controllers\Hr\BonusController;
use App\Http\Controllers\Hr\CalendarController;
use App\Http\Controllers\Hr\CandidateController;
use App\Http\Controllers\Hr\CompensationController;
use App\Http\Controllers\Hr\CompetencyController;
use App\Http\Controllers\Hr\ComplianceCalendarController;
use App\Http\Controllers\Hr\ComplianceController;
use App\Http\Controllers\Hr\ComplianceMatrixController;
use App\Http\Controllers\Hr\CustomFieldController;
use App\Http\Controllers\Hr\DepartmentController;
use App\Http\Controllers\Hr\DevelopmentGoalController;
use App\Http\Controllers\Hr\DirectoryController;
use App\Http\Controllers\Hr\DisciplinaryController;
use App\Http\Controllers\Hr\DriverEligibilityController;
use App\Http\Controllers\Hr\EmployeeProfileController;
use App\Http\Controllers\Hr\ESignatureController;
use App\Http\Controllers\Hr\ExitInterviewController;
use App\Http\Controllers\Hr\ExpenseController;
use App\Http\Controllers\Hr\FeedbackController;
use App\Http\Controllers\Hr\FeedController;
use App\Http\Controllers\Hr\GoalController;
use App\Http\Controllers\Hr\HeadcountController;
use App\Http\Controllers\Hr\HrAutomationController;
use App\Http\Controllers\Hr\HrCaseController;
use App\Http\Controllers\Hr\HrDocumentController;
use App\Http\Controllers\Hr\HrReportController;
use App\Http\Controllers\Hr\HrWebhookController;
use App\Http\Controllers\Hr\ICalController;
use App\Http\Controllers\Hr\ImportExportController;
use App\Http\Controllers\Hr\InterviewKitController;
use App\Http\Controllers\Hr\LeaveController;
use App\Http\Controllers\Hr\LeaveReportController;
use App\Http\Controllers\Hr\MyHrController;
use App\Http\Controllers\Hr\OffboardingController;
use App\Http\Controllers\Hr\OnboardingController;
use App\Http\Controllers\Hr\OnboardingEmailController;
use App\Http\Controllers\Hr\OrgChartController;
use App\Http\Controllers\Hr\PayrollExportController;
use App\Http\Controllers\Hr\PayslipController;
use App\Http\Controllers\Hr\PerformanceReviewController;
use App\Http\Controllers\Hr\PipController;
use App\Http\Controllers\Hr\PolicyAttestationController;
use App\Http\Controllers\Hr\PolicyController;
use App\Http\Controllers\Hr\PositionController;
use App\Http\Controllers\Hr\PublicHolidayController;
use App\Http\Controllers\Hr\RecruitmentController;
use App\Http\Controllers\Hr\RecruitmentExportController;
use App\Http\Controllers\Hr\RecruitmentJobController;
use App\Http\Controllers\Hr\ReportBuilderController;
use App\Http\Controllers\Hr\SkillsController;
use App\Http\Controllers\Hr\SuccessionController;
use App\Http\Controllers\Hr\SupervisionController;
use App\Http\Controllers\Hr\TimeTrackingController;
use App\Http\Controllers\Hr\TrainingController;
use App\Http\Controllers\Hr\TrainingDashboardController;
use App\Http\Controllers\Hr\VettingController;
use App\Http\Controllers\Hr\WellbeingController;
use Illuminate\Support\Facades\Route;

/**
 * HR Module Routes
 */
Route::middleware(['auth'])->prefix('hr')->name('hr.')->group(function () {
    // HR module landing page for shared breadcrumbs/navigation.
    Route::redirect('/', '/hr/my')->name('index');

    /*
    |--------------------------------------------------------------------------
    | My HR (Self-Service) — accessible to all authenticated users
    |--------------------------------------------------------------------------
    */
    Route::prefix('my')->name('my.')->group(function () {
        Route::get('/', [MyHrController::class, 'index'])->name('index');
        Route::get('/calendar', [MyHrController::class, 'calendar'])->name('calendar');
        Route::get('/leave', [MyHrController::class, 'leave'])->name('leave');
        Route::get('/leave/preview', [MyHrController::class, 'previewLeave'])->name('leave.preview');
        Route::post('/leave', [MyHrController::class, 'submitLeave'])->name('leave.store');
        Route::delete('/leave/{leaveRequest}', [MyHrController::class, 'cancelLeave'])->name('leave.cancel');
        Route::get('/expenses', [MyHrController::class, 'expenses'])->name('expenses');
        Route::post('/expenses', [MyHrController::class, 'submitExpense'])->name('expenses.store');
        Route::get('/training', [MyHrController::class, 'training'])->name('training');
        Route::get('/policies', [MyHrController::class, 'policies'])->name('policies');
        Route::post('/policies/{policy}/attest', [MyHrController::class, 'attestPolicy'])->name('policies.attest');
        Route::get('/profile', [MyHrController::class, 'profile'])->name('profile');
        Route::put('/profile', [MyHrController::class, 'updateProfile'])->name('profile.update');
        Route::get('/directory', [MyHrController::class, 'directory'])->name('directory');
        Route::get('/reviews', [MyHrController::class, 'reviews'])->name('reviews');
        Route::put('/reviews/{review}', [MyHrController::class, 'updateReview'])->name('reviews.update');
        Route::get('/goals', [MyHrController::class, 'goals'])->name('goals');
        Route::put('/goals/{goal}', [MyHrController::class, 'updateGoal'])->name('goals.update');
        Route::get('/surveys', [MyHrController::class, 'surveys'])->name('surveys');
        Route::post('/surveys/{survey}', [MyHrController::class, 'submitSurvey'])->name('surveys.submit');

        Route::get('/time', [MyHrController::class, 'time'])->name('time');
        Route::post('/time/clock-in', [MyHrController::class, 'clockIn'])->name('time.clock-in');
        Route::post('/time/clock-out', [MyHrController::class, 'clockOut'])->name('time.clock-out');
        Route::get('/time/shifts/{shift}/calendar', [MyHrController::class, 'shiftCalendar'])->name('time.shift-calendar');
        Route::get('/one', [MyHrController::class, 'one'])->name('one');
        Route::post('/one/{note}/acknowledge', [MyHrController::class, 'acknowledgeOne'])->name('one.acknowledge');
        Route::post('/kudos', [MyHrController::class, 'sendKudos'])->name('kudos');
        Route::get('/shoutouts', [MyHrController::class, 'shoutouts'])->name('shoutouts');
        Route::post('/kudos/{kudos}/react', [MyHrController::class, 'reactKudos'])->name('kudos.react');
        Route::post('/kudos/{kudos}/reply', [MyHrController::class, 'replyKudos'])->name('kudos.reply');
        Route::get('/documents', [MyHrController::class, 'documents'])->name('documents');
        Route::get('/documents/{document}/download', [MyHrController::class, 'downloadDocument'])->name('documents.download');
        Route::post('/documents/sign/{signature}', [MyHrController::class, 'signDocument'])->name('documents.sign');
    });

    /*
    |--------------------------------------------------------------------------
    | Recruitment
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.recruitment.view')->group(function () {
        Route::get('/recruitment', [RecruitmentController::class, 'index'])->name('recruitment.index');
        Route::get('/recruitment/candidates', [RecruitmentController::class, 'index'])->name('candidates.index');
        Route::get('/recruitment/export', [RecruitmentExportController::class, 'export'])->name('recruitment.export');
        // Retired standalone tab pages — collapsed into the unified Recruitment hub.
        Route::get('/recruitment/kanban', fn () => redirect()->route('hr.recruitment.index', ['tab' => 'board']))->name('recruitment.kanban');
        Route::get('/recruitment/analytics', fn () => redirect()->route('hr.recruitment.index', ['tab' => 'analytics']))->name('recruitment.analytics');
        Route::get('/recruitment/jobs', fn () => redirect()->route('hr.recruitment.index', ['tab' => 'requisitions']))->name('jobs.index');
        Route::get('/recruitment/kits', fn () => redirect()->route('hr.recruitment.index', ['tab' => 'kits']))->name('kits.index');

        Route::get('/recruitment/candidates/create', [CandidateController::class, 'create'])->name('candidates.create')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/candidates', [CandidateController::class, 'store'])->name('candidates.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::get('/recruitment/candidates/{candidate}', [CandidateController::class, 'show'])->name('candidates.show');
        Route::put('/recruitment/candidates/{candidate}', [CandidateController::class, 'update'])->name('candidates.update')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/applications/{application}/advance', [CandidateController::class, 'advanceApplication'])->name('applications.advance')
            ->middleware('permission:hr.recruitment.manage');

        // Interviews
        Route::post('/recruitment/applications/{application}/interviews', [CandidateController::class, 'storeInterview'])->name('interviews.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::put('/recruitment/interviews/{interview}', [CandidateController::class, 'updateInterview'])->name('interviews.update')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/interviews/{interview}/score', [CandidateController::class, 'scoreInterview'])->name('interviews.score')
            ->middleware('permission:hr.recruitment.manage');

        // Reference Checks
        Route::post('/recruitment/applications/{application}/references', [CandidateController::class, 'storeReference'])->name('references.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::put('/recruitment/references/{reference}', [CandidateController::class, 'updateReference'])->name('references.update')
            ->middleware('permission:hr.recruitment.manage');

        // Application Actions
        Route::post('/recruitment/applications/{application}/reject', [CandidateController::class, 'rejectApplication'])->name('applications.reject')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/applications/bulk', [CandidateController::class, 'bulkAction'])->name('applications.bulk')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/candidates/bulk-email', [CandidateController::class, 'bulkEmail'])->name('candidates.bulk-email')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/email-templates', [CandidateController::class, 'storeEmailTemplate'])->name('email-templates.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::delete('/recruitment/email-templates/{template}', [CandidateController::class, 'destroyEmailTemplate'])->name('email-templates.destroy')
            ->middleware('permission:hr.recruitment.manage');

        // Talent pool
        Route::post('/recruitment/candidates/{candidate}/pool', [CandidateController::class, 'addToPool'])->name('pool.add')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/candidates/{candidate}/reactivate', [CandidateController::class, 'reactivatePool'])->name('pool.reactivate')
            ->middleware('permission:hr.recruitment.manage');

        // Candidate Documents
        Route::post('/recruitment/candidates/{candidate}/documents', [CandidateController::class, 'storeDocument'])->name('candidate.documents.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::get('/recruitment/documents/{document}/download', [CandidateController::class, 'downloadDocument'])->name('candidate.documents.download');
        Route::delete('/recruitment/documents/{document}', [CandidateController::class, 'destroyDocument'])->name('candidate.documents.destroy')
            ->middleware('permission:hr.recruitment.manage');

        // Offers
        Route::get('/recruitment/applications/{application}/offer/create', [CandidateController::class, 'createOffer'])->name('offers.create')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/offers', [CandidateController::class, 'storeOffer'])->name('offers.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/offers/{offer}/send', [CandidateController::class, 'sendOffer'])->name('offers.send')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/offers/{offer}/resend', [CandidateController::class, 'resendOffer'])->name('offers.resend')
            ->middleware('permission:hr.recruitment.manage');
        Route::get('/recruitment/offers/{offer}/letter', [CandidateController::class, 'downloadOfferLetter'])->name('offers.letter');
        Route::post('/recruitment/offers/{offer}/approve', [CandidateController::class, 'approveOffer'])->name('offers.approve')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/offers/{offer}/respond', [CandidateController::class, 'respondOffer'])->name('offers.respond')
            ->middleware('permission:hr.recruitment.manage');
        // Segregation of duties: minting a login account additionally requires hr.employees.manage.
        Route::post('/recruitment/offers/{offer}/convert', [CandidateController::class, 'convertToEmployee'])->name('offers.convert')
            ->middleware('permission:hr.recruitment.manage', 'permission:hr.employees.manage');

        // Jobs & ATS setup
        Route::post('/recruitment/jobs', [RecruitmentJobController::class, 'store'])->name('jobs.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::put('/recruitment/jobs/{job}', [RecruitmentJobController::class, 'update'])->name('jobs.update')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/jobs/{job}/publish', [RecruitmentJobController::class, 'publish'])->name('jobs.publish')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/jobs/{job}/submit-approval', [RecruitmentJobController::class, 'submitForApproval'])->name('jobs.submit-approval')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/jobs/{job}/approve', [RecruitmentJobController::class, 'approve'])->name('jobs.approve')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/jobs/{job}/reject-approval', [RecruitmentJobController::class, 'rejectApproval'])->name('jobs.reject-approval')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/jobs/{job}/close', [RecruitmentJobController::class, 'close'])->name('jobs.close')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/jobs/{job}/sync-posting', [RecruitmentJobController::class, 'syncPosting'])->name('jobs.sync-posting')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/jobs/{job}/unpublish-posting', [RecruitmentJobController::class, 'unpublishPosting'])->name('jobs.unpublish-posting')
            ->middleware('permission:hr.recruitment.manage');

        // Interview kits
        Route::post('/recruitment/kits', [InterviewKitController::class, 'store'])->name('kits.store')
            ->middleware('permission:hr.recruitment.manage');
        Route::put('/recruitment/kits/{kit}', [InterviewKitController::class, 'update'])->name('kits.update')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/kits/{kit}/toggle-active', [InterviewKitController::class, 'toggleActive'])->name('kits.toggleActive')
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

        Route::get('/people/{profile}/documents', [HrDocumentController::class, 'profileDocuments'])->name('people.documents');
        Route::get('/people/{profile}/documents/{document}/download', [HrDocumentController::class, 'download'])->name('people.documents.download');

        Route::middleware('permission:hr.employees.manage')->group(function () {
            Route::post('/people', [EmployeeProfileController::class, 'store'])->name('people.store');
            Route::post('/people/bulk', [EmployeeProfileController::class, 'bulkAction'])->name('people.bulk');
            Route::get('/people/{profile}/edit', [EmployeeProfileController::class, 'edit'])->name('people.edit');
            Route::put('/people/{profile}', [EmployeeProfileController::class, 'update'])->name('people.update');
            Route::patch('/people/{profile}/active', [EmployeeProfileController::class, 'setActive'])->name('people.active');
            Route::post('/people/{profile}/invite', [EmployeeProfileController::class, 'resendInvite'])->name('people.invite');
            Route::post('/people/{profile}/documents', [HrDocumentController::class, 'storeForProfile'])->name('people.documents.store');
            Route::put('/people/{profile}/documents/{document}', [HrDocumentController::class, 'updateForProfile'])->name('people.documents.update');
            Route::delete('/people/{profile}/documents/{document}', [HrDocumentController::class, 'destroyForProfile'])->name('people.documents.destroy');
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
        Route::get('/compliance/staff/{staff}', [ComplianceController::class, 'staffDetail'])->name('compliance.staff');

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
    Route::middleware('permission:hr.training.view|training.viewAny')->group(function () {
        Route::get('/training', [TrainingDashboardController::class, 'index'])->name('training.index');
    });

    /*
    |--------------------------------------------------------------------------
    | Vetting Register
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.vetting.view')->group(function () {
        Route::get('/compliance/vetting', [VettingController::class, 'index'])->name('vetting.index');

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

        Route::get('/compliance/vetting/{check}', [VettingController::class, 'show'])->name('vetting.show');
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
        Route::get('/leave/preview', [LeaveController::class, 'previewLeave'])->name('leave.preview');
        Route::get('/leave/balances', [LeaveController::class, 'balances'])->name('leave.balances');
        Route::get('/leave/balances/{user}/ledger', [LeaveController::class, 'ledger'])->name('leave.balances.ledger');
        Route::get('/leave/reports', [LeaveReportController::class, 'index'])->name('leave.reports');
        Route::get('/leave/holidays', [PublicHolidayController::class, 'index'])->name('leave.holidays.index');

        Route::middleware('permission:hr.leave.manage')->group(function () {
            Route::get('/leave/create', [LeaveController::class, 'create'])->name('leave.create');
            Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
            Route::post('/leave/balances/adjust', [LeaveController::class, 'adjustBalance'])->name('leave.balances.adjust');
            Route::get('/leave/export', [LeaveController::class, 'export'])->name('leave.export');
            Route::get('/leave/balances/export', [LeaveController::class, 'exportBalances'])->name('leave.balances.export');
            Route::get('/leave/reports/export', [LeaveReportController::class, 'export'])->name('leave.reports.export');
            Route::post('/leave/holidays', [PublicHolidayController::class, 'store'])->name('leave.holidays.store');
            Route::put('/leave/holidays/{holiday}', [PublicHolidayController::class, 'update'])->name('leave.holidays.update');
            Route::delete('/leave/holidays/{holiday}', [PublicHolidayController::class, 'destroy'])->name('leave.holidays.destroy');
        });

        Route::middleware('permission:hr.leave.approve|hr.leave.manage')->group(function () {
            Route::get('/leave/{leaveRequest}', [LeaveController::class, 'show'])->name('leave.show');
            Route::post('/leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
            Route::post('/leave/{leaveRequest}/decline', [LeaveController::class, 'decline'])->name('leave.decline');
            Route::post('/leave/bulk-approve', [LeaveController::class, 'bulkApprove'])->name('leave.bulk-approve');
            Route::post('/leave/bulk-decline', [LeaveController::class, 'bulkDecline'])->name('leave.bulk-decline');
            Route::post('/leave/escalate-now', [LeaveController::class, 'escalateNow'])->name('leave.escalate-now');
            Route::post('/leave/{leaveRequest}/sla-due', [LeaveController::class, 'setSlaDue'])->name('leave.sla-due');
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
        });

        // Onboarding Email Templates (must be above {checklist} wildcard)
        Route::get('/onboarding/emails', [OnboardingEmailController::class, 'index'])->name('onboarding.emails.index');
        Route::get('/onboarding/emails/log', [OnboardingEmailController::class, 'log'])->name('onboarding.emails.log');
        Route::get('/onboarding/emails/{email}/preview', [OnboardingEmailController::class, 'preview'])->name('onboarding.emails.preview');

        Route::middleware('permission:hr.onboarding.manage')->group(function () {
            Route::post('/onboarding/emails', [OnboardingEmailController::class, 'store'])->name('onboarding.emails.store');
            Route::put('/onboarding/emails/{email}', [OnboardingEmailController::class, 'update'])->name('onboarding.emails.update');
            Route::delete('/onboarding/emails/{email}', [OnboardingEmailController::class, 'destroy'])->name('onboarding.emails.destroy');
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
        Route::get('/disciplinary/{action}/edit', [DisciplinaryController::class, 'edit'])->name('disciplinary.edit')
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
    Route::middleware('permission:hr.policies.view')->prefix('documents/policies')->name('policies.')->group(function () {
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
        Route::get('/documents/export', [HrDocumentController::class, 'export'])->name('documents.export');
        Route::get('/documents/{document}/download', [HrDocumentController::class, 'download'])->name('documents.download');
        Route::get('/documents/{document}/signed', [HrDocumentController::class, 'downloadSigned'])->name('documents.signed');

        Route::middleware('permission:hr.documents.manage')->group(function () {
            Route::get('/documents/upload', [HrDocumentController::class, 'createUpload'])->name('documents.upload');
            Route::post('/documents', [HrDocumentController::class, 'store'])->name('documents.store');
            Route::post('/documents/generate', [HrDocumentController::class, 'generate'])->name('documents.generate');
            Route::post('/documents/preview', [HrDocumentController::class, 'preview'])->name('documents.preview');
            Route::get('/documents/bulk-download', [HrDocumentController::class, 'bulkDownload'])->name('documents.bulk-download');
            Route::post('/documents/bulk-delete', [HrDocumentController::class, 'bulkDestroy'])->name('documents.bulk-delete');
            Route::post('/documents/move', [HrDocumentController::class, 'move'])->name('documents.move');
            Route::put('/documents/{document}', [HrDocumentController::class, 'update'])->name('documents.update');
            Route::delete('/documents/{document}', [HrDocumentController::class, 'destroy'])->name('documents.destroy');
            Route::get('/documents/templates', [HrDocumentController::class, 'templates'])->name('documents.templates');
            Route::get('/documents/templates/create', [HrDocumentController::class, 'createTemplate'])->name('documents.templates.create');
            Route::post('/documents/templates', [HrDocumentController::class, 'storeTemplate'])->name('documents.templates.store');
            Route::get('/documents/templates/{template}/edit', [HrDocumentController::class, 'editTemplate'])->name('documents.templates.edit');
            Route::put('/documents/templates/{template}', [HrDocumentController::class, 'updateTemplate'])->name('documents.templates.update');
            Route::post('/documents/templates/{template}/toggle-active', [HrDocumentController::class, 'toggleTemplateActive'])->name('documents.templates.toggleActive');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Offboarding
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.onboarding.view')->prefix('offboarding')->name('offboarding.')->group(function () {
        Route::get('/', [OffboardingController::class, 'index'])->name('index');

        Route::middleware('permission:hr.onboarding.manage')->group(function () {
            Route::get('/create', [OffboardingController::class, 'create'])->name('create');
            Route::post('/', [OffboardingController::class, 'store'])->name('store');
            Route::post('/tasks/{task}/complete', [OffboardingController::class, 'completeTask'])->name('tasks.complete');
        });

        Route::get('/{checklist}', [OffboardingController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Wellbeing & Engagement
    |--------------------------------------------------------------------------
    */
    Route::prefix('wellbeing')->name('wellbeing.')->group(function () {
        Route::get('/', [WellbeingController::class, 'index'])->name('index')
            ->middleware('permission:hr.wellbeing.view');
        Route::get('/surveys/{survey}', [WellbeingController::class, 'showSurvey'])->name('surveys.show');
        Route::post('/surveys/{survey}/responses', [WellbeingController::class, 'submitResponse'])->name('surveys.responses.store');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::post('/surveys', [WellbeingController::class, 'storeSurvey'])->name('surveys.store');
            Route::put('/surveys/{survey}', [WellbeingController::class, 'updateSurvey'])->name('surveys.update');
            Route::post('/surveys/{survey}/publish', [WellbeingController::class, 'publishSurvey'])->name('surveys.publish');
            Route::post('/surveys/{survey}/close', [WellbeingController::class, 'closeSurvey'])->name('surveys.close');
            Route::post('/surveys/{survey}/action-plans', [WellbeingController::class, 'storeActionPlan'])->name('action-plans.store');
        });

        Route::put('/action-plans/{plan}', [WellbeingController::class, 'updateActionPlan'])->name('action-plans.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Development Goals (folded under the Goals & OKRs hub at /hr/goals/development)
    |--------------------------------------------------------------------------
    */
    Route::prefix('goals/development')->name('development.')->group(function () {
        Route::get('/', [DevelopmentGoalController::class, 'index'])->name('goals.index');
        Route::put('/{goal}', [DevelopmentGoalController::class, 'update'])->name('goals.update');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::post('/', [DevelopmentGoalController::class, 'store'])->name('goals.store');
            Route::delete('/{goal}', [DevelopmentGoalController::class, 'destroy'])->name('goals.destroy');
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
            Route::post('/payroll/runs/{run}/pay', [PayrollExportController::class, 'payNet'])->name('payroll.runs.pay');
            Route::post('/payroll/runs/{run}/export', [PayrollExportController::class, 'export'])->name('payroll.runs.export');
            Route::get('/payroll/runs/{run}/net-pay-file', [PayrollExportController::class, 'downloadNetPayFile'])->name('payroll.runs.net-pay-file');
            Route::post('/payroll/export-profiles', [PayrollExportController::class, 'storeProfile'])->name('payroll.profiles.store');
            Route::put('/payroll/export-profiles/{profile}', [PayrollExportController::class, 'updateProfile'])->name('payroll.profiles.update');
            Route::post('/payroll/export-profiles/{profile}/set-default', [PayrollExportController::class, 'setDefaultProfile'])->name('payroll.profiles.set-default');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | HR Reports
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.reports.view')->group(function () {
        Route::get('/reports', [HrReportController::class, 'index'])->name('reports.index');
        Route::match(['get', 'post'], '/reports/generate', [HrReportController::class, 'generate'])->name('reports.generate');
        Route::get('/reports/exports/{export}', [HrReportController::class, 'showExport'])->name('reports.exports.show');

        Route::middleware('permission:hr.reports.export')->group(function () {
            Route::match(['get', 'post'], '/reports/export', [HrReportController::class, 'export'])->name('reports.export');
            Route::get('/reports/exports/{export}/download', [HrReportController::class, 'downloadExport'])->name('reports.exports.download');
            Route::post('/reports/subscriptions', [HrReportController::class, 'storeSubscription'])->name('reports.subscriptions.store');
            Route::put('/reports/subscriptions/{subscription}', [HrReportController::class, 'updateSubscription'])->name('reports.subscriptions.update');
            Route::post('/reports/subscriptions/{subscription}/toggle-active', [HrReportController::class, 'toggleSubscription'])->name('reports.subscriptions.toggleActive');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Directory
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.employees.viewAny')->group(function () {
        Route::get('/directory', [DirectoryController::class, 'index'])->name('directory.index');
        Route::post('/directory/{profile}/photo', [DirectoryController::class, 'uploadPhoto'])->name('directory.uploadPhoto')
            ->middleware('permission:hr.employees.manage');
    });

    // Directory card detail (JSON) powering the self-service My HR directory
    // modal — available to all staff (gated to staff in the controller).
    // Personal contact and the compliance summary stay manager-only.
    Route::get('/directory/{profile}', [DirectoryController::class, 'show'])->name('directory.show');

    /*
    |--------------------------------------------------------------------------
    | Import / Export
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
    | Positions
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.employees.viewAny')->prefix('positions')->name('positions.')->group(function () {
        Route::get('/', [PositionController::class, 'index'])->name('index');

        Route::middleware('permission:hr.employees.manage')->group(function () {
            Route::get('/create', [PositionController::class, 'create'])->name('create');
            Route::post('/', [PositionController::class, 'store'])->name('store');
            Route::get('/{position}/edit', [PositionController::class, 'edit'])->name('edit');
            Route::put('/{position}', [PositionController::class, 'update'])->name('update');
        });

        Route::get('/{position}', [PositionController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Org Chart
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.employees.viewAny')->group(function () {
        Route::get('/orgchart', [OrgChartController::class, 'index'])->name('orgchart.index');
        Route::put('/orgchart/{profile}', [OrgChartController::class, 'update'])->name('orgchart.update')
            ->middleware('permission:hr.employees.manage');
    });

    /*
    |--------------------------------------------------------------------------
    | Job Postings (retired)
    |--------------------------------------------------------------------------
    | The standalone HrJobPosting authoring surface was consolidated onto
    | requisitions and removed. Keep the index path alive as a redirect (never
    | 404) so bookmarks + the route('hr.job-postings.index') helper still resolve.
    */
    Route::middleware('permission:hr.recruitment.view')
        ->get('/job-postings', fn () => redirect()->route('hr.jobs.index'))
        ->name('job-postings.index');

    /*
    |--------------------------------------------------------------------------
    | Compensation
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.compensation.view')->prefix('compensation')->name('compensation.')->group(function () {
        Route::get('/bands', [CompensationController::class, 'bands'])->name('bands');
        Route::get('/bands/export', [CompensationController::class, 'exportBands'])->name('bands.export');
        Route::get('/reviews', [CompensationController::class, 'reviews'])->name('reviews');
        Route::get('/history', [CompensationController::class, 'historyIndex'])->name('history.index');
        Route::get('/history/{profile}', [CompensationController::class, 'history'])->name('history');
        Route::get('/settings', [CompensationController::class, 'settings'])->name('settings');
        Route::get('/bonuses', [BonusController::class, 'index'])->name('bonuses');

        Route::middleware('permission:hr.compensation.manage')->group(function () {
            Route::post('/bands', [CompensationController::class, 'storeBand'])->name('bands.store');
            Route::put('/bands/{band}', [CompensationController::class, 'updateBand'])->name('bands.update');
            Route::get('/reviews/create', [CompensationController::class, 'createReview'])->name('reviews.create');
            Route::post('/reviews', [CompensationController::class, 'storeReview'])->name('reviews.store');
            Route::get('/reviews/{review}', [CompensationController::class, 'showReview'])->name('reviews.show');
            Route::post('/reviews/{review}/approve', [CompensationController::class, 'approveReview'])->name('reviews.approve');
            Route::post('/reviews/{review}/apply', [CompensationController::class, 'applyReview'])->name('reviews.apply');
            Route::post('/reviews/{review}/items/{item}/approve', [CompensationController::class, 'approveReviewItem'])->name('reviews.items.approve');
            Route::post('/reviews/{review}/items/{item}/reject', [CompensationController::class, 'rejectReviewItem'])->name('reviews.items.reject');
            Route::post('/bonuses', [BonusController::class, 'store'])->name('bonuses.store');
            Route::post('/bonuses/{bonus}/approve', [BonusController::class, 'approve'])->name('bonuses.approve');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Benefits (folded into the Compensation & Benefits hub)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.benefits.view')->prefix('compensation/benefits')->name('compensation.benefits.')->group(function () {
        Route::get('/', [BenefitsController::class, 'index'])->name('index');
        Route::get('/plans', [BenefitsController::class, 'plans'])->name('plans');

        Route::middleware('permission:hr.benefits.manage')->group(function () {
            Route::post('/plans', [BenefitsController::class, 'storePlan'])->name('plans.store');
            Route::put('/plans/{plan}', [BenefitsController::class, 'updatePlan'])->name('plans.update');
            Route::post('/enroll', [BenefitsController::class, 'enroll'])->name('enroll');
            Route::put('/enrollments/{enrollment}', [BenefitsController::class, 'updateEnrollment'])->name('enrollments.update');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Expenses (folded into the Compensation & Benefits hub)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.expenses.view')->prefix('compensation/expenses')->name('compensation.expenses.')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::get('/{expenseClaim}/items/{item}/receipt', [ExpenseController::class, 'downloadReceipt'])->name('receipt');

        Route::middleware('permission:hr.expenses.manage')->group(function () {
            Route::get('/create', [ExpenseController::class, 'create'])->name('create');
            Route::post('/', [ExpenseController::class, 'store'])->name('store');
            Route::post('/{expenseClaim}/submit', [ExpenseController::class, 'submit'])->name('submit');
        });

        Route::middleware('permission:hr.expenses.approve')->group(function () {
            Route::post('/bulk-approve', [ExpenseController::class, 'bulkApprove'])->name('bulk-approve');
            Route::post('/{expenseClaim}/approve', [ExpenseController::class, 'approve'])->name('approve');
            Route::post('/{expenseClaim}/reject', [ExpenseController::class, 'reject'])->name('reject');
            Route::post('/{expenseClaim}/pay', [ExpenseController::class, 'pay'])->name('pay');
        });

        Route::get('/{expenseClaim}', [ExpenseController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | 360 Feedback
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->prefix('feedback')->name('feedback.')->group(function () {
        Route::get('/', [FeedbackController::class, 'index'])->name('index');
        Route::get('/request', [FeedbackController::class, 'request'])->name('request');
        Route::post('/request', [FeedbackController::class, 'storeRequest'])->name('request.store');
        Route::get('/{feedbackRequest}/respond', [FeedbackController::class, 'respond'])->name('respond');
        Route::post('/{feedbackRequest}/respond', [FeedbackController::class, 'submitResponse'])->name('respond.store');
        Route::get('/summary/{user}', [FeedbackController::class, 'summary'])->name('summary');
        Route::post('/templates', [FeedbackController::class, 'storeTemplate'])->name('templates.store');
        Route::put('/templates/{template}', [FeedbackController::class, 'updateTemplate'])->name('templates.update');
        Route::delete('/templates/{template}', [FeedbackController::class, 'deleteTemplate'])->name('templates.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Competencies
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->group(function () {
        Route::get('/performance/competencies', [CompetencyController::class, 'index'])->name('competencies.index');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::get('/performance/competencies/assess', [CompetencyController::class, 'createAssessment'])->name('competencies.assess.create');
            Route::post('/performance/competencies', [CompetencyController::class, 'store'])->name('competencies.store');
            Route::put('/performance/competencies/{competency}', [CompetencyController::class, 'update'])->name('competencies.update');
            Route::post('/performance/competencies/assess', [CompetencyController::class, 'assess'])->name('competencies.assess');
        });

        Route::get('/performance/competencies/{profile}', [CompetencyController::class, 'employeeProfile'])->name('competencies.profile');
    });

    /*
    |--------------------------------------------------------------------------
    | PIPs (Performance Improvement Plans)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->prefix('performance/pips')->name('pips.')->group(function () {
        Route::get('/', [PipController::class, 'index'])->name('index');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::get('/create', [PipController::class, 'create'])->name('create');
            Route::post('/', [PipController::class, 'store'])->name('store');
            Route::put('/milestones/{milestone}', [PipController::class, 'updateMilestone'])->name('milestones.update');
            Route::post('/{pip}/complete', [PipController::class, 'complete'])->name('complete');
        });

        Route::get('/{pip}', [PipController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Succession Planning
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->prefix('succession')->name('succession.')->group(function () {
        Route::get('/', [SuccessionController::class, 'index'])->name('index');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::get('/create', [SuccessionController::class, 'create'])->name('create');
            Route::post('/', [SuccessionController::class, 'store'])->name('store');
            Route::put('/{plan}', [SuccessionController::class, 'update'])->name('update');
            Route::post('/{plan}/candidates', [SuccessionController::class, 'addCandidate'])->name('candidates.store');
            Route::put('/candidates/{candidate}', [SuccessionController::class, 'updateCandidate'])->name('candidates.update');
        });

        Route::get('/{plan}', [SuccessionController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Goals & OKRs
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->prefix('goals')->name('goals.')->group(function () {
        Route::get('/', [GoalController::class, 'index'])->name('index');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::get('/create', [GoalController::class, 'create'])->name('create');
            Route::post('/', [GoalController::class, 'store'])->name('store');
            Route::put('/{goal}', [GoalController::class, 'update'])->name('update');
            Route::delete('/{goal}', [GoalController::class, 'destroy'])->name('destroy');
            Route::post('/{goal}/progress', [GoalController::class, 'updateProgress'])->name('progress');

            // Key Results
            Route::post('/{goal}/key-results', [GoalController::class, 'storeKeyResult'])->name('key-results.store');
            Route::put('/key-results/{keyResult}', [GoalController::class, 'updateKeyResult'])->name('key-results.update');
            Route::delete('/key-results/{keyResult}', [GoalController::class, 'destroyKeyResult'])->name('key-results.destroy');
        });

        Route::get('/{goal}', [GoalController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Community Feed
    |--------------------------------------------------------------------------
    */
    Route::prefix('feed')->name('feed.')->group(function () {
        Route::get('/', [FeedController::class, 'index'])
            ->middleware('permission:hr.recognition.view')->name('index');

        // Post image attachments — viewable by anyone who can read the feed.
        Route::get('/attachments/{attachment}', [FeedController::class, 'downloadAttachment'])
            ->middleware('permission:hr.recognition.view')->name('attachments.show');

        // Mutations require the give permission (was previously ungated).
        Route::middleware('permission:hr.recognition.give')->group(function () {
            Route::post('/', [FeedController::class, 'store'])->name('store');
            Route::post('/kudos', [FeedController::class, 'sendKudos'])->name('kudos');
            // Feed-scoped react/reply aliases onto the shared HrKudos path.
            Route::post('/kudos/{kudos}/react', [FeedController::class, 'react'])->name('kudos.react');
            Route::post('/kudos/{kudos}/reply', [FeedController::class, 'reply'])->name('kudos.reply');
            // Polymorphic react/reply for the wall's non-kudos items (posts + announcements).
            Route::post('/react', [FeedController::class, 'reactFeed'])->name('react');
            Route::post('/reply', [FeedController::class, 'replyFeed'])->name('reply');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Surveys (RETIRED — superseded by the Wellbeing engagement-survey system)
    |--------------------------------------------------------------------------
    | The standalone HrSurvey module was retired (S11): the Wellbeing system
    | covers anonymity, scoring, eNPS, action plans + SLA reminders. The routes
    | are kept alive as redirects so bookmarks + route() helpers still resolve;
    | route names are preserved.
    */
    Route::prefix('surveys')->name('surveys.')->group(function () {
        Route::redirect('/', '/hr/wellbeing')->name('index');
        Route::redirect('/create', '/hr/wellbeing')->name('create');
        Route::redirect('/{survey}/respond', '/hr/wellbeing')->name('respond');
        Route::redirect('/{survey}', '/hr/wellbeing')->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    */
    Route::prefix('announcements')->name('announcements.')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index'])->name('index');

        Route::middleware('permission:hr.announcements.manage')->group(function () {
            Route::get('/create', [AnnouncementController::class, 'create'])->name('create');
            Route::post('/', [AnnouncementController::class, 'store'])->name('store');
        });

        Route::post('/{announcement}/acknowledge', [AnnouncementController::class, 'acknowledge'])->name('acknowledge');
        Route::get('/{announcement}', [AnnouncementController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Skills Matrix
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->prefix('performance/skills')->name('performance.skills.')->group(function () {
        Route::get('/', [SkillsController::class, 'index'])->name('index');
        Route::get('/matrix', [SkillsController::class, 'matrix'])->name('matrix');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::post('/', [SkillsController::class, 'storeSkill'])->name('store');
            Route::post('/assess', [SkillsController::class, 'assessEmployee'])->name('assess');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Analytics & Headcount
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.analytics.view')->group(function () {
        Route::get('/analytics', [AnalyticsDashboardController::class, 'index'])->name('analytics.index');
        Route::get('/headcount', [HeadcountController::class, 'index'])->name('headcount.index');
    });

    /*
    |--------------------------------------------------------------------------
    | Calendar
    |--------------------------------------------------------------------------
    */
    Route::prefix('calendar')->name('calendar.')->group(function () {
        Route::get('/', [CalendarController::class, 'index'])->name('index');
        Route::get('/feed', [CalendarController::class, 'feed'])->name('feed');
        Route::post('/events', [CalendarController::class, 'store'])->name('events.store');
        Route::put('/events/{event}', [CalendarController::class, 'update'])->name('events.update');
        Route::delete('/events/{event}', [CalendarController::class, 'destroy'])->name('events.destroy');
        Route::post('/events/{event}/rsvp', [CalendarController::class, 'rsvp'])->name('events.rsvp');
        Route::post('/events/{event}/attachments', [CalendarController::class, 'storeAttachment'])->name('events.attachments.store');
        Route::delete('/attachments/{attachment}', [CalendarController::class, 'destroyAttachment'])->name('attachments.destroy');
        Route::get('/attachments/{attachment}/download', [CalendarController::class, 'downloadAttachment'])->name('attachments.download');
    });

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.assets.view')->prefix('assets')->name('assets.')->group(function () {
        Route::get('/', [AssetController::class, 'index'])->name('index');

        Route::middleware('permission:hr.assets.manage')->group(function () {
            Route::get('/create', [AssetController::class, 'create'])->name('create');
            Route::post('/', [AssetController::class, 'store'])->name('store');
            Route::post('/{asset}/assign', [AssetController::class, 'assign'])->name('assign');
            Route::post('/assignments/{assignment}/return', [AssetController::class, 'returnAsset'])->name('assignments.return');
            Route::post('/{asset}/maintenance', [AssetController::class, 'sendToMaintenance'])->name('maintenance');
            Route::post('/{asset}/return-from-maintenance', [AssetController::class, 'returnFromMaintenance'])->name('return-from-maintenance');
            Route::post('/{asset}/retire', [AssetController::class, 'retire'])->name('retire');
        });

        Route::get('/{asset}', [AssetController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.approvals.view')->prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/pending', [ApprovalController::class, 'pending'])->name('pending');
        Route::get('/chains', [ApprovalController::class, 'chains'])->name('chains');

        Route::middleware('permission:hr.approvals.manage')->group(function () {
            Route::post('/chains', [ApprovalController::class, 'storeChain'])->name('chains.store');
            Route::post('/{instance}/action', [ApprovalController::class, 'action'])->name('action');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | E-Signatures
    |--------------------------------------------------------------------------
    */
    Route::prefix('signatures')->name('signatures.')->group(function () {
        Route::get('/pending', [ESignatureController::class, 'pending'])->name('pending');
        Route::get('/{signature}/document', [ESignatureController::class, 'downloadDocument'])->name('document');
        Route::get('/{signature}', [ESignatureController::class, 'show'])->name('show');
        Route::post('/{signature}/sign', [ESignatureController::class, 'sign'])->name('sign');
        Route::post('/{signature}/decline', [ESignatureController::class, 'decline'])->name('decline');
        Route::middleware('permission:hr.signatures.manage|hr.documents.manage')->group(function () {
            Route::post('/request', [ESignatureController::class, 'request'])->name('request');
            Route::post('/{signature}/nudge', [ESignatureController::class, 'nudge'])->name('nudge');
            Route::post('/{signature}/resend', [ESignatureController::class, 'resend'])->name('resend');
            Route::post('/document/{document}/cancel', [ESignatureController::class, 'cancel'])->name('cancel');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Payslips
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.payslips.view')->group(function () {
        Route::get('/payroll/payslips', [PayslipController::class, 'index'])->name('payslips.index');
        Route::get('/payroll/payslips/{payslip}', [PayslipController::class, 'show'])->name('payslips.show');
        Route::get('/payroll/payslips/{payslip}/download', [PayslipController::class, 'download'])->name('payslips.download');

        Route::middleware('permission:hr.payslips.generate')->group(function () {
            Route::post('/payroll/payslips/generate', [PayslipController::class, 'generate'])->name('payslips.generate');
        });
    });

    // My Payslips (self-service) — owner-authorised in the controller, so these
    // are NOT behind hr.payslips.view (which only HR/admin hold).
    Route::get('/my/payslips', [PayslipController::class, 'myPayslips'])->name('my.payslips');
    Route::get('/my/payslips/{payslip}', [PayslipController::class, 'show'])->name('my.payslips.show');
    Route::get('/my/payslips/{payslip}/download', [PayslipController::class, 'download'])->name('my.payslips.download');

    /*
    |--------------------------------------------------------------------------
    | Exit Interviews
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.exit-interviews.view|hr.exit-interviews.manage')->prefix('exit-interviews')->name('exit-interviews.')->group(function () {
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
    | Time Tracking
    |--------------------------------------------------------------------------
    */
    Route::prefix('time')->name('time.')->middleware('permission:timesheets.viewAny')->group(function () {
        Route::get('/', [TimeTrackingController::class, 'index'])->name('index');
        Route::post('/clock-in', [TimeTrackingController::class, 'clockIn'])->name('clock-in');
        Route::post('/clock-out', [TimeTrackingController::class, 'clockOut'])->name('clock-out');
        Route::post('/entries', [TimeTrackingController::class, 'store'])->name('entries.store');
        Route::get('/timesheets', [TimeTrackingController::class, 'timesheets'])->name('timesheets');
        Route::get('/export', [TimeTrackingController::class, 'export'])->name('export');
        Route::get('/report/pdf', [TimeTrackingController::class, 'reportPdf'])->name('report.pdf');

        Route::middleware('permission:timesheets.manageAny|timesheets.approve')->group(function () {
            Route::put('/entries/{entry}', [TimeTrackingController::class, 'updateEntry'])->name('entries.update');
            Route::get('/entries/{entry}/amendments', [TimeTrackingController::class, 'entryAmendments'])->name('entries.amendments');
            Route::post('/entries/{entry}/correct', [TimeTrackingController::class, 'correct'])->name('entries.correct');
            Route::post('/entries/{entry}/note', [TimeTrackingController::class, 'addNote'])->name('entries.note');
            Route::post('/clock-on-behalf', [TimeTrackingController::class, 'clockOnBehalf'])->name('clock-on-behalf');
        });

        // Voiding a paid-relevant entry is admin-only (manageAny).
        Route::post('/entries/{entry}/void', [TimeTrackingController::class, 'void'])
            ->name('entries.void')->middleware('permission:timesheets.manageAny');
    });

    /*
    |--------------------------------------------------------------------------
    | Departments
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.settings.manage|hr.employees.manage')->group(function () {
        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::get('/departments/{department}', [DepartmentController::class, 'show'])->name('departments.show');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.settings.manage')->prefix('settings')->name('settings.')->group(function () {
        // Automations + Webhooks (HR event-driven rules + outbound endpoints)
        // moved here from the Reports hub. Backed by HrAutomationController /
        // HrWebhookController — the live system with delivery logs + retry.
        Route::get('/automations', [HrAutomationController::class, 'index'])->name('automations.index');
        Route::post('/automations', [HrAutomationController::class, 'store'])->name('automations.store');
        Route::put('/automations/{rule}', [HrAutomationController::class, 'update'])->name('automations.update');
        Route::post('/automations/{rule}/toggle-active', [HrAutomationController::class, 'toggle'])->name('automations.toggleActive');

        Route::get('/webhooks', [HrWebhookController::class, 'index'])->name('webhooks.index');
        Route::post('/webhooks', [HrWebhookController::class, 'store'])->name('webhooks.store');
        Route::put('/webhooks/{endpoint}', [HrWebhookController::class, 'update'])->name('webhooks.update');
        Route::post('/webhooks/{endpoint}/toggle-active', [HrWebhookController::class, 'toggle'])->name('webhooks.toggleActive');
        Route::post('/webhooks/deliveries/{delivery}/retry', [HrWebhookController::class, 'retryDelivery'])->name('webhooks.deliveries.retry');

        Route::get('/custom-fields', [CustomFieldController::class, 'definitions'])->name('custom-fields');
        Route::post('/custom-fields', [CustomFieldController::class, 'storeDefinition'])->name('custom-fields.store');
        Route::put('/custom-fields/{definition}', [CustomFieldController::class, 'updateDefinition'])->name('custom-fields.update');
        Route::delete('/custom-fields/{definition}', [CustomFieldController::class, 'destroyDefinition'])->name('custom-fields.destroy');

        Route::get('/audit-log', [AuditController::class, 'index'])->name('audit-log');
        Route::get('/audit-log/{type}/{id}', [AuditController::class, 'show'])->name('audit-log.show');
    });

    /*
    |--------------------------------------------------------------------------
    | Report Builder
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.reports.view')->group(function () {
        Route::get('/reports/saved', [ReportBuilderController::class, 'index'])->name('reports.saved');
        Route::get('/reports/builder', [ReportBuilderController::class, 'create'])->name('reports.builder');
        Route::post('/reports/builder/preview', [ReportBuilderController::class, 'preview'])->name('reports.builder.preview');
        Route::post('/reports/builder', [ReportBuilderController::class, 'store'])->name('reports.builder.store');
        Route::post('/reports/saved/{report}/run', [ReportBuilderController::class, 'run'])->name('reports.saved.run');
        Route::get('/reports/saved/{report}/export', [ReportBuilderController::class, 'export'])->name('reports.saved.export');
        Route::delete('/reports/saved/{report}', [ReportBuilderController::class, 'destroy'])->name('reports.saved.destroy');
        Route::post('/reports/saved/{report}/schedule', [ReportBuilderController::class, 'schedule'])->name('reports.saved.schedule');
    });

    /*
    |--------------------------------------------------------------------------
    | iCal Feed
    |--------------------------------------------------------------------------
    */
    Route::get('/ical/{token}', [ICalController::class, 'feed'])->name('ical.feed')->withoutMiddleware('auth');
    Route::post('/ical/token', [ICalController::class, 'generateToken'])->name('ical.token');

    /*
    |--------------------------------------------------------------------------
    | Training Catalog & Course Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.training.view|training.viewAny')->group(function () {
        Route::get('/training/catalog', [TrainingController::class, 'catalog'])->name('training.catalog');
        Route::get('/training/courses/{course}', [TrainingController::class, 'showCourse'])->name('training.courses.show');
        Route::get('/training/enrollments/{enrollment}/certificate', [TrainingController::class, 'downloadCertificate'])->name('training.certificate');
    });
    Route::middleware('permission:hr.training.manage|training.manageCourses')->group(function () {
        Route::post('/training/courses', [TrainingController::class, 'storeCourse'])->name('training.courses.store');
    });
    Route::middleware('permission:hr.training.manage|training.manageCourses|training.enrol')->group(function () {
        Route::post('/training/enroll', [TrainingController::class, 'enroll'])->name('training.enroll');
    });
    Route::middleware('permission:hr.training.manage|training.manageCourses|training.record')->group(function () {
        Route::put('/training/enrollments/{enrollment}/complete', [TrainingController::class, 'completeEnrollment'])->name('training.enrollments.complete');
    });
});
