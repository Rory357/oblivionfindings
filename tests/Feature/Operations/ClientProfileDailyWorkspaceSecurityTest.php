<?php

use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ConsentType;
use App\Models\FamilyNote;
use App\Models\FamilyPortalSetting;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\TimelineEventComment;
use App\Models\User;

function grantClientProfileDailyWorkspacePermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_daily_workspace_'.$user->id],
        ['label' => 'Client Profile Daily Workspace', 'level' => 50, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

it('rejects support worker assignments from another organisation', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, [
        'clients.assignments.update',
        'clients.update',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $foreignWorker = User::factory()->create([
        'organization_id' => 2,
        'role' => 'support_worker',
    ]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}")
        ->put("/operations/clients/{$client->id}/assignments", [
            'user_ids' => [$foreignWorker->id],
            '_modal' => true,
        ])
        ->assertSessionHasErrors('user_ids.0');

    $this->assertDatabaseMissing('client_user', [
        'client_id' => $client->id,
        'user_id' => $foreignWorker->id,
    ]);
});

it('assigns only same-organisation care-delivery roles', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, [
        'clients.assignments.update',
        'clients.update',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $supportWorker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
    ]);
    $financeUser = User::factory()->create([
        'organization_id' => 1,
        'role' => 'finance',
    ]);

    $this->actingAs($manager)
        ->put("/operations/clients/{$client->id}/assignments", [
            'user_ids' => [$supportWorker->id],
            '_modal' => true,
        ])
        ->assertRedirect();
    $this->assertDatabaseHas('client_user', [
        'client_id' => $client->id,
        'user_id' => $supportWorker->id,
    ]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}")
        ->put("/operations/clients/{$client->id}/assignments", [
            'user_ids' => [$financeUser->id],
            '_modal' => true,
        ])
        ->assertSessionHasErrors('user_ids.0');
    $this->assertDatabaseMissing('client_user', [
        'client_id' => $client->id,
        'user_id' => $financeUser->id,
    ]);
});

it('does not expose or mutate assignments without actual client update authority', function () {
    $assigner = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($assigner, [
        'clients.assignments.update',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $supportWorker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
    ]);

    $this->actingAs($assigner)
        ->getJson("/operations/clients/{$client->id}/assignments?modal=1")
        ->assertForbidden();
    $this->actingAs($assigner)
        ->put("/operations/clients/{$client->id}/assignments", [
            'user_ids' => [$supportWorker->id],
        ])
        ->assertForbidden();
});

it('rejects onboarding workflows for a client from another organisation', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, ['clients.create']);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);

    $this->actingAs($manager)
        ->from('/operations/onboarding/create')
        ->post('/operations/onboarding', [
            'client_id' => $foreignClient->id,
        ])
        ->assertSessionHasErrors('client_id');

    $this->assertDatabaseMissing('client_onboarding_workflows', [
        'organization_id' => 1,
        'client_id' => $foreignClient->id,
    ]);
});

it('rejects onboarding step assignees from another organisation', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, ['clients.create']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $workflow = ClientOnboardingWorkflow::query()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'status' => 'in_progress',
        'started_at' => now(),
        'created_by' => $manager->id,
    ]);
    $foreignAssignee = User::factory()->create(['organization_id' => 2]);

    $this->actingAs($manager)
        ->from("/operations/onboarding/{$workflow->id}")
        ->post("/operations/onboarding/{$workflow->id}/steps", [
            'step_name' => 'Arrange introductory visit',
            'assigned_to' => $foreignAssignee->id,
        ])
        ->assertSessionHasErrors('assigned_to');

    $this->assertDatabaseMissing('client_onboarding_steps', [
        'workflow_id' => $workflow->id,
        'assigned_to' => $foreignAssignee->id,
    ]);
});

it('rejects a daily note linked to another clients shift', function () {
    $worker = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($worker, [
        'clients.viewAny',
        'progress_notes.create',
    ]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $otherClient = Client::factory()->create(['organization_id' => 1]);
    $otherClientsShift = Shift::factory()->create([
        'client_id' => $otherClient->id,
        'user_id' => $worker->id,
        'created_by' => $worker->id,
    ]);

    $this->actingAs($worker)
        ->from("/operations/clients/{$client->id}?tab=daily_notes")
        ->post("/operations/clients/{$client->id}/daily-notes", [
            'body' => 'Settled after lunch.',
            'shift_id' => $otherClientsShift->id,
        ])
        ->assertSessionHasErrors('shift_id');

    $this->assertDatabaseMissing('client_notes', [
        'client_id' => $client->id,
        'shift_id' => $otherClientsShift->id,
    ]);
});

it('does not delete a timeline comment through another clients route', function () {
    $worker = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($worker, ['clients.viewAny']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $otherClient = Client::factory()->create(['organization_id' => 1]);
    $otherClientsEvent = TimelineEvent::factory()->create([
        'client_id' => $otherClient->id,
        'actor_user_id' => $worker->id,
        'created_by' => $worker->id,
    ]);
    $comment = TimelineEventComment::query()->create([
        'timeline_event_id' => $otherClientsEvent->id,
        'user_id' => $worker->id,
        'body' => 'This belongs to the other client.',
    ]);

    $this->actingAs($worker)
        ->delete("/clients/{$client->id}/timeline/comments/{$comment->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('timeline_event_comments', [
        'id' => $comment->id,
        'timeline_event_id' => $otherClientsEvent->id,
    ]);
});

it('does not delete a portal family note through another clients route', function () {
    $familyMember = User::factory()->create(['organization_id' => 1]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $otherClient = Client::factory()->create(['organization_id' => 1]);
    $client->portalUsers()->attach($familyMember->id, [
        'relation' => 'next_of_kin',
    ]);
    NextOfKin::query()->create([
        'client_id' => $client->id,
        'user_id' => $familyMember->id,
        'relationship' => 'guardian',
    ]);
    $familyConsentType = ConsentType::factory()->create([
        'name' => 'Information Sharing with Whānau / Family',
        'category' => 'communication',
    ]);
    ClientConsent::query()->create([
        'client_id' => $client->id,
        'consent_type_id' => $familyConsentType->id,
        'status' => 'given',
        'given_at' => now(),
        'expires_at' => now()->addMonth(),
        'given_by_user_id' => $familyMember->id,
        'given_by_relationship' => 'next_of_kin',
        'given_method' => 'portal',
        'created_by' => $familyMember->id,
        'updated_by' => $familyMember->id,
    ]);
    FamilyPortalSetting::query()->create([
        'client_id' => $client->id,
        'show_care_notes' => true,
    ]);
    $otherClientsNote = FamilyNote::query()->create([
        'client_id' => $otherClient->id,
        'created_by' => $familyMember->id,
        'title' => 'Other client note',
        'description' => 'This record must stay with its parent client.',
        'note_type' => 'note',
        'priority' => 'normal',
        'status' => 'open',
        'visibility' => 'portal',
    ]);

    $this->actingAs($familyMember)
        ->delete("/portal/clients/{$client->id}/family-notes/{$otherClientsNote->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('family_notes', [
        'id' => $otherClientsNote->id,
        'client_id' => $otherClient->id,
        'deleted_at' => null,
    ]);
});

it('forbids meal preference changes for a client in another organisation', function () {
    $kitchenWorker = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($kitchenWorker, ['sites.meals.view']);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);

    $this->actingAs($kitchenWorker)
        ->post("/clients/{$foreignClient->id}/meal-preferences/dislikes", [
            'free_text_name' => 'Very spicy food',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('client_meal_dislikes', [
        'client_id' => $foreignClient->id,
        'free_text_name' => 'Very spicy food',
    ]);
});

it('forbids starting a care plan review across organisations', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, ['care_plans.update']);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);
    $foreignPlan = CarePlan::query()->create([
        'organization_id' => 2,
        'client_id' => $foreignClient->id,
        'title' => 'Foreign organisation support plan',
        'status' => 'active',
        'plan_type' => 'support_plan',
        'version' => 1,
    ]);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$foreignPlan->id}/start-review")
        ->assertForbidden();

    expect(CarePlan::query()
        ->where('parent_id', $foreignPlan->id)
        ->exists())->toBeFalse();
});

it('rejects creating or reassigning care plans across organisations', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, [
        'care_plans.create',
        'care_plans.update',
    ]);
    $sameOrgClient = Client::factory()->create(['organization_id' => 1]);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$foreignClient->id}?tab=care_plans")
        ->post('/operations/care-plans', [
            'client_id' => $foreignClient->id,
            'title' => 'Cross-organisation plan',
            'plan_type' => 'support_plan',
        ])
        ->assertSessionHasErrors('client_id');

    $plan = CarePlan::query()->create([
        'organization_id' => 1,
        'client_id' => $sameOrgClient->id,
        'title' => 'Same organisation plan',
        'status' => 'draft',
        'plan_type' => 'support_plan',
        'version' => 1,
    ]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$sameOrgClient->id}?tab=care_plans")
        ->put("/operations/care-plans/{$plan->id}", [
            'client_id' => $foreignClient->id,
        ])
        ->assertSessionHasErrors('client_id');

    expect($plan->fresh()->client_id)->toBe($sameOrgClient->id);
});

it('forbids completing a care plan review across organisations', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, ['care_plans.update']);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);
    $foreignReview = CarePlan::query()->create([
        'organization_id' => 2,
        'client_id' => $foreignClient->id,
        'title' => 'Foreign review',
        'status' => 'review',
        'plan_type' => 'support_plan',
        'version' => 2,
        'content' => [
            'domains' => [[
                'key' => 'communication',
                'label' => 'Communication',
                'status' => 'active',
            ]],
        ],
    ]);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$foreignReview->id}/complete-review")
        ->assertForbidden();

    expect($foreignReview->fresh()->status)->toBe('review');
});

it('copies goals steps domains and sign off context into a care plan review', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, ['care_plans.update']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $plan = CarePlan::query()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'title' => 'Active support plan',
        'status' => 'active',
        'plan_type' => 'support_plan',
        'version' => 1,
        'content' => [
            'domains' => [[
                'key' => 'daily_living',
                'label' => 'Daily living',
                'status' => 'active',
                'strategies' => [[
                    'text' => 'Use visual prompts.',
                    'owner' => 'Key worker',
                ]],
            ]],
        ],
    ]);
    $goal = $plan->goals()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'title' => 'Prepare breakfast independently',
        'category' => 'daily_living',
        'priority' => 'high',
        'status' => 'in_progress',
        'progress_percentage' => 50,
        'created_by' => $manager->id,
    ]);
    $goal->steps()->create([
        'organization_id' => 1,
        'title' => 'Collect ingredients',
        'sort_order' => 1,
        'is_complete' => true,
        'completed_at' => now(),
        'completed_by' => $manager->id,
        'created_by' => $manager->id,
    ]);
    $plan->signOffs()->create([
        'organization_id' => 1,
        'party_role' => 'client',
        'party_name' => 'Aroha Client',
        'agreed_on' => today(),
        'method' => 'in_person',
        'acknowledgement' => 'Agreed to the current goals.',
        'recorded_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$plan->id}/start-review")
        ->assertRedirect("/operations/clients/{$client->id}?tab=care_plans");

    $review = CarePlan::query()
        ->where('parent_id', $plan->id)
        ->where('status', 'review')
        ->firstOrFail();
    $reviewGoal = $review->goals()->firstOrFail();

    expect(data_get($review->content, 'domains.0.strategies.0.text'))
        ->toBe('Use visual prompts.')
        ->and($reviewGoal->title)->toBe($goal->title)
        ->and($reviewGoal->steps()->count())->toBe(1)
        ->and($reviewGoal->steps()->value('title'))->toBe('Collect ingredients')
        ->and($review->signOffs()->count())->toBe(0)
        ->and(data_get($review->content, 'review_context.prior_sign_offs.0.party_name'))
        ->toBe('Aroha Client');
});

it('starts only one review from an active plan and rejects invalid source states', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, ['care_plans.update']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $draft = CarePlan::query()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'title' => 'Draft plan',
        'status' => 'draft',
        'plan_type' => 'support',
        'created_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->from("/operations/clients/{$client->id}?tab=care_plans")
        ->post("/operations/care-plans/{$draft->id}/start-review")
        ->assertSessionHasErrors('status');

    $active = CarePlan::query()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'title' => 'Active plan',
        'status' => 'active',
        'plan_type' => 'support',
        'created_by' => $manager->id,
    ]);
    $active->goals()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'title' => 'Stay connected',
        'category' => 'Community',
        'priority' => 'medium',
        'created_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$active->id}/start-review")
        ->assertRedirect();
    $this->actingAs($manager)
        ->post("/operations/care-plans/{$active->id}/start-review")
        ->assertRedirect();

    expect(CarePlan::query()
        ->where('parent_id', $active->id)
        ->where('status', 'review')
        ->count())->toBe(1);
});

it('completes a review by archiving the prior version and retaining review notes', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientProfileDailyWorkspacePermissions($manager, ['care_plans.update']);
    $client = Client::factory()->create(['organization_id' => 1]);
    $active = CarePlan::query()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'title' => 'Active support plan',
        'status' => 'active',
        'plan_type' => 'support',
        'content' => ['domains' => ['communication' => ['summary' => 'Use plain language']]],
        'created_by' => $manager->id,
    ]);
    $active->goals()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'title' => 'Choose weekly activities',
        'category' => 'Choice',
        'priority' => 'medium',
        'created_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$active->id}/start-review")
        ->assertRedirect();
    $review = CarePlan::query()
        ->where('parent_id', $active->id)
        ->where('status', 'review')
        ->sole();
    $review->signOffs()->create([
        'organization_id' => 1,
        'party_role' => 'client',
        'party_name' => 'Fresh review signatory',
        'agreed_on' => today(),
        'recorded_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/complete-review", [
            'review_notes' => 'Whānau confirmed the updated communication approach.',
        ])
        ->assertRedirect();

    expect($active->fresh()->status)->toBe('archived')
        ->and($review->fresh()->status)->toBe('active')
        ->and(data_get($review->fresh()->content, 'review_context.review_notes'))
        ->toBe('Whānau confirmed the updated communication approach.');
});
