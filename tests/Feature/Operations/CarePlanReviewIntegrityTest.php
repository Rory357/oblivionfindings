<?php

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\CarePlanSignOff;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TimelineEvent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function grantCarePlanReviewIntegrityPermissions(User $user, array $keys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'care_plan_review_integrity_'.$user->id],
        ['label' => 'Care Plan Review Integrity', 'level' => 50, 'type' => 'custom'],
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

function makeCarePlanReviewIntegrityPlan(
    User $user,
    Client $client,
    array $attributes = [],
): CarePlan {
    return CarePlan::query()->create(array_merge([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'title' => 'Published support plan',
        'status' => 'active',
        'plan_type' => 'support_plan',
        'version' => 1,
        'created_by' => $user->id,
        'content' => [
            'domains' => [[
                'key' => 'daily_living',
                'label' => 'Daily living',
                'status' => 'active',
            ]],
        ],
    ], $attributes));
}

function addCarePlanReviewIntegrityGoal(
    CarePlan $plan,
    User $user,
    string $title,
): CarePlanGoal {
    return $plan->goals()->create([
        'organization_id' => $plan->organization_id,
        'client_id' => $plan->client_id,
        'title' => $title,
        'category' => 'daily_living',
        'priority' => 'medium',
        'status' => 'in_progress',
        'progress_percentage' => 25,
        'created_by' => $user->id,
    ]);
}

it('hydrates the in-progress review as the complete working care plan', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($manager, [
        'clients.viewAny',
        'care_plans.viewAny',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $active = makeCarePlanReviewIntegrityPlan($manager, $client);
    addCarePlanReviewIntegrityGoal($active, $manager, 'Published goal');
    $active->signOffs()->create([
        'organization_id' => 1,
        'party_role' => 'client',
        'party_name' => 'Published signatory',
        'agreed_on' => today()->subMonth(),
        'recorded_by' => $manager->id,
    ]);

    $review = makeCarePlanReviewIntegrityPlan($manager, $client, [
        'title' => 'Review working copy',
        'status' => 'review',
        'version' => 2,
        'parent_id' => $active->id,
    ]);
    $reviewGoal = addCarePlanReviewIntegrityGoal($review, $manager, 'Review-only goal');
    $reviewSignOff = $review->signOffs()->create([
        'organization_id' => 1,
        'party_role' => 'whanau',
        'party_name' => 'Fresh review signatory',
        'agreed_on' => today(),
        'recorded_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get("/operations/clients/{$client->id}?tab=care_plans")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('care_plans_summary.active_plan.id', $active->id)
            ->where('care_plans_summary.working_plan.id', $review->id)
            ->where('care_plans_summary.working_plan.status', 'review')
            ->where('care_plans_summary.working_plan.goals.0.id', $reviewGoal->id)
            ->where('care_plans_summary.working_plan.sign_offs.0.id', $reviewSignOff->id));
});

it('rejects lifecycle-only statuses during care plan creation', function (string $status) {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($manager, ['care_plans.create']);
    $client = Client::factory()->create(['organization_id' => 1]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->post('/operations/care-plans', [
            'client_id' => $client->id,
            'title' => 'Invalid lifecycle plan',
            'plan_type' => 'support_plan',
            'status' => $status,
        ])
        ->assertSessionHasErrors('status');

    expect(CarePlan::query()
        ->where('client_id', $client->id)
        ->where('title', 'Invalid lifecycle plan')
        ->exists())->toBeFalse();
})->with(['review', 'archived']);

it('rejects arbitrary status transitions through the generic update endpoint', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($manager, ['care_plans.update']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $active = makeCarePlanReviewIntegrityPlan($manager, $client);
    $draft = makeCarePlanReviewIntegrityPlan($manager, $client, [
        'title' => 'Draft plan',
        'status' => 'draft',
        'version' => 1,
    ]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->put("/operations/care-plans/{$active->id}", ['status' => 'archived'])
        ->assertSessionHasErrors('status');

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->put("/operations/care-plans/{$draft->id}", ['status' => 'review'])
        ->assertSessionHasErrors('status');

    expect($active->fresh()->status)->toBe('active')
        ->and($draft->fresh()->status)->toBe('draft');
});

it('keeps archived care plan versions immutable', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($manager, ['care_plans.update']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $archived = makeCarePlanReviewIntegrityPlan($manager, $client, [
        'title' => 'Archived plan',
        'status' => 'archived',
    ]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->put("/operations/care-plans/{$archived->id}", ['title' => 'Rewritten history'])
        ->assertSessionHasErrors('care_plan');

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->post("/operations/care-plans/{$archived->id}/goals", [
            'title' => 'Historical goal',
            'category' => 'daily_living',
            'priority' => 'medium',
        ])
        ->assertSessionHasErrors('care_plan');

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->post("/operations/care-plans/{$archived->id}/sign-offs", [
            'party_role' => 'client',
            'party_name' => 'Historical signatory',
            'agreed_on' => today()->toDateString(),
        ])
        ->assertSessionHasErrors('care_plan');

    expect($archived->fresh()->title)->toBe('Archived plan')
        ->and($archived->goals()->count())->toBe(0)
        ->and($archived->signOffs()->count())->toBe(0);
});

it('freezes the published source version while its review copy is in progress', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($manager, ['care_plans.update']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $active = makeCarePlanReviewIntegrityPlan($manager, $client);
    $review = makeCarePlanReviewIntegrityPlan($manager, $client, [
        'title' => 'Review working copy',
        'status' => 'review',
        'version' => 2,
        'parent_id' => $active->id,
    ]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->put("/operations/care-plans/{$active->id}", ['title' => 'Changed published plan'])
        ->assertSessionHasErrors('care_plan');

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->post("/operations/care-plans/{$active->id}/goals", [
            'title' => 'Goal on published source',
            'category' => 'daily_living',
            'priority' => 'medium',
        ])
        ->assertSessionHasErrors('care_plan');

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->post("/operations/care-plans/{$active->id}/sign-offs", [
            'party_role' => 'client',
            'party_name' => 'Wrong version signatory',
            'agreed_on' => today()->toDateString(),
        ])
        ->assertSessionHasErrors('care_plan');

    expect($active->fresh()->title)->toBe('Published support plan')
        ->and($active->goals()->count())->toBe(0)
        ->and($active->signOffs()->count())->toBe(0)
        ->and($review->fresh()->status)->toBe('review');
});

it('requires a fresh review-version sign-off before review completion', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($manager, ['care_plans.update']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $active = makeCarePlanReviewIntegrityPlan($manager, $client);
    $active->signOffs()->create([
        'organization_id' => 1,
        'party_role' => 'client',
        'party_name' => 'Prior-version signatory',
        'agreed_on' => today()->subMonth(),
        'recorded_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$active->id}/start-review")
        ->assertRedirect();
    $review = CarePlan::query()
        ->where('parent_id', $active->id)
        ->where('status', 'review')
        ->sole();

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->post("/operations/care-plans/{$review->id}/complete-review")
        ->assertSessionHasErrors('sign_offs');

    expect($active->fresh()->status)->toBe('active')
        ->and($review->fresh()->status)->toBe('review');

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/sign-offs", [
            'party_role' => 'client',
            'party_name' => 'Fresh review signatory',
            'agreed_on' => today()->toDateString(),
            'method' => 'in_person',
        ])
        ->assertRedirect();

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/complete-review", [
            'review_notes' => 'The updated plan was agreed.',
        ])
        ->assertRedirect("/operations/clients/{$client->id}?tab=care_plans");

    expect($active->fresh()->status)->toBe('archived')
        ->and($review->fresh()->status)->toBe('active');
});

it('retracts the canonical timeline projection when a sign-off is removed', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($manager, ['care_plans.update']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $plan = makeCarePlanReviewIntegrityPlan($manager, $client);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$plan->id}/sign-offs", [
            'party_role' => 'client',
            'party_name' => 'Aroha Client',
            'agreed_on' => today()->toDateString(),
            'method' => 'in_person',
        ])
        ->assertRedirect();

    $signOff = CarePlanSignOff::query()->where('care_plan_id', $plan->id)->sole();
    expect(TimelineEvent::query()
        ->where('source_type', CarePlanSignOff::class)
        ->where('source_id', $signOff->id)
        ->exists())->toBeTrue();

    $this->actingAs($manager)
        ->delete("/operations/care-plans/{$plan->id}/sign-offs/{$signOff->id}")
        ->assertRedirect();

    expect(CarePlanSignOff::query()->find($signOff->id))->toBeNull()
        ->and(TimelineEvent::query()
            ->where('source_type', CarePlanSignOff::class)
            ->where('source_id', $signOff->id)
            ->exists())->toBeFalse();
});

it('uses the dedicated delete capability for current care plans', function () {
    $deleteManager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($deleteManager, ['care_plans.delete']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $draft = makeCarePlanReviewIntegrityPlan($deleteManager, $client, [
        'title' => 'Disposable draft',
        'status' => 'draft',
    ]);

    $this->actingAs($deleteManager)
        ->delete("/operations/care-plans/{$draft->id}")
        ->assertRedirect();

    expect(CarePlan::withTrashed()->findOrFail($draft->id)->trashed())->toBeTrue();

    $updateManager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($updateManager, ['care_plans.update']);
    $otherDraft = makeCarePlanReviewIntegrityPlan($updateManager, $client, [
        'title' => 'Protected draft',
        'status' => 'draft',
    ]);

    $this->actingAs($updateManager)
        ->delete("/operations/care-plans/{$otherDraft->id}")
        ->assertForbidden();

    expect($otherDraft->fresh())->not->toBeNull();
});

it('does not delete archived care plan history', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantCarePlanReviewIntegrityPermissions($manager, ['care_plans.delete']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $archived = makeCarePlanReviewIntegrityPlan($manager, $client, [
        'title' => 'Historical plan',
        'status' => 'archived',
    ]);

    $this->actingAs($manager)
        ->from('/operations/care-plans')
        ->delete("/operations/care-plans/{$archived->id}")
        ->assertSessionHasErrors('care_plan');

    expect($archived->fresh())->not->toBeNull();
});
