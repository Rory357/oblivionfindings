<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->allowedSite = Site::factory()->create(['name' => 'Allowed leave API Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden leave API Site']);

    $this->apiUser = function (array $permissionKeys): User {
        $user = User::factory()->create([
            'role' => 'hr_api',
            'approved_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'leave_balance_api_'.Role::query()->count(),
            'label' => 'Leave Balance API',
            'type' => 'custom',
            'level' => 60,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));
        $user->roles()->sync([$role->id]);
        ($this->staffProfile)($user);

        return $user;
    };

    $this->staffProfile = fn (User $user, ?Site $site = null): HrEmployeeProfile => HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => ($site ?? $this->allowedSite)->id,
        'is_active' => true,
    ]);

    $this->leaveBalance = fn (User $user): HrLeaveBalance => HrLeaveBalance::factory()->create([
        'user_id' => $user->id,
        'leave_type' => 'annual',
        'balance_hours' => 40,
        'year' => 2026,
    ]);
});

test('staff can read their own leave balance without approval authority', function () {
    $staff = ($this->apiUser)(['hr.leave.viewAny']);
    ($this->leaveBalance)($staff);

    $this->actingAs($staff, 'sanctum')
        ->getJson("/api/hr/leave/balances/{$staff->id}")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.user_id', $staff->id);
});

test('view-only staff cannot read another staff members leave balance', function () {
    $viewer = ($this->apiUser)(['hr.leave.viewAny']);
    $target = User::factory()->create(['approved_at' => now()]);
    ($this->staffProfile)($target);
    ($this->leaveBalance)($target);

    $this->actingAs($viewer, 'sanctum')
        ->getJson("/api/hr/leave/balances/{$target->id}")
        ->assertForbidden();
});

test('leave approvers can read a canonical staff members leave balance', function () {
    $approver = ($this->apiUser)(['hr.leave.viewAny', 'hr.leave.approve']);
    $target = User::factory()->create(['approved_at' => now()]);
    ($this->staffProfile)($target);
    ($this->leaveBalance)($target);

    $this->actingAs($approver, 'sanctum')
        ->getJson("/api/hr/leave/balances/{$target->id}")
        ->assertOk()
        ->assertJsonPath('0.user_id', $target->id);
});

test('leave approvers cannot read a staff member outside their approved Sites', function () {
    $approver = ($this->apiUser)(['hr.leave.viewAny', 'hr.leave.approve']);
    $target = User::factory()->create(['approved_at' => now()]);
    ($this->staffProfile)($target, $this->hiddenSite);
    ($this->leaveBalance)($target);

    $this->actingAs($approver, 'sanctum')
        ->getJson("/api/hr/leave/balances/{$target->id}")
        ->assertNotFound();
});

test('leave balance rows do not make a user a canonical staff member', function () {
    $approver = ($this->apiUser)(['hr.leave.viewAny', 'hr.leave.approve']);
    $nonStaff = User::factory()->create(['approved_at' => now()]);
    ($this->leaveBalance)($nonStaff);

    $this->actingAs($approver, 'sanctum')
        ->getJson("/api/hr/leave/balances/{$nonStaff->id}")
        ->assertNotFound();
});

test('unapproved users cannot use the self-service leave balance path', function () {
    $unapproved = ($this->apiUser)(['hr.leave.viewAny']);
    $unapproved->update(['approved_at' => null]);
    ($this->leaveBalance)($unapproved);

    $this->actingAs($unapproved, 'sanctum')
        ->getJson("/api/hr/leave/balances/{$unapproved->id}")
        ->assertNotFound();
});

test('portal users cannot use the self-service leave balance path', function () {
    $portal = ($this->apiUser)(['hr.leave.viewAny']);
    $portal->update(['role' => 'client']);
    ($this->leaveBalance)($portal);

    $this->actingAs($portal, 'sanctum')
        ->getJson("/api/hr/leave/balances/{$portal->id}")
        ->assertNotFound();
});
