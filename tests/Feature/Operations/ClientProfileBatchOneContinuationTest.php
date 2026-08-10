<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function grantClientProfileBatchOneContinuationPermissions(User $user, array $keys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_batch_one_continuation_'.$user->id],
        ['label' => 'Client Profile Batch One Continuation', 'level' => 50, 'type' => 'custom'],
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

function clientProfileBatchOneContinuationUserAtSite(
    Site $site,
    array $permissionKeys,
    string $role = 'manager',
): User {
    $user = User::factory()->create([
        'approved_at' => now(),
        'role' => $role,
    ]);
    grantClientProfileBatchOneContinuationPermissions($user, $permissionKeys);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user;
}

it('emits independent Batch 1 action capabilities and canonical HR staff preparation', function () {
    $site = Site::factory()->create();
    $viewer = clientProfileBatchOneContinuationUserAtSite($site, [
        'clients.viewAny',
        'care_plans.viewAny',
        'care_plans.update',
        'progress_notes.create',
        'family_portal.viewAny',
        'medications.view',
        'medications.administer.record',
        'risks.viewAny',
        'calendar.viewAny',
        'onboarding.create',
        'hr.onboarding.view',
    ]);
    $worker = User::factory()->create([
        'name' => 'Prepared Worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $client->supportWorkers()->attach($worker->id);
    CarePlan::query()->create([
        'client_id' => $client->id,
        'title' => 'Working support plan',
        'status' => 'active',
        'plan_type' => 'support',
        'version' => 1,
        'created_by' => $viewer->id,
    ]);

    $employee = HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'position_title' => 'Support Worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
    $checklist = HrOnboardingChecklist::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'in_progress',
        'due_date' => now()->addWeek(),
    ]);
    $checklist->tasks()->createMany([
        [
            'category' => 'orientation',
            'title' => 'Read the support plan',
            'status' => 'completed',
            'sort_order' => 1,
        ],
        [
            'category' => 'orientation',
            'title' => 'Complete shadow shift',
            'status' => 'pending',
            'sort_order' => 2,
        ],
    ]);

    $this->actingAs($viewer)
        ->get("/operations/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/clients/show')
            ->where('can.manage_care_plan_goals', true)
            ->where('can.edit_path_plan', false)
            ->where('can.create_daily_note', true)
            ->where('can.create_quick_note', true)
            ->where('can.create_communication_note', true)
            ->where('can.view_family_chat', true)
            ->where('can.send_family_chat', false)
            ->where('can.record_medication_administration', true)
            ->where('can.update_risk_level', false)
            ->where('can.navigate_care_plans', true)
            ->where('can.navigate_risks', true)
            ->where('can.navigate_medical', true)
            ->where('can.navigate_calendar', true)
            ->where('can.create_onboarding_workflow', true)
            ->where('can.manage_onboarding_workflow', false)
            ->where('can.manage_onboarding_checklist', false)
            ->where('can.view_hr_onboarding', true)
            ->where('staff_preparation.summary.assigned', 1)
            ->where('staff_preparation.summary.in_progress', 1)
            ->where('staff_preparation.workers.0.name', 'Prepared Worker')
            ->where('staff_preparation.workers.0.tasks_total', 2)
            ->where('staff_preparation.workers.0.tasks_completed', 1));
});

it('keeps onboarding workflow creation distinct from workflow and checklist management', function () {
    $site = Site::factory()->create();
    $creator = clientProfileBatchOneContinuationUserAtSite($site, [
        'clients.viewAny',
        'onboarding.create',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);

    $this->actingAs($creator)
        ->post("/operations/clients/{$client->id}/onboarding-workflow")
        ->assertRedirect();

    $this->assertDatabaseHas('client_onboarding_workflows', [
        'client_id' => $client->id,
        'status' => 'in_progress',
        'created_by' => $creator->id,
    ]);

    $workflowId = $client->onboardingWorkflows()->value('id');
    $this->actingAs($creator)
        ->post("/operations/onboarding/{$workflowId}/steps", [
            'step_name' => 'Unauthorized management step',
        ])
        ->assertForbidden();
    $this->actingAs($creator)
        ->post("/operations/clients/{$client->id}/onboarding/profile", [
            'checked' => true,
        ])
        ->assertForbidden();
});
