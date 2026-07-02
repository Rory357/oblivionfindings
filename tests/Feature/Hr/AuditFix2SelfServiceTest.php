<?php

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBenefitPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Notifications\BenefitEnrolledNotification;
use App\Domain\Hr\Notifications\ItProvisioningCancelledNotification;
use App\Domain\Hr\Notifications\KudosReceivedNotification;
use App\Domain\Hr\Services\BenefitsService;
use App\Domain\Hr\Services\FeedService;
use App\Models\ItProvisioningRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Notification::fake();

    // HR manager — holds hr.employees.manage, hr.benefits.*, it.manage.
    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    // Plain staff members.
    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);

    $this->teammate = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->teammate->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);
});

function af2Profile(User $user): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-AF2-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subDays(10)->toDateString(),
        'is_active' => true,
    ]);
}

function af2Checklist(HrEmployeeProfile $profile, int $createdBy): HrOnboardingChecklist
{
    return HrOnboardingChecklist::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'in_progress',
        'started_at' => now(),
        'due_date' => now()->addDays(20),
        'created_by' => $createdBy,
    ]);
}

function af2Task(HrOnboardingChecklist $checklist, array $overrides = []): HrOnboardingTask
{
    return HrOnboardingTask::query()->create(array_merge([
        'checklist_id' => $checklist->id,
        'category' => 'hr',
        'title' => 'Read the welcome pack',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
        'sign_off_required' => false,
    ], $overrides));
}

/* ------------------------------------------------------------------ */
/*  1 · New hire sees + completes their own onboarding checklist       */
/* ------------------------------------------------------------------ */

test('a new hire sees their own onboarding checklist on /hr/my and can complete their own task', function () {
    $profile = af2Profile($this->worker);
    $checklist = af2Checklist($profile, $this->hr->id);

    $mine = af2Task($checklist, [
        'title' => 'Read the welcome pack',
        'assigned_to_user_id' => $this->worker->id,
        'due_date' => now()->subDays(2)->toDateString(), // overdue
        'sort_order' => 1,
    ]);
    $unassigned = af2Task($checklist, [
        'title' => 'Set up your voicemail',
        'assigned_to_user_id' => null,
        'sort_order' => 2,
    ]);
    $signOff = af2Task($checklist, [
        'title' => 'Manager sign-off on induction',
        'assigned_to_user_id' => $this->hr->id,
        'sign_off_required' => true,
        'sort_order' => 3,
    ]);

    $response = $this->actingAs($this->worker)->get('/hr/my');
    $response->assertOk();

    $onboarding = $response->inertiaProps('onboarding');
    expect($onboarding)->not->toBeNull();
    expect($onboarding['id'])->toBe($checklist->id);
    expect($onboarding['tasks'])->toHaveCount(3);
    expect($onboarding['progress']['total'])->toBe(3);
    expect($onboarding['progress']['completed'])->toBe(0);

    $byId = collect($onboarding['tasks'])->keyBy('id');
    expect($byId[$mine->id]['can_complete'])->toBeTrue();
    expect($byId[$mine->id]['overdue'])->toBeTrue();
    expect($byId[$unassigned->id]['can_complete'])->toBeTrue();
    expect($byId[$signOff->id]['can_complete'])->toBeFalse();

    // The overdue own task also surfaces in the Overview attention worklist.
    $attention = collect($response->inertiaProps('overview.attention'));
    expect($attention->pluck('id'))->toContain('onboarding');

    // Complete my own task.
    $this->actingAs($this->worker)
        ->post("/hr/my/onboarding/tasks/{$mine->id}/complete")
        ->assertRedirect();

    $mine->refresh();
    expect($mine->status)->toBe('completed');
    expect($mine->completed_by)->toBe($this->worker->id);
});

test('the subject cannot complete a sign-off task assigned to someone else, and outsiders get 403', function () {
    $profile = af2Profile($this->worker);
    $checklist = af2Checklist($profile, $this->hr->id);

    $signOff = af2Task($checklist, [
        'title' => 'Manager sign-off on induction',
        'assigned_to_user_id' => $this->hr->id,
        'sign_off_required' => true,
    ]);
    $mine = af2Task($checklist, [
        'title' => 'Read the welcome pack',
        'assigned_to_user_id' => $this->worker->id,
        'sort_order' => 2,
    ]);

    // Not mine to complete (assigned elsewhere + sign-off).
    $this->actingAs($this->worker)
        ->post("/hr/my/onboarding/tasks/{$signOff->id}/complete")
        ->assertForbidden();

    // A different employee cannot complete tasks on my checklist at all.
    af2Profile($this->teammate);
    $this->actingAs($this->teammate)
        ->post("/hr/my/onboarding/tasks/{$mine->id}/complete")
        ->assertForbidden();

    expect($signOff->fresh()->status)->toBe('pending');
    expect($mine->fresh()->status)->toBe('pending');
});

/* ------------------------------------------------------------------ */
/*  2 · IT provisioning cancel annotates the task + notifies creator   */
/* ------------------------------------------------------------------ */

test('cancelling an IT request annotates the still-pending onboarding task and notifies the checklist creator', function () {
    $profile = af2Profile($this->worker);
    $checklist = af2Checklist($profile, $this->hr->id);
    $task = af2Task($checklist, [
        'category' => 'it',
        'title' => 'Create Microsoft 365 account',
    ]);

    $request = ItProvisioningRequest::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'onboarding_task_id' => $task->id,
        'type' => 'account',
        'item' => 'Create Microsoft 365 account',
        'status' => 'pending',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/it/provisioning/{$request->id}/cancel", ['reason' => 'Duplicate request'])
        ->assertRedirect();

    expect($request->fresh()->status)->toBe('cancelled');

    $task->refresh();
    expect($task->status)->toBe('pending'); // still open — not orphaned silently
    expect((string) $task->notes)->toContain('IT request cancelled: Duplicate request');

    Notification::assertSentTo(
        $this->hr,
        ItProvisioningCancelledNotification::class,
        function (ItProvisioningCancelledNotification $notification) use ($task) {
            $data = $notification->toArray($this->hr);

            return $data['task_id'] === $task->id
                && $data['reason'] === 'Duplicate request'
                && in_array('mail', $notification->via($this->hr), true)
                && in_array('database', $notification->via($this->hr), true);
        },
    );
});

/* ------------------------------------------------------------------ */
/*  3 · KiwiSaver enrolment ↔ payroll (profile) sync                   */
/* ------------------------------------------------------------------ */

test('a kiwisaver enrolment syncs the employee profile rate and opting out zeroes it', function () {
    $profile = af2Profile($this->worker);
    $plan = HrBenefitPlan::query()->create([
        'tenant_id' => 1,
        'name' => 'KiwiSaver — Default',
        'type' => 'kiwisaver',
        'employer_contribution_rate' => 3,
        'is_active' => true,
    ]);

    $enrollment = app(BenefitsService::class)->enrollEmployee($profile, $plan, [
        'enrollment_date' => now()->toDateString(),
        'employee_contribution_rate' => 4,
    ]);

    // Payroll reads profile.kiwisaver_rate — the enrolment must mirror there.
    expect((float) $profile->fresh()->kiwisaver_rate)->toBe(4.0);

    Notification::assertSentTo($this->worker, BenefitEnrolledNotification::class);

    // Opting out through the manager update path zeroes the profile rate.
    $this->actingAs($this->hr)
        ->put("/hr/compensation/benefits/enrollments/{$enrollment->id}", [
            'status' => 'opted_out',
            'opt_out_date' => now()->toDateString(),
        ])
        ->assertRedirect();

    expect($enrollment->fresh()->status)->toBe('opted_out');
    expect((float) $profile->fresh()->kiwisaver_rate)->toBe(0.0);
});

test('a non-kiwisaver enrolment never touches the profile kiwisaver rate', function () {
    $profile = af2Profile($this->worker);
    $profile->update(['kiwisaver_rate' => 3]);

    $plan = HrBenefitPlan::query()->create([
        'tenant_id' => 1,
        'name' => 'Southern Cross Wellbeing',
        'type' => 'health_insurance',
        'employer_contribution_rate' => 50,
        'is_active' => true,
    ]);

    app(BenefitsService::class)->enrollEmployee($profile, $plan, [
        'enrollment_date' => now()->toDateString(),
        'employee_contribution_rate' => 50,
    ]);

    expect((float) $profile->fresh()->kiwisaver_rate)->toBe(3.0);
});

/* ------------------------------------------------------------------ */
/*  4 · /hr/my/benefits is owner-scoped                                */
/* ------------------------------------------------------------------ */

test('the my-benefits page shows only the viewer’s own enrolments', function () {
    $mine = af2Profile($this->worker);
    $theirs = af2Profile($this->teammate);

    $plan = HrBenefitPlan::query()->create([
        'tenant_id' => 1,
        'name' => 'KiwiSaver — Default',
        'type' => 'kiwisaver',
        'employer_contribution_rate' => 3,
        'is_active' => true,
    ]);

    $ownEnrolment = HrBenefitEnrollment::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $mine->id,
        'benefit_plan_id' => $plan->id,
        'enrollment_date' => now()->toDateString(),
        'status' => 'active',
        'employee_contribution_rate' => 4,
        'employer_contribution_rate' => 3,
    ]);
    HrBenefitEnrollment::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $theirs->id,
        'benefit_plan_id' => $plan->id,
        'enrollment_date' => now()->toDateString(),
        'status' => 'active',
        'employee_contribution_rate' => 8,
        'employer_contribution_rate' => 3,
    ]);

    $response = $this->actingAs($this->worker)->get('/hr/my/benefits');
    $response->assertOk();

    $enrolments = collect($response->inertiaProps('enrolments'));
    expect($enrolments)->toHaveCount(1);
    expect($enrolments->first()['id'])->toBe($ownEnrolment->id);
    expect($enrolments->first()['plan_name'])->toBe('KiwiSaver — Default');
    expect((float) $enrolments->first()['employee_contribution_rate'])->toBe(4.0);
});

/* ------------------------------------------------------------------ */
/*  5 · Kudos notifies the recipient (never the sender)                */
/* ------------------------------------------------------------------ */

test('sending kudos notifies each recipient but never the sender', function () {
    af2Profile($this->worker);
    af2Profile($this->teammate);

    app(FeedService::class)->sendKudosToMany(
        $this->worker,
        [$this->teammate->id],
        'teamwork',
        'Amazing cover on the night shift — thank you!',
        1,
        'impressive',
    );

    Notification::assertSentTo(
        $this->teammate,
        KudosReceivedNotification::class,
        function (KudosReceivedNotification $notification) {
            $data = $notification->toArray($this->teammate);

            return $data['from_name'] === $this->worker->name
                && $data['category'] === 'teamwork'
                && $data['action_url'] === '/hr/my/shoutouts'
                && in_array('mail', $notification->via($this->teammate), true)
                && in_array('database', $notification->via($this->teammate), true);
        },
    );

    Notification::assertNotSentTo($this->worker, KudosReceivedNotification::class);
});

/* ------------------------------------------------------------------ */
/*  6 · Feed moderation — delete gated + audited                       */
/* ------------------------------------------------------------------ */

test('feed moderation delete is 403 for a plain user and removes the post (with audit log) for a manager', function () {
    af2Profile($this->worker);

    $post = HrFeedPost::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'post_type' => 'update',
        'content' => 'Something wildly inappropriate.',
        'is_pinned' => false,
    ]);

    // Plain user (no hr.employees.manage) is blocked.
    $this->actingAs($this->worker)
        ->delete("/hr/feed/posts/{$post->id}")
        ->assertForbidden();
    expect(HrFeedPost::query()->find($post->id))->not->toBeNull();

    // Manager removes it — hard delete + audit trail entry.
    $this->actingAs($this->hr)
        ->delete("/hr/feed/posts/{$post->id}")
        ->assertRedirect();

    expect(HrFeedPost::query()->find($post->id))->toBeNull();
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'hr.feed.post.removed',
        'auditable_type' => HrFeedPost::class,
        'auditable_id' => $post->id,
        'user_id' => $this->hr->id,
    ]);
});

test('removing a kudos also removes its feed post, reactions and replies', function () {
    af2Profile($this->worker);
    af2Profile($this->teammate);

    $kudos = app(FeedService::class)->sendKudos(
        $this->worker,
        $this->teammate->id,
        'teamwork',
        'Nice one!',
        1,
    );
    app(FeedService::class)->toggleReaction($kudos, $this->teammate->id, 'heart');
    app(FeedService::class)->addReply($kudos, $this->teammate->id, 'Thanks!');

    $postId = $kudos->feed_post_id;

    $this->actingAs($this->hr)
        ->delete("/hr/feed/kudos/{$kudos->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('hr_kudos', ['id' => $kudos->id]);
    $this->assertDatabaseMissing('hr_feed_posts', ['id' => $postId]);
    $this->assertDatabaseMissing('hr_kudos_reactions', ['kudos_id' => $kudos->id]);
    $this->assertDatabaseMissing('hr_kudos_replies', ['kudos_id' => $kudos->id]);
});
