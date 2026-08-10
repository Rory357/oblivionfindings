<?php

use App\Domain\Hr\Models\HrEapReferral;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementActionPlanNote;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Models\HrWellbeingFlagAction;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00'));

    $this->site = Site::factory()->create(['name' => 'Wellbeing care site']);

    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->manager->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);

    foreach ([$this->manager, $this->staff] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }
});

afterEach(fn () => Carbon::setTestNow());

function redFlagFor(User $user): HrWellbeingIndicator
{
    return HrWellbeingIndicator::query()->create([
        'user_id' => $user->id,
        'period_start' => now()->subDays(28)->toDateString(),
        'period_end' => now()->toDateString(),
        'overtime_hours' => 22.5,
        'consecutive_days_worked' => 13,
        'sick_leave_days_30d' => 1,
        'sick_leave_days_90d' => 1,
        'shifts_worked_7d' => 7,
        'average_shift_length_hours' => 11.8,
        'flag_level' => 'red',
        'calculated_at' => now(),
    ]);
}

test('flagged staff appear, acknowledge keeps them visible, snooze and dismiss hide them', function () {
    redFlagFor($this->staff);

    $props = $this->actingAs($this->manager)->get('/hr/wellbeing')->assertOk()->inertiaProps();
    $ids = collect($props['flaggedStaff'])->pluck('user_id')->all();
    expect($ids)->toContain($this->staff->id);

    // Acknowledge → still visible, latest_action recorded
    $this->actingAs($this->manager)->post("/hr/wellbeing/signals/{$this->staff->id}/acknowledge")->assertRedirect();
    $props = $this->actingAs($this->manager)->get('/hr/wellbeing')->inertiaProps();
    $row = collect($props['flaggedStaff'])->firstWhere('user_id', $this->staff->id);
    expect($row)->not->toBeNull();
    expect($row['latest_action']['action'])->toBe('acknowledge');

    // Snooze to the future → hidden
    $this->actingAs($this->manager)->post("/hr/wellbeing/signals/{$this->staff->id}/snooze", [
        'snooze_until' => now()->addDays(5)->toDateString(),
    ])->assertRedirect();
    $props = $this->actingAs($this->manager)->get('/hr/wellbeing')->inertiaProps();
    expect(collect($props['flaggedStaff'])->pluck('user_id')->all())->not->toContain($this->staff->id);

    // Dismiss → hidden
    $this->actingAs($this->manager)->post("/hr/wellbeing/signals/{$this->staff->id}/dismiss", ['reason' => 'Resolved'])->assertRedirect();
    $props = $this->actingAs($this->manager)->get('/hr/wellbeing')->inertiaProps();
    expect(collect($props['flaggedStaff'])->pluck('user_id')->all())->not->toContain($this->staff->id);

    expect(HrWellbeingFlagAction::query()->where('staff_user_id', $this->staff->id)->count())->toBe(3);
});

test('wellbeing undo removes only the acting managers latest triage action', function () {
    $otherManager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $otherManager->roles()->syncWithoutDetaching([$hrRole->id]);
    }
    HrEmployeeProfile::factory()->create([
        'user_id' => $otherManager->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->post("/hr/wellbeing/signals/{$this->staff->id}/acknowledge")
        ->assertRedirect();
    $this->actingAs($otherManager)
        ->post("/hr/wellbeing/signals/{$this->staff->id}/acknowledge")
        ->assertRedirect();

    $this->actingAs($this->manager)
        ->post("/hr/wellbeing/signals/{$this->staff->id}/undo")
        ->assertRedirect();

    $actions = HrWellbeingFlagAction::query()
        ->where('staff_user_id', $this->staff->id)
        ->get();
    expect($actions)->toHaveCount(1)
        ->and($actions->sole()->actor_user_id)->toBe($otherManager->id);
});

test('wellbeing undo conceals a hidden Site subject without deleting actions', function () {
    $hiddenSite = Site::factory()->create(['name' => 'Hidden wellbeing care site']);
    $hiddenStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $hiddenStaff->id,
        'primary_site_id' => $hiddenSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'employee_number' => 'WB-HIDDEN-'.$hiddenStaff->id,
        'work_email' => "wb-hidden-{$hiddenStaff->id}@example.test",
    ]);
    $action = HrWellbeingFlagAction::query()->create([
        'staff_user_id' => $hiddenStaff->id,
        'action' => 'acknowledge',
        'actor_user_id' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->post("/hr/wellbeing/signals/{$hiddenStaff->id}/undo")
        ->assertNotFound();

    expect($action->fresh())->not->toBeNull();
});

test('manager creates a standalone action plan from a flag with a system note', function () {
    $this->actingAs($this->manager)->post('/hr/wellbeing/action-plans', [
        'owner_user_id' => $this->manager->id,
        'staff_user_id' => $this->staff->id,
        'source_type' => 'flag',
        'title' => 'Reduce consecutive-day stretches',
        'priority' => 'high',
        'due_date' => now()->addDays(14)->toDateString(),
    ])->assertRedirect();

    $plan = HrEngagementActionPlan::query()->where('title', 'Reduce consecutive-day stretches')->first();
    expect($plan)->not->toBeNull();
    expect($plan->source_type)->toBe('flag');
    expect($plan->staff_user_id)->toBe($this->staff->id);
    expect($plan->survey_id)->toBeNull();
    expect(HrEngagementActionPlanNote::query()->where('plan_id', $plan->id)->where('kind', 'system')->exists())->toBeTrue();
});

test('action plan reopen and cancel append timeline notes', function () {
    $plan = HrEngagementActionPlan::query()->create([
        'owner_user_id' => $this->manager->id,
        'source_type' => 'manual',
        'title' => 'Test plan',
        'priority' => 'medium',
        'status' => 'completed',
        'progress_percent' => 100,
        'completed_at' => now()->toDateString(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)->post("/hr/wellbeing/action-plans/{$plan->id}/reopen")->assertRedirect();
    expect($plan->fresh()->status)->toBe('open');
    expect($plan->fresh()->completed_at)->toBeNull();

    $this->actingAs($this->manager)->post("/hr/wellbeing/action-plans/{$plan->id}/cancel", ['reason' => 'No longer needed'])->assertRedirect();
    expect($plan->fresh()->status)->toBe('cancelled');
    expect(HrEngagementActionPlanNote::query()->where('plan_id', $plan->id)->count())->toBeGreaterThanOrEqual(2);
});

test('the employee view hides private check-ins and shows shared ones to the subject', function () {
    // Check-ins about the manager (who can load /hr/wellbeing). Private one is hidden.
    HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->manager->id,
        'manager_user_id' => $this->manager->id,
        'type' => 'welfare',
        'notes' => 'Private note',
        'is_private' => true,
    ]);
    $shared = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->manager->id,
        'manager_user_id' => $this->manager->id,
        'type' => 'welfare',
        'notes' => 'Shared note',
        'is_private' => false,
    ]);

    $props = $this->actingAs($this->manager)->get('/hr/wellbeing')->inertiaProps();
    $myCheckinIds = collect($props['my']['checkins'])->pluck('id')->all();
    expect($myCheckinIds)->toContain($shared->id);
    expect($myCheckinIds)->toHaveCount(1); // private one excluded
});

test('only the subject can acknowledge a shared check-in, never a private one', function () {
    $shared = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->staff->id,
        'manager_user_id' => $this->manager->id,
        'type' => 'welfare',
        'notes' => 'Shared note',
        'is_private' => false,
    ]);
    $private = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->staff->id,
        'manager_user_id' => $this->manager->id,
        'type' => 'welfare',
        'notes' => 'Private note',
        'is_private' => true,
    ]);

    // Subject (a plain support worker, no dashboard permission) can acknowledge the shared one.
    $this->actingAs($this->staff)->post("/hr/wellbeing/checkins/{$shared->id}/acknowledge")->assertRedirect();
    expect($shared->fresh()->acknowledged_at)->not->toBeNull();

    // Private check-ins can never be acknowledged by the subject.
    $this->actingAs($this->staff)->post("/hr/wellbeing/checkins/{$private->id}/acknowledge")->assertNotFound();

    // A different user may not acknowledge.
    $other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $other->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
    $this->actingAs($other)->post("/hr/wellbeing/checkins/{$shared->id}/acknowledge")->assertNotFound();
});

test('manager can clear optional check-in fields without changing its subject', function () {
    $checkin = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->staff->id,
        'manager_user_id' => $this->manager->id,
        'type' => 'welfare',
        'notes' => 'Follow-up required',
        'mood' => 'low',
        'follow_up_date' => today()->addWeek(),
        'is_private' => true,
    ]);

    $this->actingAs($this->manager)->patch("/hr/wellbeing/checkins/{$checkin->id}", [
        'notes' => null,
        'mood' => null,
        'follow_up_date' => null,
        'is_private' => false,
    ])->assertRedirect();

    expect($checkin->fresh())
        ->staff_user_id->toBe($this->staff->id)
        ->notes->toBeNull()
        ->mood->toBeNull()
        ->follow_up_date->toBeNull()
        ->is_private->toBeFalse();
});

test('EAP referral is recorded', function () {
    $this->actingAs($this->manager)->post('/hr/wellbeing/eap-referrals', [
        'staff_user_id' => $this->staff->id,
        'reason_category' => 'workload',
        'provider' => 'Vitae',
        'consent_given' => true,
        'notes' => 'Context',
    ])->assertRedirect();

    $referral = HrEapReferral::query()->where('staff_user_id', $this->staff->id)->first();
    expect($referral)->not->toBeNull();
    expect($referral->reason_category)->toBe('workload');
    expect($referral->consent_given)->toBeTrue();
});

test('survey can be duplicated and both closed and draft surveys archived', function () {
    $published = HrEngagementSurvey::query()->create([
        'title' => 'June pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);
    $published->questions()->create(['question_type' => 'scale', 'question_text' => 'How are you?', 'is_required' => true, 'sort_order' => 1]);

    $this->actingAs($this->manager)->post("/hr/wellbeing/surveys/{$published->id}/duplicate")->assertRedirect();
    $copy = HrEngagementSurvey::query()->where('title', 'June pulse (copy)')->first();
    expect($copy)->not->toBeNull();
    expect($copy->status)->toBe('draft');
    expect($copy->questions()->count())->toBe(1);

    $closed = HrEngagementSurvey::query()->create([
        'title' => 'May pulse',
        'survey_type' => 'pulse',
        'status' => 'closed',
        'is_anonymous' => true,
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);
    $this->actingAs($this->manager)->post("/hr/wellbeing/surveys/{$closed->id}/archive")->assertRedirect();
    expect($closed->fresh()->status)->toBe('archived');

    $this->actingAs($this->manager)->delete("/hr/wellbeing/surveys/{$copy->id}")->assertRedirect();
    expect($copy->fresh()->status)->toBe('archived');
    expect($copy->questions()->count())->toBe(1);
});

test('draft survey optional scheduling and description fields can be cleared', function () {
    $survey = HrEngagementSurvey::query()->create([
        'title' => 'Scheduled pulse',
        'description' => 'Temporary description',
        'survey_type' => 'pulse',
        'status' => 'draft',
        'is_anonymous' => true,
        'starts_at' => today()->addWeek(),
        'ends_at' => today()->addWeeks(2),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);
    $survey->questions()->create([
        'question_type' => 'scale',
        'question_text' => 'How are you?',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($this->manager)->put("/hr/wellbeing/surveys/{$survey->id}", [
        'description' => null,
        'starts_at' => null,
        'ends_at' => null,
    ])->assertRedirect();

    expect($survey->fresh())
        ->description->toBeNull()
        ->starts_at->toBeNull()
        ->ends_at->toBeNull();
});

test('index exposes hero summary, needs and employee view props', function () {
    redFlagFor($this->staff);

    $props = $this->actingAs($this->manager)->get('/hr/wellbeing')->assertOk()->inertiaProps();

    expect($props['wellbeingSummary'])->toHaveKeys(['greenPct', 'needAttention', 'open_plans', 'overdue', 'enps']);
    expect($props['needs'])->toBeArray();
    expect($props['my'])->toHaveKeys(['name', 'surveys', 'checkins']);
    expect($props['staffOptions'])->toBeArray();
    expect($props['can']['manage'])->toBeTrue();
});

test('a non-manager cannot triage flags or create check-ins', function () {
    redFlagFor($this->staff);

    $this->actingAs($this->staff)->post("/hr/wellbeing/signals/{$this->staff->id}/acknowledge")->assertForbidden();
    $this->actingAs($this->staff)->post('/hr/wellbeing/checkins', [
        'staff_user_id' => $this->staff->id,
        'type' => 'welfare',
    ])->assertForbidden();
});
