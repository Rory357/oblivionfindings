<?php

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalCycle;
use App\Domain\Hr\Models\HrGoalTemplate;
use App\Domain\Hr\Models\HrKeyResult;
use App\Domain\Hr\Notifications\GoalCheckinDueNotification;
use App\Domain\Hr\Notifications\GoalOverdueNotification;
use App\Domain\Hr\Notifications\GoalWeeklyDigestNotification;
use App\Domain\Hr\Services\CycleService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->owner = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->site = Site::factory()->create(['name' => 'Goals and OKRs Site']);
    goalsOkrProfile($this->hr, $this->site);
    goalsOkrProfile($this->owner, $this->site);
});

function goalsOkrProfile(User $user, Site $site): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

test('the hub ships objectives, cycles and confidence-aware analytics', function () {
    HrGoal::query()->create([
        'user_id' => $this->owner->id,
        'created_by' => $this->hr->id,
        'title' => 'At-risk objective',
        'goal_type' => 'company',
        'priority' => 'high',
        'status' => 'active',
        'confidence' => 'at_risk',
        'start_date' => '2026-07-01',
        'due_date' => '2026-09-30',
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/goals?cycle=all');
    $response->assertOk();

    expect($response->inertiaProps('objectives'))->not->toBeNull();
    expect($response->inertiaProps('cycles'))->not->toBeEmpty();
    expect($response->inertiaProps('analytics')['at_risk'])->toBeGreaterThanOrEqual(1);
});

test('creating an objective with key results computes a weighted roll-up', function () {
    $this->actingAs($this->hr)->post('/hr/goals', [
        'user_id' => $this->owner->id,
        'title' => 'Weighted objective',
        'goal_type' => 'team',
        'priority' => 'high',
        'confidence' => 'on_track',
        'start_date' => '2026-07-01',
        'due_date' => '2026-09-30',
        'key_results' => [
            ['title' => 'KR A', 'kr_type' => 'percent', 'start_value' => 0, 'target_value' => 100, 'unit' => '%', 'weight' => 3],
            ['title' => 'KR B', 'kr_type' => 'percent', 'start_value' => 0, 'target_value' => 100, 'unit' => '%', 'weight' => 1],
        ],
    ])->assertRedirect();

    $goal = HrGoal::query()->where('title', 'Weighted objective')->with('keyResults')->first();
    expect($goal->keyResults)->toHaveCount(2);
    expect((int) $goal->progress_percentage)->toBe(0); // both at baseline
});

test('a baseline-aware KR check-in recomputes progress and logs confidence', function () {
    $goal = HrGoal::query()->create([
        'user_id' => $this->owner->id,
        'created_by' => $this->hr->id,
        'title' => 'Reduce errors',
        'goal_type' => 'team',
        'priority' => 'high',
        'status' => 'active',
        'start_date' => '2026-07-01',
        'due_date' => '2026-09-30',
    ]);

    // "Reduce 50 -> 10": a current of 30 is exactly halfway.
    $kr = HrKeyResult::query()->create([
        'goal_id' => $goal->id,
        'title' => 'Errors per month',
        'kr_type' => 'number',
        'start_value' => 50,
        'current_value' => 50,
        'target_value' => 10,
        'weight' => 1,
        'status' => 'not_started',
    ]);

    $this->actingAs($this->hr)->post("/hr/goals/{$goal->id}/checkin", [
        'confidence' => 'at_risk',
        'comment' => 'Halfway there',
        'key_results' => [
            ['id' => $kr->id, 'current_value' => 30, 'confidence' => 'at_risk'],
        ],
    ])->assertRedirect();

    $kr->refresh();
    $goal->refresh();
    expect($kr->progress_percentage)->toBe(50);
    expect((int) $goal->progress_percentage)->toBe(50);
    expect($goal->confidence)->toBe('at_risk');
    expect($goal->updates()->count())->toBeGreaterThanOrEqual(1);
    expect($kr->updates()->count())->toBe(1);
});

test('a manual objective rejects manual progress once it has key results', function () {
    $goal = HrGoal::query()->create([
        'user_id' => $this->owner->id,
        'created_by' => $this->hr->id,
        'title' => 'Derived objective',
        'goal_type' => 'team',
        'priority' => 'high',
        'status' => 'active',
        'start_date' => '2026-07-01',
        'due_date' => '2026-09-30',
    ]);
    HrKeyResult::query()->create([
        'goal_id' => $goal->id, 'title' => 'KR', 'start_value' => 0,
        'current_value' => 0, 'target_value' => 100, 'weight' => 1, 'status' => 'not_started',
    ]);

    $this->actingAs($this->hr)->post("/hr/goals/{$goal->id}/progress", [
        'progress_percentage' => 80,
    ])->assertStatus(422);
});

test('an objective can be duplicated with its key results reset to baseline', function () {
    $goal = HrGoal::query()->create([
        'user_id' => $this->owner->id,
        'created_by' => $this->hr->id,
        'title' => 'Clone me',
        'goal_type' => 'team',
        'priority' => 'medium',
        'status' => 'active',
        'progress_percentage' => 60,
        'start_date' => '2026-07-01',
        'due_date' => '2026-09-30',
    ]);
    HrKeyResult::query()->create([
        'goal_id' => $goal->id, 'title' => 'KR', 'start_value' => 10,
        'current_value' => 50, 'target_value' => 100, 'weight' => 1, 'progress_percentage' => 44, 'status' => 'in_progress',
    ]);

    $this->actingAs($this->hr)->post("/hr/goals/{$goal->id}/duplicate", [
        'with_key_results' => true,
    ])->assertRedirect();

    $clone = HrGoal::query()->where('title', 'Clone me (copy)')->with('keyResults')->first();
    expect($clone)->not->toBeNull();
    expect($clone->status)->toBe('draft');
    expect((int) $clone->progress_percentage)->toBe(0);
    expect((float) $clone->keyResults->first()->current_value)->toBe(10.0); // reset to baseline
});

test('an objective can be re-parented and roll-ups recompute', function () {
    $parent = HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'New parent', 'goal_type' => 'company', 'priority' => 'high', 'status' => 'active',
        'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
    ]);
    $child = HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'Child', 'goal_type' => 'team', 'priority' => 'high', 'status' => 'active',
        'progress_percentage' => 80, 'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
    ]);

    $this->actingAs($this->hr)->patch("/hr/goals/{$child->id}/parent", [
        'parent_goal_id' => $parent->id,
    ])->assertRedirect();

    $child->refresh();
    $parent->refresh();
    expect($child->parent_goal_id)->toBe($parent->id);
    expect((int) $parent->progress_percentage)->toBe(80); // rolled up from the only child
});

test('re-parenting refuses to create a cycle', function () {
    $a = HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'A', 'goal_type' => 'company', 'priority' => 'high', 'status' => 'active',
        'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
    ]);
    $b = HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'B', 'goal_type' => 'team', 'priority' => 'high', 'status' => 'active',
        'parent_goal_id' => $a->id, 'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
    ]);

    // Moving A under its own descendant B must fail.
    $this->actingAs($this->hr)->patch("/hr/goals/{$a->id}/parent", [
        'parent_goal_id' => $b->id,
    ])->assertStatus(422);
});

test('bulk archive cancels the selected objectives', function () {
    $g1 = HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'Bulk 1', 'goal_type' => 'team', 'priority' => 'low', 'status' => 'active',
        'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
    ]);
    $g2 = HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'Bulk 2', 'goal_type' => 'team', 'priority' => 'low', 'status' => 'active',
        'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
    ]);

    $this->actingAs($this->hr)->post('/hr/goals/bulk', [
        'action' => 'archive',
        'ids' => [$g1->id, $g2->id],
    ])->assertRedirect();

    expect($g1->fresh()->status)->toBe('cancelled');
    expect($g2->fresh()->status)->toBe('cancelled');
});

test('the export endpoint streams a CSV of objectives and KRs', function () {
    $goal = HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'Exportable', 'goal_type' => 'team', 'priority' => 'medium', 'status' => 'active',
        'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
    ]);
    HrKeyResult::query()->create([
        'goal_id' => $goal->id, 'title' => 'Exported KR', 'start_value' => 0,
        'current_value' => 0, 'target_value' => 100, 'weight' => 1, 'status' => 'not_started',
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/goals/export?cycle=all');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});

test('cycles are seeded and the current cycle resolves to today\'s window', function () {
    $service = app(CycleService::class);
    $service->seedDefaults();

    $cycles = HrGoalCycle::query()->get();
    expect($cycles->where('type', 'quarter'))->toHaveCount(4);
    expect($service->currentCycle())->not->toBeNull();
});

test('creating an objective persists confidence, cycle and tags', function () {
    $cycle = HrGoalCycle::query()->create([
        'name' => 'FY26 Q3', 'type' => 'quarter',
        'starts_at' => '2026-07-01', 'ends_at' => '2026-09-30', 'status' => 'active',
    ]);

    $this->actingAs($this->hr)->post('/hr/goals', [
        'user_id' => $this->owner->id,
        'title' => 'Tagged objective',
        'goal_type' => 'team',
        'priority' => 'high',
        'confidence' => 'at_risk',
        'checkin_frequency' => 'weekly',
        'cycle_id' => $cycle->id,
        'tags' => ['Safety', 'Quality'],
        'start_date' => '2026-07-01',
        'due_date' => '2026-09-30',
    ])->assertRedirect();

    $goal = HrGoal::query()->where('title', 'Tagged objective')->first();
    expect($goal->confidence)->toBe('at_risk');
    expect($goal->cycle_id)->toBe($cycle->id);
    expect($goal->checkin_frequency)->toBe('weekly');
    expect($goal->tags)->toBe(['Safety', 'Quality']);
});

test('an objective can be put on hold and blocked', function () {
    $goal = HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'Holdable', 'goal_type' => 'team', 'priority' => 'medium', 'status' => 'active',
        'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
    ]);

    $this->actingAs($this->hr)->put("/hr/goals/{$goal->id}", ['status' => 'on_hold'])->assertRedirect();
    expect($goal->fresh()->status)->toBe('on_hold');

    $this->actingAs($this->hr)->put("/hr/goals/{$goal->id}", ['status' => 'blocked'])->assertRedirect();
    expect($goal->fresh()->status)->toBe('blocked');
});

test('the hub exposes objective templates', function () {
    HrGoalTemplate::query()->create([
        'name' => 'Sample template', 'title' => 'Sample objective',
        'goal_type' => 'team', 'priority' => 'high',
        'key_results' => [['title' => 'KR', 'kr_type' => 'percent', 'start_value' => 0, 'target_value' => 100, 'unit' => '%', 'weight' => 1]],
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/goals?cycle=all');
    $response->assertOk();
    expect(collect($response->inertiaProps('templates'))->pluck('name'))->toContain('Sample template');
});

test('the daily reminders command notifies owners of due check-ins and overdue objectives', function () {
    Notification::fake();

    // No check-in ever + overdue.
    HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'Needs a check-in', 'goal_type' => 'team', 'priority' => 'high', 'status' => 'active',
        'checkin_frequency' => 'weekly', 'last_checkin_at' => null,
        'start_date' => now()->subMonths(2)->toDateString(), 'due_date' => now()->subWeek()->toDateString(),
    ]);

    $this->artisan('hr:goal-reminders')->assertExitCode(0);

    Notification::assertSentTo($this->owner, GoalCheckinDueNotification::class);
    Notification::assertSentTo($this->owner, GoalOverdueNotification::class);
});

test('the development reminders fire and bump next_review_at forward', function () {
    Notification::fake();

    $plan = HrDevelopmentGoal::query()->create([
        'employee_user_id' => $this->owner->id, 'manager_user_id' => $this->hr->id,
        'title' => 'Review me', 'category' => 'capability', 'status' => 'in_progress',
        'progress_percent' => 20, 'review_frequency' => 'monthly',
        'next_review_at' => now()->subDay()->toDateString(),
        'created_by' => $this->hr->id, 'updated_by' => $this->hr->id,
    ]);

    $this->artisan('hr:goal-reminders')->assertExitCode(0);

    expect($plan->fresh()->next_review_at->isFuture())->toBeTrue();
});

test('the weekly digest command runs and notifies owners', function () {
    Notification::fake();

    HrGoal::query()->create([
        'user_id' => $this->owner->id, 'created_by' => $this->hr->id,
        'title' => 'Digest goal', 'goal_type' => 'team', 'priority' => 'medium', 'status' => 'active',
        'confidence' => 'at_risk', 'start_date' => '2026-07-01', 'due_date' => '2026-09-30',
    ]);

    $this->artisan('hr:goal-weekly-digest')->assertExitCode(0);

    Notification::assertSentTo($this->owner, GoalWeeklyDigestNotification::class);
});

test('a development plan can link a formal competency and seeds a review date', function () {
    $competencyId = DB::table('hr_competencies')->insertGetId([
        'name' => 'Medication administration', 'category' => 'clinical',
        'proficiency_levels' => json_encode(['Novice', 'Competent', 'Expert']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->hr)->post('/hr/goals/development', [
        'employee_user_id' => $this->owner->id,
        'title' => 'Grow med competency',
        'competency_area' => 'Medication administration',
        'competency_id' => $competencyId,
        'category' => 'capability',
        'review_frequency' => 'monthly',
    ])->assertRedirect();

    $plan = HrDevelopmentGoal::query()->where('title', 'Grow med competency')->first();
    expect($plan->competency_id)->toBe($competencyId);
    expect($plan->next_review_at)->not->toBeNull();
});

test('the legacy development route redirects into the hub tab', function () {
    $this->actingAs($this->hr)->get('/hr/goals/development')
        ->assertRedirect('/hr/goals?tab=development');
});
