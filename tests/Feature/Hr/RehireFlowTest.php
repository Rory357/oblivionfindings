<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    // A former employee: inactive profile + login revoked on offboarding.
    $this->leaver = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => null,
    ]);

    $hrRole = Role::where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $supportRole = Role::where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->leaver->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $this->profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->leaver->id,
        'employee_number' => 'EMP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'work_email' => $this->leaver->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'contract_type' => 'individual',
        'start_date' => now()->subYears(3)->toDateString(),
        'end_date' => now()->subMonths(6)->toDateString(),
        'termination_reason' => 'resigned',
        'is_active' => false,
    ]);
});

test('rehiring an inactive profile reactivates it, archives the prior stint and restores login', function () {
    $previousStart = $this->profile->start_date->toDateString();
    $previousEnd = $this->profile->end_date->toDateString();
    $newStart = now()->addDays(7)->toDateString();

    $this->actingAs($this->hr)
        ->post("/hr/people/{$this->profile->id}/rehire", [
            'start_date' => $newStart,
            'position_title' => 'Senior Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'part_time',
            'hours_per_week' => 24,
            'send_invite' => false,
            'start_onboarding' => false,
        ])
        ->assertSessionHas('success');

    $profile = $this->profile->fresh();

    expect($profile->is_active)->toBeTrue();
    expect($profile->end_date)->toBeNull();
    expect($profile->termination_reason)->toBeNull();
    expect($profile->start_date->toDateString())->toBe($newStart);
    expect($profile->position_title)->toBe('Senior Support Worker');
    expect($profile->employment_type)->toBe('part_time');

    // The outgoing stint is archived into employment_history.
    $history = $profile->employment_history;
    expect($history)->toBeArray()->toHaveCount(1);
    expect($history[0]['start_date'])->toBe($previousStart);
    expect($history[0]['end_date'])->toBe($previousEnd);
    expect($history[0]['position_title'])->toBe('Support Worker');
    expect($history[0]['position_role'])->toBe('support_worker');
    expect($history[0]['employment_type'])->toBe('full_time');

    // Login access restored (approval is what gates login).
    expect($this->leaver->fresh()->approved_at)->not->toBeNull();
});

test('rehire generates a fresh onboarding checklist even though an old completed one exists', function () {
    HrOnboardingTemplate::query()->create([
        'tenant_id' => 1,
        'role' => 'support_worker',
        'site_type' => 'all',
        'tasks' => [
            [
                'category' => 'hr',
                'title' => 'Re-issue employment agreement',
                'description' => 'New engagement paperwork.',
                'is_required' => true,
                'sign_off_required' => false,
                'assigned_to_role' => 'hr',
            ],
        ],
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);

    // The first stint's checklist — long completed.
    $old = HrOnboardingChecklist::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $this->profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'completed',
        'started_at' => now()->subYears(3),
        'completed_at' => now()->subYears(3)->addDays(30),
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/people/{$this->profile->id}/rehire", [
            'start_date' => now()->addDays(3)->toDateString(),
            'send_invite' => false,
            'start_onboarding' => true,
        ])
        ->assertSessionHas('success');

    $checklists = HrOnboardingChecklist::query()
        ->where('employee_profile_id', $this->profile->id)
        ->orderBy('id')
        ->get();

    expect($checklists)->toHaveCount(2);
    expect($checklists->first()->id)->toBe($old->id);

    $fresh = $checklists->last();
    expect($fresh->status)->toBe('pending');
    expect($fresh->started_at)->not->toBeNull();
    expect($fresh->tasks()->count())->toBe(1);
});

test('rehire is rejected for an active profile', function () {
    $this->profile->update(['is_active' => true, 'end_date' => null]);

    $this->actingAs($this->hr)
        ->post("/hr/people/{$this->profile->id}/rehire", [
            'start_date' => now()->toDateString(),
        ])
        ->assertSessionHas('error');

    expect($this->profile->fresh()->employment_history)->toBeNull();
});

test('feedback picker excludes inactive-profile users but keeps profile-less admins', function () {
    $active = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $active->id,
        'employee_number' => 'EMP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'work_email' => $active->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'contract_type' => 'individual',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    // A profile-less user (e.g. an admin) must stay selectable.
    $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);

    $response = $this->actingAs($this->hr)->get('/hr/feedback');
    $response->assertOk();

    $ids = collect($response->inertiaProps('wizard.employees'))->pluck('id');

    expect($ids)->toContain($active->id);
    expect($ids)->toContain($admin->id);
    expect($ids)->not->toContain($this->leaver->id);
});

test('training enrolment picker excludes inactive-profile users', function () {
    $active = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $active->id,
        'employee_number' => 'EMP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'work_email' => $active->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'contract_type' => 'individual',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/training');
    $response->assertOk();

    $ids = collect($response->inertiaProps('lookups.staff'))->pluck('id');

    expect($ids)->toContain($active->id);
    expect($ids)->not->toContain($this->leaver->id);
});
