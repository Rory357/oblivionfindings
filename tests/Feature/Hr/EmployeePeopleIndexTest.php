<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'name' => 'HR Manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
    $this->site = Site::factory()->create(['name' => 'People Test Site']);
    createEmployeeProfile($this->hr, [
        'employee_number' => 'EMP-HR-VIEWER',
        'primary_site_id' => $this->site->id,
    ]);
});

function createEmployeeProfile(User $staff, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create(array_merge([
        'user_id' => $staff->id,
        'employee_number' => 'EMP-'.$staff->id.'-'.now()->timestamp,
        'work_email' => $staff->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => Site::query()->orderBy('id')->value('id'),
    ], $overrides));
}

test('people index lists Site-provenanced staff and fails closed for staff without employee profiles', function () {
    $staffWithProfile = User::factory()->create([
        'name' => 'Staff With Profile',
        'email' => 'with.profile@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    createEmployeeProfile($staffWithProfile);

    User::factory()->create([
        'name' => 'Staff Without Profile',
        'email' => 'without.profile@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    User::factory()->create([
        'name' => 'Client Portal User',
        'email' => 'client.user@example.test',
        'role' => 'client',
        'approved_at' => now(),
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/people');
    $response->assertOk();

    $names = collect($response->inertiaProps('profiles.data'))
        ->pluck('user.name')
        ->all();

    expect($names)->toContain('Staff With Profile');
    expect($names)->not->toContain('Staff Without Profile');
    expect($names)->not->toContain('Client Portal User');
});

test('people index status and site filters respect employee profile data', function () {
    $site = $this->site;

    $activeStaff = User::factory()->create([
        'name' => 'Active Staff',
        'email' => 'active.staff@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    createEmployeeProfile($activeStaff, [
        'employee_number' => 'EMP-ACTIVE',
        'primary_site_id' => $site->id,
        'is_active' => true,
    ]);

    $inactiveStaff = User::factory()->create([
        'name' => 'Inactive Staff',
        'email' => 'inactive.staff@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    createEmployeeProfile($inactiveStaff, [
        'employee_number' => 'EMP-INACTIVE',
        'primary_site_id' => $site->id,
        'is_active' => false,
    ]);

    User::factory()->create([
        'name' => 'No Profile Staff',
        'email' => 'no.profile.staff@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $inactiveResponse = $this->actingAs($this->hr)->get('/hr/people?status=inactive');
    $inactiveResponse->assertOk();

    $inactiveNames = collect($inactiveResponse->inertiaProps('profiles.data'))
        ->pluck('user.name')
        ->all();

    expect($inactiveNames)->toBe(['Inactive Staff']);

    $siteResponse = $this->actingAs($this->hr)->get('/hr/people?site_id='.$site->id);
    $siteResponse->assertOk();

    $siteNames = collect($siteResponse->inertiaProps('profiles.data'))
        ->pluck('user.name')
        ->all();

    expect($siteNames)->toBe(['Active Staff', 'HR Manager', 'Inactive Staff']);
});
