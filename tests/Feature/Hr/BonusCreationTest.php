<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP-'.$this->worker->id,
        'work_email' => $this->worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
});

test('recording a bonus resolves the tenant and persists a pending payment', function () {
    $response = $this->actingAs($this->hr)->post('/hr/compensation/bonuses', [
        'employee_profile_id' => $this->profile->id,
        'bonus_type' => 'spot',
        'amount' => 500,
        'payment_date' => '2026-07-01',
        'reason' => 'Outstanding shift cover',
    ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_bonus_payments', [
        'tenant_id' => 1,
        'employee_profile_id' => $this->profile->id,
        'bonus_type' => 'spot',
        'amount' => 500,
        'status' => 'pending',
        'created_by' => $this->hr->id,
    ]);
});

test('the bonuses index ships the employees prop the create modal needs', function () {
    $response = $this->actingAs($this->hr)->get('/hr/compensation/bonuses');
    $response->assertOk();

    $employeeIds = collect($response->inertiaProps('employees'))->pluck('id')->all();
    expect($employeeIds)->toContain($this->profile->id);
});

test('a user without hr.compensation.manage cannot record a bonus', function () {
    $this->actingAs($this->worker)->post('/hr/compensation/bonuses', [
        'employee_profile_id' => $this->profile->id,
        'bonus_type' => 'spot',
        'amount' => 500,
        'payment_date' => '2026-07-01',
    ])->assertForbidden();

    $this->assertDatabaseMissing('hr_bonus_payments', [
        'employee_profile_id' => $this->profile->id,
    ]);
});
