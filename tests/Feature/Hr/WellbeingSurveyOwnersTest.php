<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
});

test('wellbeing survey page exposes active action-plan owner options', function () {
    $activeOwner = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $inactiveOwner = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $activeOwner->id,
        'employee_number' => 'EMP99101',
        'work_email' => "active-{$activeOwner->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $inactiveOwner->id,
        'employee_number' => 'EMP99102',
        'work_email' => "inactive-{$inactiveOwner->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $survey = HrEngagementSurvey::query()->create([
        'tenant_id' => 1,
        'title' => 'Survey owners check',
        'survey_type' => 'pulse',
        'status' => 'draft',
        'is_anonymous' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)->get("/hr/wellbeing/surveys/{$survey->id}");
    $response->assertOk();

    $owners = collect($response->inertiaProps('actionPlanOwners'));
    $ownerIds = $owners->pluck('id')->all();

    expect($ownerIds)->toContain($activeOwner->id);
    expect($ownerIds)->not->toContain($inactiveOwner->id);
});
