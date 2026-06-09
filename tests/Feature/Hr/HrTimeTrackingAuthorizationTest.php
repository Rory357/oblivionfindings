<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimesheet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function hrRoleUser(string $roleName): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

function grantHrTimePermission(User $user, string $permissionKey): void
{
    $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();

    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
}

function hrTimeProfile(User $user, ?User $manager = null): void
{
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-HRT-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => $user->role,
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'manager_user_id' => $manager?->id,
        'primary_site_id' => null,
        'secondary_site_ids' => [],
    ]);
}

function hrRouteTimesheet(User $staff, array $overrides = []): HrTimesheet
{
    return HrTimesheet::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'period_start' => '2026-04-20',
        'period_end' => '2026-04-26',
        'status' => 'submitted',
        'total_hours' => 8,
        'submitted_at' => now()->subHour(),
        'submitted_by' => $staff->id,
        'created_by' => $staff->id,
    ], $overrides));
}

test('users without hr time permission cannot clock in via hr time routes', function () {
    $user = hrRoleUser('support_worker');

    $this->actingAs($user)
        ->post('/hr/time/clock-in')
        ->assertForbidden();
});

test('hr users can access the hr time dashboard', function () {
    $user = hrRoleUser('hr');

    $this->actingAs($user)
        ->get('/hr/time')
        ->assertOk();
});

test('team approver can approve a direct report hr timesheet', function () {
    $manager = hrRoleUser('support_worker');
    $staff = hrRoleUser('support_worker');
    grantHrTimePermission($manager, 'hr.time.approveTeam');
    grantHrTimePermission($manager, 'hr.time.viewAny');
    hrTimeProfile($manager);
    hrTimeProfile($staff, $manager);
    $timesheet = hrRouteTimesheet($staff);

    $this->actingAs($manager)
        ->post(route('hr.time.timesheets.approve', $timesheet))
        ->assertSessionHas('success', 'Timesheet approved.');

    $this->assertDatabaseHas('hr_timesheets', [
        'id' => $timesheet->id,
        'status' => 'approved',
        'approved_by' => $manager->id,
    ]);
});

test('team approver cannot approve outside their team', function () {
    $manager = hrRoleUser('support_worker');
    $otherManager = hrRoleUser('support_worker');
    $staff = hrRoleUser('support_worker');
    grantHrTimePermission($manager, 'hr.time.approveTeam');
    grantHrTimePermission($manager, 'hr.time.viewAny');
    hrTimeProfile($manager);
    hrTimeProfile($otherManager);
    hrTimeProfile($staff, $otherManager);
    $timesheet = hrRouteTimesheet($staff);

    $this->actingAs($manager)
        ->post(route('hr.time.timesheets.approve', $timesheet))
        ->assertForbidden();

    $this->assertDatabaseHas('hr_timesheets', [
        'id' => $timesheet->id,
        'status' => 'submitted',
        'approved_by' => null,
    ]);
});

test('bulk approval cannot include hr timesheets outside reviewer scope', function () {
    $manager = hrRoleUser('support_worker');
    $otherManager = hrRoleUser('support_worker');
    $directReport = hrRoleUser('support_worker');
    $outsideStaff = hrRoleUser('support_worker');
    grantHrTimePermission($manager, 'hr.time.approveTeam');
    grantHrTimePermission($manager, 'hr.time.viewAny');
    hrTimeProfile($manager);
    hrTimeProfile($otherManager);
    hrTimeProfile($directReport, $manager);
    hrTimeProfile($outsideStaff, $otherManager);
    $allowed = hrRouteTimesheet($directReport);
    $blocked = hrRouteTimesheet($outsideStaff);

    $this->actingAs($manager)
        ->post(route('hr.time.timesheets.bulk-approve'), [
            'ids' => [$allowed->id, $blocked->id],
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('hr_timesheets', ['id' => $allowed->id, 'status' => 'submitted']);
    $this->assertDatabaseHas('hr_timesheets', ['id' => $blocked->id, 'status' => 'submitted']);
});

test('staff cannot submit another users hr timesheet', function () {
    $staff = hrRoleUser('support_worker');
    $otherStaff = hrRoleUser('support_worker');
    grantHrTimePermission($staff, 'hr.time.viewAny');
    hrTimeProfile($staff);
    hrTimeProfile($otherStaff);
    $timesheet = hrRouteTimesheet($otherStaff, ['status' => 'draft', 'submitted_at' => null, 'submitted_by' => null]);

    $this->actingAs($staff)
        ->post(route('hr.time.timesheets.submit', $timesheet))
        ->assertForbidden();
});

test('hr clock out rejects break_minutes above the shared 240 cap', function () {
    // D4 — break cap unified to 240 across the HR module too, matching the
    // frontline /attendance + /timesheets surfaces (this path was 480 before).
    $user = hrRoleUser('support_worker');
    grantHrTimePermission($user, 'timesheets.viewAny');

    $this->actingAs($user)
        ->post('/hr/time/clock-out', [
            'break_minutes' => 300,
        ])
        ->assertSessionHasErrors(['break_minutes']);
});
