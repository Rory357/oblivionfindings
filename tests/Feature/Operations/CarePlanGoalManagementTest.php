<?php

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;

function grantGoalPerms(User $user, array $keys = ['care_plans.update']): void
{
    $keys = array_values(array_unique([...$keys, 'clients.viewAny']));
    $role = Role::query()->firstOrCreate(
        ['name' => 'goal_test_'.$user->id],
        ['label' => 'Goal Test', 'level' => 50, 'type' => 'custom'],
    );
    foreach ($keys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }
    $role->permissions()->sync(
        Permission::query()->whereIn('key', $keys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function makeGoalPlan(): array
{
    $user = User::factory()->create();
    grantGoalPerms($user);
    $client = Client::factory()->create([
        'site_id' => Site::factory()->create()->id,
    ]);
    $plan = CarePlan::query()->create([
        'client_id' => $client->id,
        'title' => 'Active plan',
        'status' => 'active',
        'plan_type' => 'support',
    ]);

    return [$user, $client, $plan];
}

it('auto-calculates progress from sub-goals', function () {
    [$user, , $plan] = makeGoalPlan();

    $this->actingAs($user)
        ->post("/operations/care-plans/{$plan->id}/goals", [
            'title' => 'Cook a meal',
            'category' => 'Daily living',
            'priority' => 'medium',
            'steps' => ['Pick recipe', 'Shop', 'Cook', 'Plate up'],
        ])->assertRedirect();

    $goal = CarePlanGoal::query()->where('care_plan_id', $plan->id)->firstOrFail();
    expect($goal->progress_percentage)->toBe(0)
        ->and($goal->status)->toBe('not_started')
        ->and($goal->steps()->count())->toBe(4);

    // Complete two of four → 50% / in_progress.
    foreach ($goal->steps()->orderBy('id')->limit(2)->get() as $step) {
        $this->actingAs($user)->put(
            "/operations/care-plans/{$plan->id}/goals/{$goal->id}/steps/{$step->id}",
            ['is_complete' => true],
        )->assertRedirect();
    }
    expect($goal->refresh()->progress_percentage)->toBe(50)
        ->and($goal->status)->toBe('in_progress');

    // Complete the rest → 100% / completed.
    foreach ($goal->steps()->where('is_complete', false)->get() as $step) {
        $this->actingAs($user)->put(
            "/operations/care-plans/{$plan->id}/goals/{$goal->id}/steps/{$step->id}",
            ['is_complete' => true],
        )->assertRedirect();
    }
    expect($goal->refresh()->progress_percentage)->toBe(100)
        ->and($goal->status)->toBe('completed');
});

it('honours a manual percentage only when there are no sub-goals', function () {
    [$user, , $plan] = makeGoalPlan();
    $goal = CarePlanGoal::query()->create([
        'care_plan_id' => $plan->id,
        'client_id' => $plan->client_id,
        'title' => 'No steps',
        'category' => 'Health',
        'priority' => 'medium',
    ]);

    // No steps → manual 40 sticks.
    $this->actingAs($user)->patch(
        "/operations/care-plans/{$plan->id}/goals/{$goal->id}/progress",
        ['progress_percentage' => 40],
    )->assertRedirect();
    expect($goal->refresh()->progress_percentage)->toBe(40)
        ->and($goal->status)->toBe('in_progress');

    // Add a step → auto-calc overrides to 0%.
    $this->actingAs($user)->post(
        "/operations/care-plans/{$plan->id}/goals/{$goal->id}/steps",
        ['title' => 'First step'],
    )->assertRedirect();
    expect($goal->refresh()->progress_percentage)->toBe(0);

    // With a step present, a manual 90 is ignored.
    $this->actingAs($user)->patch(
        "/operations/care-plans/{$plan->id}/goals/{$goal->id}/progress",
        ['progress_percentage' => 90],
    )->assertRedirect();
    expect($goal->refresh()->progress_percentage)->toBe(0);
});

it('logs and resolves hurdles as goal-linked progress notes', function () {
    [$user, , $plan] = makeGoalPlan();
    $goal = CarePlanGoal::query()->create([
        'care_plan_id' => $plan->id,
        'client_id' => $plan->client_id,
        'title' => 'Goal',
        'category' => 'Community',
        'priority' => 'medium',
    ]);

    $this->actingAs($user)->post(
        "/operations/care-plans/{$plan->id}/goals/{$goal->id}/hurdles",
        ['content' => 'Transport keeps falling through'],
    )->assertRedirect();

    $note = ClientNote::query()
        ->where('care_plan_goal_id', $goal->id)
        ->where('category', 'goal_hurdle')
        ->firstOrFail();
    expect((bool) $note->is_flagged)->toBeTrue();

    $this->actingAs($user)->patch(
        "/operations/care-plans/{$plan->id}/goals/{$goal->id}/hurdles/{$note->id}/resolve",
    )->assertRedirect();
    expect((bool) $note->refresh()->is_flagged)->toBeFalse();
});

it('returns goal detail as JSON', function () {
    [$user, , $plan] = makeGoalPlan();
    $goal = CarePlanGoal::query()->create([
        'care_plan_id' => $plan->id,
        'client_id' => $plan->client_id,
        'title' => 'Goal',
        'category' => 'Finance',
        'priority' => 'medium',
    ]);
    $goal->steps()->create(['title' => 'Step 1', 'sort_order' => 1]);

    $this->actingAs($user)
        ->getJson("/operations/care-plans/{$plan->id}/goals/{$goal->id}")
        ->assertOk()
        ->assertJsonPath('goal.title', 'Goal')
        ->assertJsonCount(1, 'steps')
        ->assertJsonStructure(['goal', 'steps', 'hurdles', 'progress_log']);
});

it('forbids goal writes without the care plan permission', function () {
    [, , $plan] = makeGoalPlan();
    $stranger = User::factory()->create();
    $goal = CarePlanGoal::query()->create([
        'care_plan_id' => $plan->id,
        'client_id' => $plan->client_id,
        'title' => 'Goal',
        'category' => 'Health',
        'priority' => 'medium',
    ]);

    $this->actingAs($stranger)->post(
        "/operations/care-plans/{$plan->id}/goals/{$goal->id}/steps",
        ['title' => 'x'],
    )->assertForbidden();
});

it('rejects a goal that belongs to another care plan', function () {
    [$user, $client, $plan] = makeGoalPlan();
    $otherPlan = CarePlan::query()->create([
        'client_id' => $client->id,
        'title' => 'Other',
        'status' => 'active',
        'plan_type' => 'support',
    ]);
    $goal = CarePlanGoal::query()->create([
        'care_plan_id' => $otherPlan->id,
        'client_id' => $client->id,
        'title' => 'Elsewhere',
        'category' => 'Health',
        'priority' => 'medium',
    ]);

    $this->actingAs($user)
        ->getJson("/operations/care-plans/{$plan->id}/goals/{$goal->id}")
        ->assertNotFound();
});
