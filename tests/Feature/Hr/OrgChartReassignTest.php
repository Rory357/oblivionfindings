<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

function orgChartProfile(User $user, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-' . $user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
    ], $overrides));
}

test('org chart route admits a viewAny user (no 403) and redirects into the hub', function () {
    // The hr role holds hr.employees.viewAny but NOT hr.orgchart.view — the
    // OR-permission fix must admit the user (302 to the hub), not 403.
    expect($this->hr->canDo('hr.orgchart.view'))->toBeFalse();
    expect($this->hr->canDo('hr.employees.viewAny'))->toBeTrue();

    $this->actingAs($this->hr)
        ->get('/hr/orgchart')
        ->assertRedirect(route('hr.people.index', ['tab' => 'orgchart']));
});

test('the people hub exposes the org chart hierarchy', function () {
    $u = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    orgChartProfile($u);

    $response = $this->actingAs($this->hr)->get('/hr/people?tab=orgchart');
    $response->assertOk();

    expect($response->inertiaProps('orgHierarchy'))->toBeArray();
});

test('a manager can reassign an employee reporting line', function () {
    $empUser = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $emp = orgChartProfile($empUser);

    $mgrUser = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    orgChartProfile($mgrUser, ['employee_number' => 'EMP-MGR']);

    $this->actingAs($this->hr)
        ->put("/hr/orgchart/{$emp->id}", ['manager_user_id' => $mgrUser->id])
        ->assertRedirect();

    expect($emp->fresh()->manager_user_id)->toBe($mgrUser->id);
});

test('an employee cannot be set to report to themselves', function () {
    $empUser = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $emp = orgChartProfile($empUser);

    $this->actingAs($this->hr)
        ->put("/hr/orgchart/{$emp->id}", ['manager_user_id' => $empUser->id]);

    expect($emp->fresh()->manager_user_id)->toBeNull();
});
