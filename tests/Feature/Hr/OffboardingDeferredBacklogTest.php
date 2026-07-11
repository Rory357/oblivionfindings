<?php

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExitInterview;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Notifications\ExitInterviewScheduledNotification;
use App\Domain\Hr\Services\AssetService;
use App\Domain\Hr\Services\ExitInterviewService;
use App\Domain\Hr\Services\OnboardingService;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

function deferredOffboardingProfile(User $employee, ?User $manager = null): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'user_id' => $employee->id,
        'manager_user_id' => $manager?->id,
        'position_role' => 'support_worker',
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->actor = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->interviewer = User::factory()->create([
        'organization_id' => 1,
        'role' => 'manager',
        'approved_at' => now(),
    ]);
    $this->employee = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->profile = deferredOffboardingProfile($this->employee, $this->interviewer);
});

test('a scheduled interview links and completes the exact offboarding task without title lookup', function () {
    Notification::fake();

    $checklist = app(OnboardingService::class)->generateOffboardingChecklist(
        $this->profile,
        $this->actor->id,
        ['end_date' => now()->addWeeks(2)->toDateString()],
    );
    $task = $checklist->tasks->firstWhere('category', 'hr');

    // Prove identity does not depend on wording.
    $task->update(['title' => 'Private departure conversation']);

    $interview = app(ExitInterviewService::class)->createExitInterview([
        'tenant_id' => 1,
        'created_by' => $this->actor->id,
        'employee_profile_id' => $this->profile->id,
        'interviewer_user_id' => $this->interviewer->id,
        'interview_date' => now()->addWeek()->toDateString(),
        'departure_reason' => 'career_growth',
        'offboarding_task_id' => $task->id,
    ]);

    expect($task->fresh()->exit_interview_id)->toBe($interview->id)
        ->and($task->fresh()->status)->toBe('completed')
        ->and($interview->offboardingTask?->id)->toBe($task->id);

    Notification::assertSentTo($this->interviewer, ExitInterviewScheduledNotification::class);
});

test('ambiguous open hr tasks are left unlinked when no explicit task is supplied', function () {
    $checklist = HrOffboardingChecklist::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $this->profile->id,
        'template_key' => 'offboarding:legacy',
        'status' => 'pending',
        'started_at' => now(),
        'due_date' => now()->addWeek(),
        'created_by' => $this->actor->id,
    ]);

    foreach (['Exit chat', 'Departure notes'] as $title) {
        HrOffboardingTask::query()->create([
            'offboarding_checklist_id' => $checklist->id,
            'category' => 'hr',
            'title' => $title,
            'is_required' => false,
            'status' => 'pending',
        ]);
    }

    $interview = app(ExitInterviewService::class)->createExitInterview([
        'tenant_id' => 1,
        'created_by' => $this->actor->id,
        'employee_profile_id' => $this->profile->id,
        'interviewer_user_id' => $this->interviewer->id,
        'interview_date' => now()->toDateString(),
        'departure_reason' => 'retirement',
    ]);

    expect($interview->offboardingTask)->toBeNull()
        ->and($checklist->tasks()->whereNotNull('exit_interview_id')->count())->toBe(0)
        ->and($checklist->tasks()->where('status', 'completed')->count())->toBe(0);
});

test('interviewer notifications are future-only and repeat only for a material reschedule', function () {
    Notification::fake();

    app(ExitInterviewService::class)->createExitInterview([
        'tenant_id' => 1,
        'created_by' => $this->actor->id,
        'employee_profile_id' => $this->profile->id,
        'interviewer_user_id' => $this->interviewer->id,
        'interview_date' => now()->subDay()->toDateString(),
        'departure_reason' => 'other',
    ]);
    Notification::assertNothingSent();

    $scheduled = app(ExitInterviewService::class)->createExitInterview([
        'tenant_id' => 1,
        'created_by' => $this->actor->id,
        'employee_profile_id' => $this->profile->id,
        'interviewer_user_id' => $this->interviewer->id,
        'interview_date' => now()->addWeek()->toDateString(),
        'departure_reason' => 'other',
    ]);
    Notification::assertSentToTimes($this->interviewer, ExitInterviewScheduledNotification::class, 1);

    app(ExitInterviewService::class)->rescheduleInterview($scheduled, [
        'interviewer_user_id' => $this->interviewer->id,
        'interview_date' => $scheduled->interview_date->toDateString(),
    ]);
    Notification::assertSentToTimes($this->interviewer, ExitInterviewScheduledNotification::class, 1);

    app(ExitInterviewService::class)->rescheduleInterview($scheduled, [
        'interviewer_user_id' => $this->interviewer->id,
        'interview_date' => now()->addWeeks(2)->toDateString(),
    ]);
    Notification::assertSentToTimes($this->interviewer, ExitInterviewScheduledNotification::class, 2);
});

test('a late asset assignment creates one return task and ignores returned or reassigned assets', function () {
    $checklist = app(OnboardingService::class)->generateOffboardingChecklist(
        $this->profile,
        $this->actor->id,
        ['end_date' => now()->addWeeks(2)->toDateString()],
    );
    $asset = HrAsset::query()->create([
        'tenant_id' => 1,
        'asset_tag' => 'LATE-001',
        'name' => 'Late laptop',
        'category' => 'laptop',
        'status' => 'available',
    ]);

    $assignment = app(AssetService::class)->assignAsset($asset, $this->profile, [
        'assigned_by' => $this->actor->id,
    ]);

    $stamp = "asset_assignment_id={$assignment->id};asset_id={$asset->id}";
    expect($checklist->tasks()->where('notes', $stamp)->count())->toBe(1);

    app(OnboardingService::class)->reconcileAssetReturnTask($checklist, $asset, $this->actor->id);
    expect($checklist->tasks()->where('notes', $stamp)->count())->toBe(1);

    app(AssetService::class)->returnAsset($assignment, []);
    expect(app(OnboardingService::class)->reconcileAssetReturnTask($checklist, $asset, $this->actor->id))->toBeNull();

    $otherEmployee = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $otherProfile = deferredOffboardingProfile($otherEmployee);
    $asset->update(['status' => 'available']);
    app(AssetService::class)->assignAsset($asset, $otherProfile, ['assigned_by' => $this->actor->id]);

    expect(app(OnboardingService::class)->reconcileAssetReturnTask($checklist, $asset, $this->actor->id))->toBeNull();
});

test('required offboarding tasks fall back from configured role to manager then initiating actor', function () {
    $roleOwner = User::factory()->create([
        'organization_id' => 1,
        'role' => 'it_admin',
        'approved_at' => now(),
    ]);

    $checklist = app(OnboardingService::class)->generateOffboardingChecklist(
        $this->profile,
        $this->actor->id,
    );

    expect($checklist->tasks->firstWhere('assigned_to_role', 'it_admin')->assigned_to_user_id)->toBe($roleOwner->id)
        ->and($checklist->tasks->firstWhere('assigned_to_role', 'payroll_admin')->assigned_to_user_id)->toBe($this->interviewer->id)
        ->and($checklist->tasks->where('is_required', true)->whereNull('assigned_to_user_id'))->toHaveCount(0);

    $this->profile->update(['manager_user_id' => null]);
    User::query()->where('id', '!=', $this->actor->id)->update(['role' => 'support_worker']);
    $actorFallback = app(OnboardingService::class)->generateOffboardingChecklist(
        $this->profile,
        $this->actor->id,
    );

    expect($actorFallback->tasks->where('is_required', true)->pluck('assigned_to_user_id')->unique()->all())
        ->toBe([$this->actor->id]);
});

test('unresolvable required owners fail validation before any checklist rows are written', function () {
    $this->profile->update(['manager_user_id' => null]);

    expect(fn () => app(OnboardingService::class)->generateOffboardingChecklist(
        $this->profile,
        999999,
    ))->toThrow(ValidationException::class);

    expect(HrOffboardingChecklist::query()->where('employee_profile_id', $this->profile->id)->count())->toBe(0)
        ->and(HrOffboardingTask::query()->count())->toBe(0)
        ->and(HrExitInterview::query()->count())->toBe(0);
});
