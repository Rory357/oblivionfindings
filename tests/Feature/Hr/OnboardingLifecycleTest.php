<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function lifecycleProfile(): HrEmployeeProfile
{
    $user = User::factory()->create();

    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-LC-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => test()->site->id,
        'secondary_site_ids' => [],
        'start_date' => now()->addDays(10)->toDateString(),
        'is_active' => true,
    ]);
}

function lifecycleChecklist(HrEmployeeProfile $profile): HrOnboardingChecklist
{
    $checklist = HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'pending',
        'started_at' => now(),
        'due_date' => now()->addDays(20),
        'created_by' => $profile->user_id,
    ]);

    HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'general',
        'title' => 'Sign contract',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
    ]);

    return $checklist;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'position_role' => 'hr',
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
    ]);
});

test('an ad-hoc task can be added then completed and reopened', function () {
    $checklist = lifecycleChecklist(lifecycleProfile());

    $this->actingAs($this->hr)
        ->post("/hr/onboarding/{$checklist->id}/tasks", [
            'title' => 'Order uniform',
            'category' => 'general',
            'is_required' => false,
        ])
        ->assertRedirect();

    $task = HrOnboardingTask::query()->where('title', 'Order uniform')->firstOrFail();

    $this->actingAs($this->hr)->post("/hr/onboarding/tasks/{$task->id}/complete")->assertRedirect();
    expect($task->fresh()->status)->toBe('completed');

    $this->actingAs($this->hr)->post("/hr/onboarding/tasks/{$task->id}/uncomplete")->assertRedirect();
    expect($task->fresh()->status)->toBe('pending');
});

test('a task can be edited and reassigned', function () {
    $checklist = lifecycleChecklist(lifecycleProfile());
    $task = $checklist->tasks()->first();
    $newOwner = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $newOwner->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
    ]);

    $this->actingAs($this->hr)
        ->patch("/hr/onboarding/tasks/{$task->id}", [
            'title' => 'Sign IEA',
            'assigned_to_user_id' => $newOwner->id,
        ])
        ->assertRedirect();

    $task->refresh();
    expect($task->title)->toBe('Sign IEA');
    expect((int) $task->assigned_to_user_id)->toBe($newOwner->id);
});

test('a sign-off task requires a sign-off user to complete', function () {
    $checklist = lifecycleChecklist(lifecycleProfile());
    $task = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'compliance',
        'title' => 'Police vet',
        'is_required' => true,
        'sort_order' => 2,
        'sign_off_required' => true,
        'status' => 'pending',
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/onboarding/tasks/{$task->id}/complete")
        ->assertSessionHas('error');
    expect($task->fresh()->status)->toBe('pending');

    $this->actingAs($this->hr)
        ->post("/hr/onboarding/tasks/{$task->id}/complete", ['signed_off_by' => $this->hr->id])
        ->assertRedirect();
    expect($task->fresh()->status)->toBe('completed');
});

test('a checklist can be marked complete and archived', function () {
    $checklist = lifecycleChecklist(lifecycleProfile());

    $this->actingAs($this->hr)->post("/hr/onboarding/{$checklist->id}/complete")->assertRedirect();
    expect($checklist->fresh()->status)->toBe('completed');

    $this->actingAs($this->hr)
        ->post("/hr/onboarding/{$checklist->id}/status", ['status' => 'archived'])
        ->assertRedirect();
    expect($checklist->fresh()->status)->toBe('archived');
});

test('a template can be duplicated', function () {
    $template = HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_active' => true,
        'tasks' => [['category' => 'general', 'title' => 'X', 'is_required' => true, 'sort_order' => 1]],
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/onboarding/templates/{$template->id}/duplicate")
        ->assertRedirect();

    expect(HrOnboardingTemplate::query()->where('role', 'support_worker')->count())->toBe(2);
});

test('saving a template persists a per-task course_code for induction auto-enrol', function () {
    $this->actingAs($this->hr)
        ->put('/hr/onboarding/templates', [
            'role' => 'support_worker',
            'site_type' => 'all',
            'is_active' => true,
            'tasks' => [
                ['category' => 'induction', 'title' => 'H&S induction', 'is_required' => true, 'sort_order' => 1, 'course_code' => 'HS-IND'],
            ],
        ])
        ->assertRedirect();

    $template = HrOnboardingTemplate::query()->where('role', 'support_worker')->firstOrFail();
    expect($template->tasks[0]['course_code'])->toBe('HS-IND');
});

test('the IT & Provisioning wireframe renders for an onboarding manager', function () {
    $this->actingAs($this->hr)->get('/it')->assertOk();
});

test('bulk archive closes the selected checklists', function () {
    $a = lifecycleChecklist(lifecycleProfile());
    $b = lifecycleChecklist(lifecycleProfile());

    $this->actingAs($this->hr)
        ->post('/hr/onboarding/bulk', ['action' => 'archive', 'checklist_ids' => [$a->id, $b->id]])
        ->assertRedirect();

    expect($a->fresh()->status)->toBe('archived');
    expect($b->fresh()->status)->toBe('archived');
});
