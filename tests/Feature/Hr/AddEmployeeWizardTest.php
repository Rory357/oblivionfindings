<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    $this->site = Site::factory()->create(['name' => 'Add Employee Allowed Site']);
    HrEmployeeProfile::query()->create([
        'user_id' => $this->hr->id,
        'employee_number' => 'EMP-ADD-VIEWER',
        'work_email' => $this->hr->email,
        'position_title' => 'HR Manager',
        'position_role' => 'hr',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);
});

test('an HR manager can add an employee via people.store', function () {
    $response = $this->actingAs($this->hr)->post('/hr/people', [
        'name' => 'Ana Williams',
        'email' => 'ana.williams@example.test',
        'role' => 'support_worker',
        'employment_type' => 'full_time',
        'position_title' => 'Support Worker',
        'primary_site_id' => $this->site->id,
    ]);

    $response->assertRedirect();

    $newUser = User::query()->where('email', 'ana.williams@example.test')->first();
    expect($newUser)->not->toBeNull();
    expect($newUser->roles->pluck('name'))->toContain('support_worker');

    $profile = HrEmployeeProfile::query()->where('user_id', $newUser->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->employment_type)->toBe('full_time');
    expect($profile->position_title)->toBe('Support Worker');
    expect((bool) $profile->is_active)->toBeTrue();
    expect($profile->employee_number)->not->toBeNull();
});

test('a non-manager cannot add an employee', function () {
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);

    $this->actingAs($worker)->post('/hr/people', [
        'name' => 'Blocked Person',
        'email' => 'blocked@example.test',
    ])->assertForbidden();

    expect(User::query()->where('email', 'blocked@example.test')->exists())->toBeFalse();
});

test('adding an unproven email that exists without a profile is rejected', function () {
    $existing = User::factory()->create([
        'email' => 'candidate@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->actingAs($this->hr)->post('/hr/people', [
        'name' => 'Candidate Hire',
        'email' => 'candidate@example.test',
        'role' => 'support_worker',
        'primary_site_id' => $this->site->id,
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'candidate@example.test')->count())->toBe(1);
    expect(
        HrEmployeeProfile::query()->where('user_id', $existing->id)->exists()
    )->toBeFalse();
});

test('adding an email already used by a staff member needs link confirmation', function () {
    $existing = User::factory()->create([
        'email' => 'staffer@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $existing->id,
        'employee_number' => 'EMP-EXIST',
        'work_email' => $existing->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);

    // Without link_existing → rejected with a validation error.
    $this->actingAs($this->hr)->post('/hr/people', [
        'name' => 'Staffer Again',
        'email' => 'staffer@example.test',
        'role' => 'support_worker',
        'primary_site_id' => $this->site->id,
    ])->assertSessionHasErrors('email');

    // With link_existing → updates the existing profile in place (no duplicate).
    $this->actingAs($this->hr)->post('/hr/people', [
        'name' => 'Staffer Again',
        'email' => 'staffer@example.test',
        'role' => 'support_worker',
        'position_title' => 'Senior Support Worker',
        'primary_site_id' => $this->site->id,
        'link_existing' => true,
    ])->assertRedirect();

    expect(HrEmployeeProfile::query()->where('user_id', $existing->id)->count())->toBe(1);
    expect($existing->fresh()->hrEmployeeProfile->position_title)->toBe('Senior Support Worker');
});
