<?php

use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Notifications\ExpenseRejectedNotification;
use App\Domain\Hr\Notifications\LeaveDeclinedNotification;
use App\Domain\Hr\Notifications\SignatureRequestedNotification;
use App\Domain\Hr\Notifications\TrainingAssignedNotification;
use App\Domain\Hr\Services\ESignatureService;
use App\Domain\Hr\Services\ExpenseService;
use App\Domain\Hr\Services\LeaveService;
use App\Domain\Hr\Services\OnboardingService;
use App\Domain\Hr\Services\TrainingService;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();

    $this->site = Site::factory()->create([
        'name' => 'Notification Site',
    ]);

    $this->manager = User::factory()->create([
        'approved_at' => now(),
    ]);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    auditFixProfile($this->manager, $this->site);
});

function auditFixProfile(User $user, Site $site): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-AF-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
    ]);
}

test('creating a training assignment notifies the assigned employee', function () {
    auditFixProfile($this->worker, $this->site);

    $course = HrCourse::factory()->create([
        'title' => 'Medication Competency',
    ]);

    $count = app(TrainingService::class)->createAssignments([
        'audience_type' => 'individuals',
        'user_ids' => [$this->worker->id],
        'course_ids' => [$course->id],
        'due_at' => now()->addDays(14)->toDateString(),
    ], $this->manager->id);

    expect($count)->toBe(1);

    Notification::assertSentTo(
        $this->worker,
        TrainingAssignedNotification::class,
        function (TrainingAssignedNotification $notification) use ($course) {
            $data = $notification->toArray($this->worker);

            return $data['course_title'] === $course->title
                && $data['due_at'] === now()->addDays(14)->toDateString()
                && $data['action_url'] === '/hr/my/training'
                && in_array('mail', $notification->via($this->worker), true)
                && in_array('database', $notification->via($this->worker), true);
        },
    );
});

test('bulk signature requests notify each signer on creation', function () {
    $profile = auditFixProfile($this->worker, $this->site);

    $document = HrDocument::query()->create([
        'employee_profile_id' => $profile->id,
        'title' => 'Employment Agreement',
        'category' => 'contract',
        'storage_disk' => 'local',
        'storage_path' => 'hr/documents/agreement.pdf',
        'original_name' => 'agreement.pdf',
        'created_by' => $this->manager->id,
    ]);

    $signatures = app(ESignatureService::class)->bulkRequestSignatures(
        $document,
        [$this->worker->id],
        $this->manager->id,
        ['due_at' => now()->addDays(5)->toDateString(), 'message' => 'Please sign before Friday.'],
    );

    expect($signatures)->toHaveCount(1);

    Notification::assertSentTo(
        $this->worker,
        SignatureRequestedNotification::class,
        function (SignatureRequestedNotification $notification) {
            $data = $notification->toArray($this->worker);

            return $data['document_title'] === 'Employment Agreement'
                && $data['action_url'] === '/hr/signatures/pending'
                && in_array('mail', $notification->via($this->worker), true);
        },
    );
});

test('declining a leave request sends the real mail notification class with the reason', function () {
    auditFixProfile($this->worker, $this->site);

    $request = HrLeaveRequest::factory()->create([
        'user_id' => $this->worker->id,
        'leave_type' => 'annual',
        'status' => 'pending',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addDay(),
        'hours_requested' => 8,
    ]);

    app(LeaveService::class)->declineRequest($request, $this->manager, 'Roster is short-staffed that week.');

    expect($request->fresh()->status)->toBe('declined');
    expect($request->fresh()->review_notes)->toBe('Roster is short-staffed that week.');

    Notification::assertSentTo(
        $this->worker,
        LeaveDeclinedNotification::class,
        function (LeaveDeclinedNotification $notification) {
            return in_array('mail', $notification->via($this->worker), true)
                && in_array('database', $notification->via($this->worker), true);
        },
    );
});

test('rejecting an expense claim notifies the claimant and the claim can be resubmitted', function () {
    auditFixProfile($this->worker, $this->site);

    $claim = HrExpenseClaim::factory()->create([
        'user_id' => $this->worker->id,
        'status' => 'submitted',
        'title' => 'Mileage — client visits',
    ]);
    $claim->items()->create([
        'description' => 'Mileage',
        'category' => 'mileage',
        'amount' => 42.50,
        'expense_date' => now()->subDays(3)->toDateString(),
    ]);

    $service = app(ExpenseService::class);

    $rejected = $service->rejectClaim($claim, $this->manager, 'Missing the client visit log.');
    expect($rejected->status)->toBe('rejected');
    expect($rejected->rejection_reason)->toBe('Missing the client visit log.');

    Notification::assertSentTo(
        $this->worker,
        ExpenseRejectedNotification::class,
        function (ExpenseRejectedNotification $notification) {
            $data = $notification->toArray($this->worker);

            return $data['rejection_reason'] === 'Missing the client visit log.'
                && $data['status'] === 'rejected';
        },
    );

    // A rejected claim may be resubmitted — the prior decision is cleared.
    $resubmitted = $service->submitClaim($rejected);
    expect($resubmitted->status)->toBe('submitted');
    expect($resubmitted->rejection_reason)->toBeNull();
    expect($resubmitted->approved_by)->toBeNull();
});

test('an approved or paid claim still cannot be submitted', function () {
    $claim = HrExpenseClaim::factory()->create([
        'user_id' => $this->worker->id,
        'status' => 'approved',
    ]);
    $claim->items()->create([
        'description' => 'Taxi',
        'category' => 'travel',
        'amount' => 20,
        'expense_date' => now()->subDay()->toDateString(),
    ]);

    app(ExpenseService::class)->submitClaim($claim);
})->throws(LogicException::class);

test('completing an induction enrolment auto-completes the linked onboarding task', function () {
    $course = HrCourse::factory()->create([
        'title' => 'Health & Safety Induction',
        'code' => 'HS-IND',
        'is_mandatory' => true,
        'is_active' => true,
    ]);

    HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_active' => true,
        'tasks' => [
            [
                'category' => 'induction',
                'title' => 'Complete H&S induction',
                'is_required' => true,
                'sort_order' => 1,
                'course_code' => 'HS-IND',
            ],
        ],
    ]);

    $profile = auditFixProfile($this->worker, $this->site);

    // Generating the checklist auto-enrols the hire (existing cross-loop).
    $checklist = app(OnboardingService::class)->generateChecklist($profile, $this->manager->id);

    $enrollment = HrCourseEnrollment::query()
        ->where('user_id', $this->worker->id)
        ->where('course_id', $course->id)
        ->firstOrFail();

    app(TrainingService::class)->completeEnrollment($enrollment, ['score' => 88]);

    $task = HrOnboardingTask::query()
        ->where('checklist_id', $checklist->id)
        ->where('category', 'induction')
        ->firstOrFail();

    expect($task->status)->toBe('completed');
    expect((string) $task->notes)->toContain('Auto-completed: course HS-IND completed.');
});

test('a sign-off induction task is left for manual completion', function () {
    $course = HrCourse::factory()->create([
        'title' => 'Safeguarding Induction',
        'code' => 'SG-IND',
        'is_mandatory' => true,
        'is_active' => true,
    ]);

    HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_active' => true,
        'tasks' => [
            [
                'category' => 'induction',
                'title' => 'Complete safeguarding induction',
                'is_required' => true,
                'sort_order' => 1,
                'course_code' => 'SG-IND',
                'sign_off_required' => true,
            ],
        ],
    ]);

    $profile = auditFixProfile($this->worker, $this->site);
    $checklist = app(OnboardingService::class)->generateChecklist($profile, $this->manager->id);

    $enrollment = HrCourseEnrollment::query()
        ->where('user_id', $this->worker->id)
        ->where('course_id', $course->id)
        ->firstOrFail();

    // Must not throw — the LogicException (sign-off required) is swallowed and
    // the task remains pending for a human.
    app(TrainingService::class)->completeEnrollment($enrollment, ['score' => 90]);

    $task = HrOnboardingTask::query()
        ->where('checklist_id', $checklist->id)
        ->where('category', 'induction')
        ->firstOrFail();

    expect($task->status)->not->toBe('completed');
});
