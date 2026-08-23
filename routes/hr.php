<?php

use App\Domain\Hr\Enums\ComplianceExportDataset;
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
use App\Http\Controllers\Hr\ComplianceExportController;
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
use App\Http\Controllers\Hr\GoalCycleController;
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
use App\Http\Controllers\Hr\PerformanceHubController;
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
        Route::post('/expenses/{expenseClaim}/submit', [MyHrController::class, 'submitExpenseClaim'])->name('expenses.submit');
        Route::post('/expenses/{expenseClaim}/withdraw', [MyHrController::class, 'withdrawExpenseClaim'])->name('expenses.withdraw');
        Route::get('/training', [MyHrController::class, 'training'])->name('training');
        Route::get('/benefits', [MyHrController::class, 'benefits'])->name('benefits');
        // Owner-gated: a new hire completes their own onboarding task from the
        // Overview's "Getting started" card (subject-only; enforced in-controller).
        Route::post('/onboarding/tasks/{task}/complete', [MyHrController::class, 'completeOnboardingTask'])->name('onboarding.tasks.complete');
        Route::get('/policies', [MyHrController::class, 'policies'])->name('policies');
        Route::post('/policies/{policy}/attest', [MyHrController::class, 'attestPolicy'])->name('policies.attest');
        Route::get('/profile', [MyHrController::class, 'profile'])->name('profile');
        Route::put('/profile', [MyHrController::class, 'updateProfile'])->name('profile.update');
        Route::get('/directory', [MyHrController::class, 'directory'])->name('directory');
        Route::get('/reviews', [MyHrController::class, 'reviews'])->name('reviews');
        Route::put('/reviews/{review}', [MyHrController::class, 'updateReview'])->name('reviews.update');
        Route::get('/goals', [MyHrController::class, 'goals'])->name('goals');
        Route::put('/goals/{goal}', [MyHrController::class, 'updateGoal'])->name('goals.update');
        // Self-service OKR check-in: reuses the hub's unified check-in endpoint
        // logic; GoalController::checkin itself gates to owner-or-manager, so no
        // hr.performance.view grant is needed to check in on your own objective.
        Route::post('/goals/{goal}/checkin', [GoalController::class, 'checkin'])->name('goals.checkin');
        Route::get('/surveys', [MyHrController::class, 'surveys'])->name('surveys');
        Route::post('/surveys/{survey}', [MyHrController::class, 'submitSurvey'])->name('surveys.submit');

        Route::get('/time', [MyHrController::class, 'time'])->name('time');
        Route::post('/time/clock-in', [MyHrController::class, 'clockIn'])->name('time.clock-in');
        Route::post('/time/clock-out', [MyHrController::class, 'clockOut'])->name('time.clock-out');
        Route::get('/time/shifts/{shift}/calendar', [MyHrController::class, 'shiftCalendar'])->name('time.shift-calendar');
        Route::get('/one', [MyHrController::class, 'one'])->name('one');
        Route::post('/one/{note}/acknowledge', [SupervisionController::class, 'acknowledge'])->name('one.acknowledge');
        Route::post('/kudos', [MyHrController::class, 'sendKudos'])
            ->middleware('permission:hr.recognition.give')
            ->name('kudos');
        Route::get('/shoutouts', [MyHrController::class, 'shoutouts'])->name('shoutouts');
        Route::post('/kudos/{kudos}/react', [MyHrController::class, 'reactKudos'])
            ->middleware('permission:hr.recognition.give')
            ->name('kudos.react');
        Route::post('/kudos/{kudos}/reply', [MyHrController::class, 'replyKudos'])
            ->middleware('permission:hr.recognition.give')
            ->name('kudos.reply');
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
        Route::post('/recruitment/candidates/{candidate}/tags', [CandidateController::class, 'updateTags'])->name('candidates.tags.update')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/tags/rename', [CandidateController::class, 'renameTag'])->name('tags.rename')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/tags/delete', [CandidateController::class, 'deleteTag'])->name('tags.delete')
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
        Route::post('/recruitment/offers/{offer}/expire', [CandidateController::class, 'expireOffer'])->name('offers.expire')
            ->middleware('permission:hr.recruitment.manage');
        Route::get('/recruitment/offers/{offer}/letter', [CandidateController::class, 'downloadOfferLetter'])->name('offers.letter');
        Route::post('/recruitment/offers/{offer}/approve', [CandidateController::class, 'approveOffer'])->name('offers.approve')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/offers/{offer}/submit-approval', [CandidateController::class, 'submitOfferApproval'])->name('offers.submit-approval')
            ->middleware('permission:hr.recruitment.manage');
        Route::post('/recruitment/offers/{offer}/decline-approval', [CandidateController::class, 'declineOfferApproval'])->name('offers.decline-approval')
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
        Route::get('/people/{profile}', [EmployeeProfileController::class, 'show'])->name('people.show')->withTrashed();

        Route::get('/people/{profile}/documents', [HrDocumentController::class, 'profileDocuments'])->name('people.documents');
        Route::get('/people/{profile}/documents/{document}/download', [HrDocumentController::class, 'downloadForProfile'])->name('people.documents.download');
        Route::get('/people/{profile}/custom-fields', [CustomFieldController::class, 'employeeFields'])
            ->name('people.custom-fields')->withTrashed();

        Route::middleware('permission:hr.employees.manage')->group(function () {
            Route::post('/people', [EmployeeProfileController::class, 'store'])->name('people.store');
            Route::post('/people/bulk', [EmployeeProfileController::class, 'bulkAction'])->name('people.bulk');
            Route::get('/people/{profile}/edit', [EmployeeProfileController::class, 'edit'])->name('people.edit');
            Route::put('/people/{profile}', [EmployeeProfileController::class, 'update'])->name('people.update');
            Route::put('/people/{profile}/custom-fields', [CustomFieldController::class, 'updateEmployeeFields'])
                ->name('people.custom-fields.update');
            Route::patch('/people/{profile}/active', [EmployeeProfileController::class, 'setActive'])->name('people.active');
            Route::post('/people/{profile}/rehire', [EmployeeProfileController::class, 'rehire'])->name('people.rehire')->withTrashed();
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
    Route::get('/compliance/export', [ComplianceExportController::class, 'export'])
        ->middleware('permission:'.ComplianceExportDataset::routePermissionEnvelope())
        ->name('compliance.export');

    Route::middleware('permission:hr.compliance.view')->group(function () {
        Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');
        Route::get('/compliance/calendar', [ComplianceCalendarController::class, 'index'])->name('compliance.calendar');
        Route::get('/compliance/staff/{staff}', [ComplianceController::class, 'staffDetail'])
            ->whereNumber('staff')->name('compliance.staff');
        Route::get('/compliance/staff/{invalidStaff}', [ComplianceController::class, 'concealInvalidStaff'])
            ->where('invalidStaff', '[^/]+')->name('compliance.invalid.staff');
        Route::get('/compliance/status/{status}/evidence', [ComplianceController::class, 'evidence'])
            ->whereNumber('status')->name('compliance.status.evidence');
        Route::get('/compliance/status/{invalidStatus}/evidence', [ComplianceController::class, 'concealInvalidStatus'])
            ->where('invalidStatus', '[^/]+')->name('compliance.invalid.status.evidence');
        Route::post('/compliance/renewals/remind', [ComplianceController::class, 'renewalRemind'])->name('compliance.renewals.remind');

        Route::middleware('permission:hr.compliance.manage')->group(function () {
            Route::get('/compliance/matrix', [ComplianceMatrixController::class, 'index'])->name('compliance.matrix');
            Route::post('/compliance/requirements', [ComplianceMatrixController::class, 'storeRequirement'])->name('compliance.requirements.store');
            Route::put('/compliance/requirements/{requirement}', [ComplianceMatrixController::class, 'updateRequirement'])
                ->whereNumber('requirement')->name('compliance.requirements.update');
            Route::put('/compliance/requirements/{invalidRequirement}', [ComplianceMatrixController::class, 'concealInvalidRequirement'])
                ->where('invalidRequirement', '[^/]+')->name('compliance.invalid.requirements.update');
            Route::delete('/compliance/requirements/{requirement}', [ComplianceMatrixController::class, 'destroyRequirement'])
                ->whereNumber('requirement')->name('compliance.requirements.destroy');
            Route::delete('/compliance/requirements/{invalidRequirement}', [ComplianceMatrixController::class, 'concealInvalidRequirement'])
                ->where('invalidRequirement', '[^/]+')->name('compliance.invalid.requirements.destroy');
            Route::post('/compliance/matrix', [ComplianceMatrixController::class, 'updateMatrix'])->name('compliance.matrix.update');

            // Record / update / waive a per-staff compliance status (the write loop).
            Route::post('/compliance/staff/{staff}/status', [ComplianceController::class, 'storeStatus'])
                ->whereNumber('staff')->name('compliance.status.store');
            Route::post('/compliance/staff/{invalidStaff}/status', [ComplianceController::class, 'concealInvalidStaff'])
                ->where('invalidStaff', '[^/]+')->name('compliance.invalid.status.store');
            Route::put('/compliance/status/{status}', [ComplianceController::class, 'updateStatus'])
                ->whereNumber('status')->name('compliance.status.update');
            Route::put('/compliance/status/{invalidStatus}', [ComplianceController::class, 'concealInvalidStatus'])
                ->where('invalidStatus', '[^/]+')->name('compliance.invalid.status.update');
            Route::post('/compliance/status/{status}/exempt', [ComplianceController::class, 'exempt'])
                ->whereNumber('status')->name('compliance.status.exempt');
            Route::post('/compliance/status/{invalidStatus}/exempt', [ComplianceController::class, 'concealInvalidStatus'])
                ->where('invalidStatus', '[^/]+')->name('compliance.invalid.status.exempt');

            // Bulk + assignment.
            Route::post('/compliance/assign', [ComplianceMatrixController::class, 'assign'])->name('compliance.assign');
            Route::post('/compliance/bulk-record', [ComplianceController::class, 'bulkRecord'])->name('compliance.bulk.record');
            Route::post('/compliance/bulk-remind', [ComplianceController::class, 'bulkRemind'])->name('compliance.bulk.remind');
            Route::post('/compliance/bulk-exempt', [ComplianceController::class, 'bulkExempt'])->name('compliance.bulk.exempt');

            // Renewals snooze (remind is view-gated above; record renewal uses status.store).
            Route::post('/compliance/renewals/snooze', [ComplianceController::class, 'renewalSnooze'])->name('compliance.renewals.snooze');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Training Dashboard
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.training.view|training.viewAny')->group(function () {
        // Legacy standalone dashboard is consolidated into the Training hub.
        // Both URLs render the hub (it defaults to the Dashboard tab).
        Route::get('/training', [TrainingController::class, 'catalog'])->name('training.index');
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
        Route::get('/compliance/drivers/{eligibility}', [DriverEligibilityController::class, 'show'])->name('drivers.show');

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
            Route::post('/leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');
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
        Route::get('/onboarding/export', [OnboardingController::class, 'export'])->name('onboarding.export');

        Route::middleware('permission:hr.onboarding.manage')->group(function () {
            // Legacy single-field create page → redirects to the hub wizard.
            Route::get('/onboarding/create', [OnboardingController::class, 'create'])->name('onboarding.create');
            Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

            // Task lifecycle
            Route::post('/onboarding/tasks/{task}/complete', [OnboardingController::class, 'completeTask'])->name('onboarding.tasks.complete');
            Route::post('/onboarding/tasks/{task}/uncomplete', [OnboardingController::class, 'uncompleteTask'])->name('onboarding.tasks.uncomplete');
            Route::patch('/onboarding/tasks/{task}', [OnboardingController::class, 'updateTask'])->name('onboarding.tasks.update');
            Route::delete('/onboarding/tasks/{task}', [OnboardingController::class, 'destroyTask'])->name('onboarding.tasks.destroy');
            Route::post('/onboarding/tasks/{task}/provision-asset', [OnboardingController::class, 'provisionAsset'])->name('onboarding.tasks.provision-asset');
            Route::post('/onboarding/{checklist}/tasks', [OnboardingController::class, 'storeTask'])->name('onboarding.tasks.store');
            Route::post('/onboarding/{checklist}/tasks/reorder', [OnboardingController::class, 'reorderTasks'])->name('onboarding.tasks.reorder');

            // Bulk + checklist lifecycle
            Route::post('/onboarding/bulk', [OnboardingController::class, 'bulkAction'])->name('onboarding.bulk');
            Route::post('/onboarding/{checklist}/complete', [OnboardingController::class, 'completeChecklist'])->name('onboarding.complete');
            Route::post('/onboarding/{checklist}/remind', [OnboardingController::class, 'remindChecklist'])->name('onboarding.remind');
            Route::post('/onboarding/{checklist}/reassign', [OnboardingController::class, 'reassignChecklist'])->name('onboarding.reassign');
            Route::post('/onboarding/{checklist}/status', [OnboardingController::class, 'setChecklistStatus'])->name('onboarding.status');
            Route::delete('/onboarding/{checklist}', [OnboardingController::class, 'destroy'])->name('onboarding.destroy');

            // Templates
            Route::put('/onboarding/templates', [OnboardingController::class, 'updateTemplates'])->name('onboarding.templates.update');
            Route::post('/onboarding/templates/{template}/duplicate', [OnboardingController::class, 'duplicateTemplate'])->name('onboarding.templates.duplicate');
            Route::post('/onboarding/templates/{template}/active', [OnboardingController::class, 'setTemplateActive'])->name('onboarding.templates.active');
            Route::delete('/onboarding/templates/{template}', [OnboardingController::class, 'destroyTemplate'])->name('onboarding.templates.destroy');
        });

        // Onboarding Email Templates. Reads now live in the hub's Emails tab;
        // the legacy GET pages redirect there. Mutations stay (must be above
        // the {checklist} wildcard).
        Route::get('/onboarding/emails', fn () => redirect()->route('hr.onboarding.index', ['tab' => 'emails']))->name('onboarding.emails.index');
        Route::get('/onboarding/emails/log', fn () => redirect()->route('hr.onboarding.index', ['tab' => 'emails']))->name('onboarding.emails.log');
        Route::get('/onboarding/emails/{email}/preview', fn () => redirect()->route('hr.onboarding.index', ['tab' => 'emails']))->name('onboarding.emails.preview');

        Route::middleware('permission:hr.onboarding.manage')->group(function () {
            Route::post('/onboarding/emails', [OnboardingEmailController::class, 'store'])->name('onboarding.emails.store');
            Route::put('/onboarding/emails/{email}', [OnboardingEmailController::class, 'update'])->name('onboarding.emails.update');
            Route::delete('/onboarding/emails/{email}', [OnboardingEmailController::class, 'destroy'])->name('onboarding.emails.destroy');
            Route::post('/onboarding/emails/{email}/test', [OnboardingEmailController::class, 'test'])->name('onboarding.emails.test');
        });

        Route::get('/onboarding/{checklist}', [OnboardingController::class, 'show'])->name('onboarding.show');
    });

    /*
    |--------------------------------------------------------------------------
    | Performance & Supervision
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.performance.view')->prefix('performance')->name('performance.')->group(function () {
        Route::get('/', [PerformanceHubController::class, 'index'])->name('index');
        Route::get('/export', [PerformanceHubController::class, 'export'])->name('export');

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

            // Review lifecycle transitions (guarded, audited, locked after sign-off)
            Route::post('/reviews/{review}/submit', [PerformanceReviewController::class, 'submit'])->name('reviews.submit');
            Route::post('/reviews/{review}/sign-off', [PerformanceReviewController::class, 'signOff'])->name('reviews.sign-off');
            Route::post('/reviews/{review}/evidence', [PerformanceReviewController::class, 'uploadEvidence'])->name('reviews.evidence.store');

            // Probation reviews
            Route::post('/probation', [PerformanceReviewController::class, 'storeProbation'])->name('probation.store');
            Route::put('/probation/{review}', [PerformanceReviewController::class, 'updateProbation'])->name('probation.update');
        });

    });
    Route::post('/performance/reviews/{review}/acknowledge', [PerformanceReviewController::class, 'acknowledge'])
        ->name('performance.reviews.acknowledge');
    Route::get('/performance/reviews/{review}/evidence', [PerformanceReviewController::class, 'downloadEvidence'])
        ->name('performance.reviews.evidence.show');
    Route::post('/performance/supervision/{note}/acknowledge', [SupervisionController::class, 'acknowledge'])
        ->name('performance.supervision.acknowledge');

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
        Route::get('/documents/{document}/audit', [HrDocumentController::class, 'audit'])->name('documents.audit');

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
            Route::post('/tasks/{task}/uncomplete', [OffboardingController::class, 'uncompleteTask'])->name('tasks.uncomplete');
            Route::post('/{checklist}/status', [OffboardingController::class, 'setStatus'])->name('status');
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

        // Staff (My HR) actions on their own records.
        Route::post('/checkins/{checkin}/acknowledge', [WellbeingController::class, 'acknowledgeCheckin'])->name('checkins.acknowledge');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            // Surveys
            Route::post('/surveys', [WellbeingController::class, 'storeSurvey'])->name('surveys.store');
            Route::put('/surveys/{survey}', [WellbeingController::class, 'updateSurvey'])->name('surveys.update');
            Route::post('/surveys/{survey}/publish', [WellbeingController::class, 'publishSurvey'])->name('surveys.publish');
            Route::post('/surveys/{survey}/close', [WellbeingController::class, 'closeSurvey'])->name('surveys.close');
            Route::post('/surveys/{survey}/duplicate', [WellbeingController::class, 'duplicateSurvey'])->name('surveys.duplicate');
            Route::post('/surveys/{survey}/nudge', [WellbeingController::class, 'nudgeSurvey'])->name('surveys.nudge');
            Route::post('/surveys/{survey}/archive', [WellbeingController::class, 'archiveSurvey'])->name('surveys.archive');
            Route::delete('/surveys/{survey}', [WellbeingController::class, 'destroySurvey'])->name('surveys.destroy');
            Route::get('/surveys/{survey}/export', [WellbeingController::class, 'exportSurvey'])->name('surveys.export');
            Route::post('/surveys/{survey}/action-plans', [WellbeingController::class, 'storeActionPlan'])->name('action-plans.store');

            // Standalone / flag-linked action plans + lifecycle + notes
            Route::post('/action-plans', [WellbeingController::class, 'storeStandaloneActionPlan'])->name('action-plans.store-standalone');
            Route::post('/action-plans/{plan}/reopen', [WellbeingController::class, 'reopenActionPlan'])->name('action-plans.reopen');
            Route::post('/action-plans/{plan}/cancel', [WellbeingController::class, 'cancelActionPlan'])->name('action-plans.cancel');

            // Flag triage
            Route::post('/signals/{user}/acknowledge', [WellbeingController::class, 'acknowledgeFlag'])->name('signals.acknowledge');
            Route::post('/signals/{user}/snooze', [WellbeingController::class, 'snoozeFlag'])->name('signals.snooze');
            Route::post('/signals/{user}/dismiss', [WellbeingController::class, 'dismissFlag'])->name('signals.dismiss');
            Route::post('/signals/{user}/undo', [WellbeingController::class, 'undoFlag'])->name('signals.undo');

            // Check-ins
            Route::post('/checkins', [WellbeingController::class, 'storeCheckin'])->name('checkins.store');
            Route::patch('/checkins/{checkin}', [WellbeingController::class, 'updateCheckin'])->name('checkins.update');

            // EAP referrals
            Route::post('/eap-referrals', [WellbeingController::class, 'storeEapReferral'])->name('eap.store');
        });

        Route::put('/action-plans/{plan}', [WellbeingController::class, 'updateActionPlan'])->name('action-plans.update');
        Route::post('/action-plans/{plan}/notes', [WellbeingController::class, 'storeActionPlanNote'])->name('action-plans.notes.store');
    });

    /*
    |--------------------------------------------------------------------------
    | Development Goals (folded under the Goals & OKRs hub at /hr/goals/development)
    |--------------------------------------------------------------------------
    */
    Route::prefix('goals/development')->name('development.')->group(function () {
        // Folded into the Goals & OKR hub — index redirects to the tab.
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
            Route::post('/payroll/runs/{run}/retry-gl', [PayrollExportController::class, 'retryGlPost'])->name('payroll.runs.retry-gl');
            Route::post('/payroll/runs/{run}/prepare-net-pay', [PayrollExportController::class, 'prepareNetPay'])->name('payroll.runs.prepare-net-pay');
            Route::post('/payroll/runs/{run}/pay', [PayrollExportController::class, 'payNet'])->name('payroll.runs.pay');
            Route::post('/payroll/runs/{run}/reject-net-pay', [PayrollExportController::class, 'rejectNetPay'])->name('payroll.runs.reject-net-pay');
            Route::post('/payroll/runs/{run}/reconcile-net-pay', [PayrollExportController::class, 'reconcileNetPay'])->name('payroll.runs.reconcile-net-pay');
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
    | Retired management directory alias and staff-card endpoints
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.employees.viewAny')->group(function () {
        Route::get('/directory', [DirectoryController::class, 'index'])->name('directory.index');
    });

    // Current, Site-visible staff cards and their protected photos. The
    // operational directory page itself remains /hr/my/directory.
    Route::get('/directory/{profile}/photo', [DirectoryController::class, 'photo'])
        ->whereNumber('profile')->name('directory.photo');
    Route::post('/directory/{profile}/photo', [DirectoryController::class, 'uploadPhoto'])
        ->whereNumber('profile')->name('directory.uploadPhoto');
    Route::get('/directory/{profile}', [DirectoryController::class, 'show'])
        ->whereNumber('profile')->name('directory.show');
    // Keep invalid identifiers indistinguishable from concealed records while
    // retaining numeric constraints on every data-bearing route above.
    // Use a distinct parameter name so Laravel does not replace the numeric
    // route with this fallback in the route collection.
    Route::get('/directory/{invalidProfile}/photo', [DirectoryController::class, 'concealInvalidProfile'])
        ->where('invalidProfile', '[^/]+')->name('directory.invalid.photo.read');
    Route::post('/directory/{invalidProfile}/photo', [DirectoryController::class, 'concealInvalidProfile'])
        ->where('invalidProfile', '[^/]+')->name('directory.invalid.photo.write');
    Route::get('/directory/{invalidProfile}', [DirectoryController::class, 'concealInvalidProfile'])
        ->where('invalidProfile', '[^/]+')->name('directory.invalid.show');

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
            Route::post('/bands/{band}/deactivate', [CompensationController::class, 'deactivateBand'])->name('bands.deactivate');
            Route::post('/bands/{band}/reactivate', [CompensationController::class, 'reactivateBand'])->name('bands.reactivate');
            Route::get('/reviews/create', [CompensationController::class, 'createReview'])->name('reviews.create');
            Route::post('/reviews', [CompensationController::class, 'storeReview'])->name('reviews.store');
            Route::get('/reviews/{review}', [CompensationController::class, 'showReview'])->name('reviews.show');
            Route::post('/reviews/{review}/approve', [CompensationController::class, 'approveReview'])->name('reviews.approve');
            Route::post('/reviews/{review}/apply', [CompensationController::class, 'applyReview'])->name('reviews.apply');
            Route::post('/reviews/{review}/items/{item}/approve', [CompensationController::class, 'approveReviewItem'])->name('reviews.items.approve');
            Route::post('/reviews/{review}/items/{item}/reject', [CompensationController::class, 'rejectReviewItem'])->name('reviews.items.reject');
            Route::post('/bonuses', [BonusController::class, 'store'])->name('bonuses.store');
            Route::post('/bonuses/{bonus}/approve', [BonusController::class, 'approve'])->name('bonuses.approve');
            Route::post('/bonuses/{bonus}/cancel', [BonusController::class, 'cancel'])->name('bonuses.cancel');
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

        // Submit/resubmit: the controller gates to owner-or-manager, so a
        // claimant may (re)submit their own claim without hr.expenses.manage
        // (e.g. resubmitting after a rejection).
        Route::post('/{expenseClaim}/submit', [ExpenseController::class, 'submit'])->name('submit');

        Route::middleware('permission:hr.expenses.manage')->group(function () {
            Route::get('/create', [ExpenseController::class, 'create'])->name('create');
            Route::post('/', [ExpenseController::class, 'store'])->name('store');
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
        Route::get('/summary/{user}', [FeedbackController::class, 'summary'])->name('summary');

        // Reviewers (anyone who can view) respond to a request assigned to them.
        Route::get('/{feedbackRequest}/respond', [FeedbackController::class, 'respond'])->name('respond');
        Route::post('/{feedbackRequest}/respond', [FeedbackController::class, 'submitResponse'])->name('respond.store');

        // P0 security fix: creating requests + managing templates is a manager
        // action. These writes were previously gated only on `.view`, so any
        // viewer could create/decline/remind 360 cycles and CRUD templates.
        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::get('/request', [FeedbackController::class, 'request'])->name('request');
            Route::post('/request', [FeedbackController::class, 'storeRequest'])->name('request.store');
            Route::post('/bulk-request', [FeedbackController::class, 'bulkRequest'])->name('bulk-request');
            Route::post('/{feedbackRequest}/decline', [FeedbackController::class, 'decline'])->name('decline');
            Route::post('/{feedbackRequest}/remind', [FeedbackController::class, 'remind'])->name('remind');
            Route::post('/{feedbackRequest}/cancel', [FeedbackController::class, 'cancel'])->name('cancel');
            Route::post('/templates', [FeedbackController::class, 'storeTemplate'])->name('templates.store');
            Route::put('/templates/{template}', [FeedbackController::class, 'updateTemplate'])->name('templates.update');
            Route::delete('/templates/{template}', [FeedbackController::class, 'deleteTemplate'])->name('templates.destroy');
        });
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
            Route::post('/performance/competencies/{competency}/deactivate', [CompetencyController::class, 'deactivate'])->name('competencies.deactivate');
            Route::post('/performance/competencies/assess', [CompetencyController::class, 'assess'])->name('competencies.assess');
            Route::post('/performance/competencies/assessments/{assessment}/sign-off', [CompetencyController::class, 'signOffAssessment'])->name('competencies.assessments.sign-off');
            Route::post('/performance/competencies/assessments/{assessment}/evidence', [CompetencyController::class, 'uploadAssessmentEvidence'])->name('competencies.assessments.evidence.store');
        });

        Route::get('/performance/competencies/assessments/{assessment}/evidence', [CompetencyController::class, 'downloadAssessmentEvidence'])->name('competencies.assessments.evidence.show');
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
            Route::put('/{pip}', [PipController::class, 'update'])->name('update');
            Route::post('/{pip}/cancel', [PipController::class, 'cancel'])->name('cancel');
            Route::post('/{pip}/milestones', [PipController::class, 'storeMilestone'])->name('milestones.store');
            Route::put('/milestones/{milestone}', [PipController::class, 'updateMilestone'])->name('milestones.update');
            Route::delete('/milestones/{milestone}', [PipController::class, 'destroyMilestone'])->name('milestones.destroy');
            Route::post('/milestones/{milestone}/evidence', [PipController::class, 'uploadMilestoneEvidence'])->name('milestones.evidence.store');
            Route::post('/{pip}/complete', [PipController::class, 'complete'])->name('complete');
        });

    });

    // PIP detail + acknowledge sit OUTSIDE the hr.performance.view gate: the
    // subject employee must be able to read and acknowledge their own plan
    // (NZ good-faith process). The controller enforces subject-or-manage access.
    Route::prefix('performance/pips')->name('pips.')->group(function () {
        Route::get('/milestones/{milestone}/evidence', [PipController::class, 'downloadMilestoneEvidence'])->name('milestones.evidence.show');
        Route::post('/{pip}/acknowledge', [PipController::class, 'acknowledge'])->name('acknowledge');
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
            Route::delete('/{plan}', [SuccessionController::class, 'destroy'])->name('destroy');
            Route::post('/{plan}/candidates', [SuccessionController::class, 'addCandidate'])->name('candidates.store');
            Route::put('/candidates/{candidate}', [SuccessionController::class, 'updateCandidate'])->name('candidates.update');
            Route::delete('/candidates/{candidate}', [SuccessionController::class, 'removeCandidate'])->name('candidates.destroy');
            Route::post('/candidates/{candidate}/nominate', [SuccessionController::class, 'nominateToTalentPool'])->name('candidates.nominate');
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

        // Static segments must precede the /{goal} wildcard.
        Route::get('/export', [GoalController::class, 'export'])->name('export');
        Route::get('/cycles', [GoalCycleController::class, 'index'])->name('cycles.index');

        // Owners (not just managers) can check in on their own objectives.
        Route::post('/{goal}/checkin', [GoalController::class, 'checkin'])->name('checkin');
        Route::post('/{goal}/progress', [GoalController::class, 'updateProgress'])->name('progress');

        Route::middleware('permission:hr.performance.manage')->group(function () {
            Route::get('/create', [GoalController::class, 'create'])->name('create');
            Route::post('/', [GoalController::class, 'store'])->name('store');
            Route::post('/bulk', [GoalController::class, 'bulk'])->name('bulk');
            Route::put('/{goal}', [GoalController::class, 'update'])->name('update');
            Route::delete('/{goal}', [GoalController::class, 'destroy'])->name('destroy');
            Route::post('/{goal}/duplicate', [GoalController::class, 'duplicate'])->name('duplicate');
            Route::patch('/{goal}/parent', [GoalController::class, 'reparent'])->name('reparent');

            // Key Results
            Route::post('/{goal}/key-results', [GoalController::class, 'storeKeyResult'])->name('key-results.store');
            Route::put('/key-results/{keyResult}', [GoalController::class, 'updateKeyResult'])->name('key-results.update');
            Route::delete('/key-results/{keyResult}', [GoalController::class, 'destroyKeyResult'])->name('key-results.destroy');

            // Cycles
            Route::post('/cycles', [GoalCycleController::class, 'store'])->name('cycles.store');
            Route::put('/cycles/{cycle}', [GoalCycleController::class, 'update'])->name('cycles.update');
            Route::post('/cycles/{cycle}/close', [GoalCycleController::class, 'close'])->name('cycles.close');
            Route::post('/cycles/{cycle}/rollover', [GoalCycleController::class, 'rollover'])->name('cycles.rollover');
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

        // Moderation — remove an inappropriate post/kudos. There is no dedicated
        // hr.recognition.manage key (recognition ships only view/give), so the
        // strongest people-management permission stands in as the gate.
        Route::middleware('permission:hr.employees.manage')->group(function () {
            Route::delete('/posts/{post}', [FeedController::class, 'destroyPost'])->name('posts.destroy');
            Route::delete('/kudos/{kudos}', [FeedController::class, 'destroyKudos'])->name('kudos.destroy');
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

        // Manager-only command-center mutations. Static segments are declared
        // before the {announcement} wildcard so they aren't swallowed by it.
        Route::middleware('permission:hr.announcements.manage')->group(function () {
            Route::get('/create', [AnnouncementController::class, 'create'])->name('create');
            Route::get('/export', [AnnouncementController::class, 'export'])->name('export');
            Route::get('/preview', [AnnouncementController::class, 'preview'])->name('preview');
            Route::post('/', [AnnouncementController::class, 'store'])->name('store');
            Route::post('/bulk', [AnnouncementController::class, 'bulk'])->name('bulk');
            Route::post('/remind-bulk', [AnnouncementController::class, 'remindBulk'])->name('remind-bulk');
            Route::post('/{id}/restore', [AnnouncementController::class, 'restore'])->name('restore');
            Route::put('/{announcement}', [AnnouncementController::class, 'update'])->name('update');
            Route::patch('/{announcement}', [AnnouncementController::class, 'update']);
            Route::delete('/{announcement}', [AnnouncementController::class, 'destroy'])->name('destroy');
            Route::post('/{announcement}/publish', [AnnouncementController::class, 'publishNow'])->name('publish');
            Route::post('/{announcement}/remind', [AnnouncementController::class, 'remind'])->name('remind');
            Route::post('/{announcement}/acknowledge-for', [AnnouncementController::class, 'acknowledgeFor'])->name('acknowledge-for');
            Route::get('/{announcement}/tracking/export', [AnnouncementController::class, 'trackingExport'])->name('tracking.export');
            Route::get('/{announcement}/tracking', [AnnouncementController::class, 'tracking'])->name('tracking');
        });

        Route::get('/attachments/{attachment}', [AnnouncementController::class, 'downloadAttachment'])->name('attachments.show');
        Route::post('/{announcement}/acknowledge', [AnnouncementController::class, 'acknowledge'])->name('acknowledge');
        Route::get('/{announcement}', [AnnouncementController::class, 'show'])->name('show');
    });

    /*
    |--------------------------------------------------------------------------
    | Skills Matrix
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.skills.view|hr.skills.manage|hr.performance.view|hr.performance.manage')->prefix('performance/skills')->name('performance.skills.')->group(function () {
        Route::get('/', [SkillsController::class, 'index'])->name('index');
        Route::get('/matrix', [SkillsController::class, 'matrix'])->name('matrix');

        Route::middleware('permission:hr.skills.manage|hr.performance.manage')->group(function () {
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
    Route::middleware('permission:hr.calendar.view')->prefix('calendar')->name('calendar.')->group(function () {
        Route::get('/', [CalendarController::class, 'index'])->name('index');
        Route::get('/feed', [CalendarController::class, 'feed'])->name('feed');
        Route::post('/events', [CalendarController::class, 'store'])->name('events.store');
        Route::put('/events/{event}', [CalendarController::class, 'update'])->name('events.update');
        Route::delete('/events/{event}', [CalendarController::class, 'destroy'])->name('events.destroy');
        Route::post('/events/{event}/restore', [CalendarController::class, 'restore'])->name('events.restore');
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
        Route::get('/export', [AssetController::class, 'export'])->name('export');
        Route::get('/qr/{token}', [AssetController::class, 'qrRedirect'])->name('qr.redirect');
        Route::get('/documents/{document}/download', [AssetController::class, 'downloadDocument'])->name('documents.download');

        Route::middleware('permission:hr.assets.manage')->group(function () {
            Route::get('/fleet-search', [AssetController::class, 'fleetSearch'])->name('fleet-search');
            Route::post('/', [AssetController::class, 'store'])->name('store');
            Route::post('/bulk', [AssetController::class, 'bulk'])->name('bulk');
            Route::put('/{asset}', [AssetController::class, 'update'])->name('update');
            Route::post('/{asset}/assign', [AssetController::class, 'assign'])->name('assign');
            Route::post('/assignments/{assignment}/return', [AssetController::class, 'returnAsset'])->name('assignments.return');
            Route::post('/{asset}/maintenance', [AssetController::class, 'logMaintenance'])->name('maintenance');
            Route::post('/{asset}/return-to-service', [AssetController::class, 'returnToService'])->name('return-to-service');
            Route::post('/{asset}/retire', [AssetController::class, 'retire'])->name('retire');
            Route::post('/{asset}/documents', [AssetController::class, 'storeDocument'])->name('documents.store');
            Route::delete('/documents/{document}', [AssetController::class, 'destroyDocument'])->name('documents.destroy');
        });

        Route::get('/{asset}/qr.svg', [AssetController::class, 'qrSvg'])->name('qr.svg');
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
            Route::post('/leave-chains', [ApprovalController::class, 'storeLeaveChain'])->name('leave-chains.store');
            Route::put('/leave-chains/{leaveChain}', [ApprovalController::class, 'updateLeaveChain'])->name('leave-chains.update');
            Route::post('/leave-chains/reorder', [ApprovalController::class, 'reorderLeaveChains'])->name('leave-chains.reorder');
            Route::patch('/leave-chains/{leaveChain}/active', [ApprovalController::class, 'setLeaveChainActive'])->name('leave-chains.active');
            Route::delete('/leave-chains/{leaveChain}', [ApprovalController::class, 'destroyLeaveChain'])->name('leave-chains.destroy');
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
            Route::patch('/{exitInterview}', [ExitInterviewController::class, 'update'])->name('update');
            Route::post('/{exitInterview}/addenda', [ExitInterviewController::class, 'storeAddendum'])->name('addenda.store');
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
        Route::delete('/automations/{rule}', [HrAutomationController::class, 'destroy'])->name('automations.destroy');

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
    });

    /*
    |--------------------------------------------------------------------------
    | iCal Feed
    |--------------------------------------------------------------------------
    */
    Route::get('/ical/{token}', [ICalController::class, 'feed'])->middleware('throttle:60,1')->name('ical.feed')->withoutMiddleware('auth');
    Route::post('/ical/token', [ICalController::class, 'generateToken'])->name('ical.token');

    /*
    |--------------------------------------------------------------------------
    | Training Catalog & Course Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:hr.training.view|training.viewAny')->group(function () {
        Route::get('/training/catalog', [TrainingController::class, 'catalog'])->name('training.catalog');
        Route::get('/training/courses/{course}', [TrainingController::class, 'showCourse'])->name('training.courses.show');
        Route::get('/training/courses/{course}/detail', [TrainingController::class, 'courseDetail'])->name('training.courses.detail');
        Route::get('/training/export', [TrainingController::class, 'export'])->name('training.export');
        Route::get('/training/enrollments/{enrollment}/certificate', [TrainingController::class, 'downloadCertificate'])->name('training.certificate');
        // Claims reuse the expense backend; gated on the expense-create path inside the controller.
        Route::post('/training/claims', [TrainingController::class, 'claimFee'])->name('training.claims.store');
    });
    Route::middleware('permission:hr.training.manage|training.manageCourses')->group(function () {
        Route::post('/training/courses', [TrainingController::class, 'storeCourse'])->name('training.courses.store');
        Route::put('/training/courses/{course}', [TrainingController::class, 'updateCourse'])->name('training.courses.update');
        Route::patch('/training/courses/{course}/toggle', [TrainingController::class, 'toggleCourse'])->name('training.courses.toggle');
        Route::post('/training/courses/bulk-archive', [TrainingController::class, 'bulkArchiveCourses'])->name('training.courses.bulk-archive');
        Route::post('/training/courses/{course}/sessions', [TrainingController::class, 'storeSession'])->name('training.sessions.store');
        Route::put('/training/sessions/{session}', [TrainingController::class, 'updateSession'])->name('training.sessions.update');
        Route::delete('/training/sessions/{session}', [TrainingController::class, 'cancelSession'])->name('training.sessions.cancel');
    });
    Route::middleware('permission:hr.training.manage|training.manageCourses|training.enrol')->group(function () {
        Route::post('/training/enroll', [TrainingController::class, 'enroll'])->name('training.enroll');
        Route::post('/training/assignments', [TrainingController::class, 'storeAssignments'])->name('training.assignments.store');
        Route::get('/training/assignments/preview', [TrainingController::class, 'previewAssignments'])->name('training.assignments.preview');
        Route::post('/training/assignments/{assignment}/remind', [TrainingController::class, 'remindAssignment'])->name('training.assignments.remind');
    });
    Route::middleware('permission:hr.training.manage|training.manageCourses|training.record')->group(function () {
        Route::put('/training/enrollments/{enrollment}/complete', [TrainingController::class, 'completeEnrollment'])->name('training.enrollments.complete');
        Route::post('/training/record', [TrainingController::class, 'recordCompletion'])->name('training.record');
        Route::patch('/training/assignments/{assignment}/waive', [TrainingController::class, 'waiveAssignment'])->name('training.assignments.waive');
    });
});
