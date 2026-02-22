<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $supportRole = Role::where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $this->profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'work_email' => $this->staff->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'permanent',
        'contract_type' => 'individual',
        'start_date' => now()->subYears(2)->toDateString(),
        'is_active' => true,
    ]);
});

test('hr can create and complete offboarding workflow with dependency and sign-off checks', function () {
    HrOnboardingTemplate::query()->create([
        'tenant_id' => 1,
        'role' => 'offboarding:support_worker',
        'site_type' => 'all',
        'tasks' => [
            [
                'category' => 'it',
                'title' => 'Revoke account access',
                'description' => 'Disable all system accounts.',
                'is_required' => true,
                'sign_off_required' => false,
                'assigned_to_role' => 'hr',
            ],
            [
                'category' => 'payroll',
                'title' => 'Approve final pay',
                'description' => 'Validate leave payout and final wages.',
                'is_required' => true,
                'sign_off_required' => true,
                'assigned_to_role' => 'hr',
                'dependency_indexes' => [0],
            ],
        ],
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);

    $lastWorkingDay = now()->addDays(10)->toDateString();

    $this->actingAs($this->hr)
        ->post('/hr/offboarding', [
            'employee_profile_id' => $this->profile->id,
            'end_date' => $lastWorkingDay,
        ])
        ->assertSessionHas('success');

    $checklist = HrOffboardingChecklist::query()
        ->where('employee_profile_id', $this->profile->id)
        ->first();

    expect($checklist)->not->toBeNull();
    expect($checklist?->status)->toBe('pending');

    $tasks = HrOffboardingTask::query()
        ->where('offboarding_checklist_id', $checklist->id)
        ->orderBy('sort_order')
        ->get();

    expect($tasks)->toHaveCount(2);
    expect($tasks[1]->dependency_task_ids)->toContain($tasks[0]->id);

    $this->actingAs($this->hr)
        ->post("/hr/offboarding/tasks/{$tasks[1]->id}/complete", [
            'signed_off_by' => $this->hr->id,
        ])
        ->assertSessionHas('error');

    $this->actingAs($this->hr)
        ->post("/hr/offboarding/tasks/{$tasks[0]->id}/complete")
        ->assertSessionHas('success');

    $this->actingAs($this->hr)
        ->post("/hr/offboarding/tasks/{$tasks[1]->id}/complete", [
            'signed_off_by' => $this->hr->id,
        ])
        ->assertSessionHas('success');

    $checklist->refresh();
    $this->profile->refresh();

    expect($checklist->status)->toBe('completed');
    expect($checklist->completed_at)->not->toBeNull();
    expect($this->profile->is_active)->toBeFalse();
    expect(optional($this->profile->end_date)->toDateString())->toBe($lastWorkingDay);
});

test('offboarding dashboard exposes overdue summary and supports status filter', function () {
    $overdueChecklist = HrOffboardingChecklist::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $this->profile->id,
        'template_key' => 'offboarding:default',
        'status' => 'in_progress',
        'started_at' => now()->subDays(5),
        'due_date' => now()->subDay()->toDateString(),
        'created_by' => $this->hr->id,
    ]);

    HrOffboardingTask::query()->create([
        'offboarding_checklist_id' => $overdueChecklist->id,
        'category' => 'it',
        'title' => 'Disable accounts',
        'is_required' => true,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/offboarding?status=in_progress');
    $response->assertOk();

    expect($response->inertiaProps('summary.overdue'))->toBeGreaterThan(0);
    expect($response->inertiaProps('filters.status'))->toBe('in_progress');
});

test('offboarding checklist includes assigned asset return tasks for staff', function () {
    $asset = Asset::factory()->create([
        'created_by_user_id' => $this->hr->id,
        'updated_by_user_id' => $this->hr->id,
        'name' => 'Laptop Pro 15',
        'asset_tag' => 'AST-9911',
        'serial_number' => 'SN-OFFBOARD-9911',
    ]);

    $assignment = AssetAssignment::query()->create([
        'asset_id' => $asset->id,
        'assignee_type' => 'staff',
        'assignee_id' => $this->staff->id,
        'purpose' => 'Primary work device',
        'assigned_at' => now()->subDays(30),
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/offboarding', [
            'employee_profile_id' => $this->profile->id,
            'end_date' => now()->addDays(7)->toDateString(),
        ])
        ->assertSessionHas('success');

    $checklist = HrOffboardingChecklist::query()
        ->where('employee_profile_id', $this->profile->id)
        ->latest('id')
        ->first();

    expect($checklist)->not->toBeNull();

    $collectTask = HrOffboardingTask::query()
        ->where('offboarding_checklist_id', $checklist->id)
        ->where('title', 'Collect company equipment')
        ->first();

    $assetTask = HrOffboardingTask::query()
        ->where('offboarding_checklist_id', $checklist->id)
        ->where('category', 'assets')
        ->where('title', 'like', 'Return asset:%')
        ->first();

    expect($collectTask)->not->toBeNull();
    expect($assetTask)->not->toBeNull();
    expect($assetTask?->title)->toContain('Laptop Pro 15');
    expect($assetTask?->is_required)->toBeTrue();
    expect($assetTask?->sign_off_required)->toBeTrue();
    expect($assetTask?->dependency_task_ids ?? [])->toContain($collectTask?->id);
    expect((string) ($assetTask?->notes ?? ''))->toContain("asset_assignment_id={$assignment->id}");
});
