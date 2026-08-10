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
    $this->site = Site::factory()->create(['name' => 'Org Chart Test Site']);
    orgChartProfile($this->hr, [
        'employee_number' => 'EMP-ORG-VIEWER',
        'primary_site_id' => $this->site->id,
    ]);
});

function orgChartProfile(User $user, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create(array_merge([
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => Site::query()->orderBy('id')->value('id'),
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

test('a reporting loop is rejected server-side (drag onto own subordinate)', function () {
    // mgr ← emp (emp reports to mgr). The builder must not let mgr be dropped
    // onto emp; even if it tries, the server's wouldCreateCycle guard blocks it.
    $mgrUser = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $mgr = orgChartProfile($mgrUser, ['employee_number' => 'EMP-MGR']);

    $empUser = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $emp = orgChartProfile($empUser, ['manager_user_id' => $mgrUser->id]);

    expect($emp->fresh()->manager_user_id)->toBe($mgrUser->id);

    // Attempt to make the manager report to their own report → loop.
    $this->actingAs($this->hr)
        ->put("/hr/orgchart/{$mgr->id}", ['manager_user_id' => $empUser->id])
        ->assertSessionHas('error');

    expect($mgr->fresh()->manager_user_id)->toBeNull();
});

test('an employee can be dragged to the top level (manager cleared)', function () {
    $mgrUser = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    orgChartProfile($mgrUser, ['employee_number' => 'EMP-MGR']);

    $empUser = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $emp = orgChartProfile($empUser, ['manager_user_id' => $mgrUser->id]);

    $this->actingAs($this->hr)
        ->put("/hr/orgchart/{$emp->id}", ['manager_user_id' => null])
        ->assertRedirect();

    expect($emp->fresh()->manager_user_id)->toBeNull();
});
