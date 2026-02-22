<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->hr->id,
        'employee_number' => 'EMP-HR-' . $this->hr->id,
        'work_email' => $this->hr->email,
        'position_title' => 'HR Manager',
        'position_role' => 'hr',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
});

test('compliance matrix index only returns requirements from resolved tenant', function () {
    HrComplianceRequirement::query()->create([
        'tenant_id' => 1,
        'code' => 'TENANT1_REQ',
        'name' => 'Tenant 1 Requirement',
        'category' => 'training',
        'check_type' => 'training_course',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);

    HrComplianceRequirement::query()->create([
        'tenant_id' => 2,
        'code' => 'TENANT2_REQ',
        'name' => 'Tenant 2 Requirement',
        'category' => 'training',
        'check_type' => 'training_course',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/compliance/matrix');
    $response->assertOk();

    $codes = collect($response->inertiaProps('requirements'))->pluck('code')->all();
    expect($codes)->toContain('TENANT1_REQ');
    expect($codes)->not->toContain('TENANT2_REQ');
});

test('creating compliance requirement uses resolved tenant id', function () {
    $response = $this->actingAs($this->hr)->post('/hr/compliance/requirements', [
        'name' => 'Manual Handling',
        'code' => 'MANUAL_HANDLING',
        'category' => 'training',
        'check_type' => 'training_course',
        'hard_stop' => true,
    ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_compliance_requirements', [
        'tenant_id' => 1,
        'code' => 'MANUAL_HANDLING',
        'name' => 'Manual Handling',
    ]);
});
