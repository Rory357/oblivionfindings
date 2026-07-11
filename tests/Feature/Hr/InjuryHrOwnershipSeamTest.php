<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkplaceInjury;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

/**
 * Seam S4 — Injuries (H&S) → HR. `WorkplaceInjury` is owned by H&S: a source
 * audit finds it referenced in 13 sites app-wide, ALL under HealthSafety /
 * Governance / Tasks. HR federates a permission-gated read-only summary on the
 * employee profile and still has no create/update/delete path. These tests lock
 * the per-employee and one-owner-per-fact boundaries.
 */
function makeInjuryEmployeeProfile(User $employee, int $tenantId = 1): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $employee->id,
        'employee_number' => 'INJURY-'.$tenantId.'-'.$employee->id,
        'work_email' => $employee->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->viewer = User::factory()->create([
        'name' => 'HR Injury Viewer',
        'role' => 'hr',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $this->viewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('S4 seam: a workplace injury is H&S-owned per-employee data that HR would federate read-only', function () {
    $employee = User::factory()->create();
    $injury = WorkplaceInjury::factory()->create(['user_id' => $employee->id]);

    // Per-employee linkage — the join a read-only HR surface would federate on.
    expect($injury->user)->not->toBeNull();
    expect($injury->user->id)->toBe($employee->id);

    // The injury is retrievable by the injured employee's id (the read path a
    // read-only HR surface would use); H&S remains the sole writer.
    expect(WorkplaceInjury::query()->where('user_id', $employee->id)->count())->toBe(1);
});

test('employee profile surfaces H&S-owned injuries read-only with hazards view permission', function () {
    $permission = Permission::query()->where('key', 'hazards.view')->firstOrFail();
    $this->viewer->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);

    $employee = User::factory()->create(['organization_id' => 1]);
    $profile = makeInjuryEmployeeProfile($employee);
    $injury = WorkplaceInjury::factory()->create([
        'user_id' => $employee->id,
        'injury_type' => 'manual_handling',
        'body_part_affected' => 'Lower back',
        'severity' => 'moderate',
        'status' => 'return_to_work',
        'lost_time_days' => 3,
    ]);
    $injury->refresh();
    $original = $injury->getRawOriginal();

    $this->actingAs($this->viewer)
        ->get("/hr/people/{$profile->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/employees/show')
            ->where('can.viewInjuries', true)
            ->where('workplaceInjuries.0.id', $injury->id)
            ->where('workplaceInjuries.0.injury_type', 'manual_handling')
            ->where('workplaceInjuries.0.status', 'return_to_work'));

    expect($injury->fresh()->getRawOriginal())->toBe($original);
});

test('employee profile omits workplace injuries without hazards view permission', function () {
    $employee = User::factory()->create(['organization_id' => 1]);
    $profile = makeInjuryEmployeeProfile($employee);
    WorkplaceInjury::factory()->create(['user_id' => $employee->id]);

    $this->actingAs($this->viewer)
        ->get("/hr/people/{$profile->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.viewInjuries', false)
            ->missing('workplaceInjuries'));
});

test('employee profile blocks a foreign tenant before injury data is resolved', function () {
    $permission = Permission::query()->where('key', 'hazards.view')->firstOrFail();
    $this->viewer->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);

    $employee = User::factory()->create(['organization_id' => 2]);
    $profile = makeInjuryEmployeeProfile($employee, 2);
    WorkplaceInjury::factory()->create(['user_id' => $employee->id]);

    $this->actingAs($this->viewer)
        ->get("/hr/people/{$profile->id}")
        ->assertNotFound();
});
