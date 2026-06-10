<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
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

function hrSharedOperationsTimesheet(User $staff, array $overrides = []): Timesheet
{
    $client = Client::factory()->create([
        'first_name' => 'Aroha',
        'last_name' => 'Ngata',
        'status' => 'active',
    ]);
    $startsAt = CarbonImmutable::parse('2026-04-20 09:00:00', config('app.worker_timezone'))->utc();
    $endsAt = $startsAt->addHours(8);

    return Timesheet::query()->create(array_merge([
        'user_id' => $staff->id,
        'client_id' => $client->id,
        'work_date' => '2026-04-20',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'break_minutes' => 30,
        'status' => 'submitted',
        'submitted_at' => now()->subHour(),
        'submitted_by' => $staff->id,
        'created_by' => $staff->id,
        'client_name_snapshot' => 'Aroha Ngata',
        'staff_name_snapshot' => $staff->name,
        'shift_type_snapshot' => 'standard',
        'coverage_roles_snapshot' => [],
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

test('hr time timesheets tab lists the shared operations timesheet rows', function () {
    $hr = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($hr);
    hrTimeProfile($staff);

    $timesheet = hrSharedOperationsTimesheet($staff, [
        'status' => 'draft',
        'submitted_at' => null,
        'submitted_by' => null,
    ]);

    $this->actingAs($hr)
        ->get('/hr/time?tab=timesheets')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/time/index')
            ->where('timesheets.data.0.id', $timesheet->id)
            ->where('timesheets.data.0.source', 'operations')
            ->where('timesheets.data.0.module_url', '/operations/timesheets?view='.$timesheet->id)
            ->where('timesheets.data.0.user_name', $staff->name)
        );
});

test('hr time approval queue lists submitted operations timesheets', function () {
    $hr = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($hr);
    hrTimeProfile($staff);

    $timesheet = hrSharedOperationsTimesheet($staff);

    $this->actingAs($hr)
        ->get('/hr/time?tab=approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/time/index')
            ->where('pendingApprovalCount', 1)
            ->where('kpiStats.pending_timesheets', 1)
            ->where('approvalTimesheets.0.id', $timesheet->id)
            ->where('approvalTimesheets.0.source', 'operations')
            ->where('approvalTimesheets.0.module_url', '/operations/timesheets?tab=submitted&view='.$timesheet->id)
        );
});

test('legacy hr timesheet workflow routes are removed', function () {
    foreach ([
        'hr.time.timesheets.submit',
        'hr.time.timesheets.approve',
        'hr.time.timesheets.reject',
        'hr.time.timesheets.return',
        'hr.time.timesheets.bulk-approve',
        'hr.time.timesheets.bulk-reject',
        'hr.time.timesheets.bulk-return',
    ] as $routeName) {
        expect(Route::getRoutes()->getByName($routeName))->toBeNull();
    }
});

test('hr time frontend links to operations timesheets instead of posting to legacy hr endpoints', function () {
    $source = file_get_contents(resource_path('js/pages/hr/time/index.tsx'));

    expect($source)->not->toContain('/hr/time/timesheets/');
    expect($source)->not->toContain('/hr/time/timesheets/bulk-');
    expect($source)->toContain('/operations/timesheets');
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
