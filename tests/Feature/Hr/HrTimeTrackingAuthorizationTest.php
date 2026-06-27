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
            ->where('kpiStats.awaiting_approval', 1)
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

test('clock on behalf requires and persists a reason', function () {
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager);
    hrTimeProfile($staff, $manager);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');

    // Missing reason → validation error.
    $this->actingAs($manager)
        ->post('/hr/time/clock-on-behalf', [
            'target_user_id' => $staff->id,
            'clock_in' => '2026-04-20 09:00',
        ])
        ->assertSessionHasErrors(['reason']);

    // With reason → persisted to the entry + an audit amendment row.
    $this->actingAs($manager)
        ->post('/hr/time/clock-on-behalf', [
            'target_user_id' => $staff->id,
            'clock_in' => '2026-04-20 09:00',
            'clock_out' => '2026-04-20 17:00',
            'break_minutes' => 30,
            'reason' => 'Staff forgot to clock in during an emergency handover.',
        ])
        ->assertSessionHasNoErrors();

    $entry = \App\Domain\Hr\Models\HrTimeEntry::query()
        ->where('user_id', $staff->id)
        ->where('entry_type', 'admin_clock')
        ->firstOrFail();

    expect($entry->amendment_reason)->toContain('emergency handover');
    expect($entry->amendments()->where('field_name', 'created_on_behalf')->exists())->toBeTrue();
});

test('voiding an entry soft-deletes it with a required reason', function () {
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');

    $entry = \App\Domain\Hr\Models\HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'status' => 'submitted',
    ]);

    // Reason required.
    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/void", [])
        ->assertSessionHasErrors(['reason']);

    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/void", [
            'reason' => 'Duplicate entry created in error.',
        ])
        ->assertSessionHasNoErrors();

    expect(\App\Domain\Hr\Models\HrTimeEntry::withTrashed()->find($entry->id)->trashed())->toBeTrue();
    expect($entry->amendments()->where('field_name', 'voided')->exists())->toBeTrue();
});

test('approved entries cannot be voided', function () {
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');

    $entry = \App\Domain\Hr\Models\HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'status' => 'approved',
    ]);

    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/void", [
            'reason' => 'Trying to void an approved entry.',
        ])
        ->assertSessionHas('error');

    expect(\App\Domain\Hr\Models\HrTimeEntry::find($entry->id))->not->toBeNull();
});

test('an approve-only manager cannot amend an entry outside their team', function () {
    // team_lead has timesheets.approve but NOT manageAny (RbacSeeder) — they may
    // only touch their own or their direct reports' entries.
    $lead = hrRoleUser('team_lead');
    $stranger = hrRoleUser('support_worker');
    hrTimeProfile($lead);
    hrTimeProfile($stranger); // NOT managed by $lead
    grantHrTimePermission($lead, 'timesheets.viewAny');
    grantHrTimePermission($lead, 'timesheets.approve');

    $entry = \App\Domain\Hr\Models\HrTimeEntry::factory()->create([
        'user_id' => $stranger->id,
        'status' => 'submitted',
    ]);

    $this->actingAs($lead)
        ->put("/hr/time/entries/{$entry->id}", [
            'clock_in' => '2026-04-20 09:00',
            'clock_out' => '2026-04-20 16:00',
            'break_minutes' => 30,
            'amendment_reason' => 'Trying to amend a stranger entry.',
        ])
        ->assertForbidden();

    $this->actingAs($lead)
        ->post("/hr/time/entries/{$entry->id}/correct", [
            'clock_out' => '2026-04-20 17:00',
            'reason' => 'Trying to correct a stranger entry.',
        ])
        ->assertForbidden();
});

test('an approve-only manager can amend their direct report\'s entry', function () {
    $lead = hrRoleUser('team_lead');
    $report = hrRoleUser('support_worker');
    hrTimeProfile($lead);
    hrTimeProfile($report, $lead); // managed by $lead
    grantHrTimePermission($lead, 'timesheets.viewAny');
    grantHrTimePermission($lead, 'timesheets.approve');

    $entry = \App\Domain\Hr\Models\HrTimeEntry::factory()->create([
        'user_id' => $report->id,
        'status' => 'submitted',
    ]);

    $this->actingAs($lead)
        ->put("/hr/time/entries/{$entry->id}", [
            'clock_in' => '2026-04-20 09:00',
            'clock_out' => '2026-04-20 16:00',
            'break_minutes' => 30,
            'amendment_reason' => 'Corrected the recorded finish time.',
        ])
        ->assertSessionHasNoErrors();
});

test('correcting a missed clock-out closes the entry and records the reason', function () {
    $manager = hrRoleUser('hr');
    $staff = hrRoleUser('support_worker');
    hrTimeProfile($manager);
    grantHrTimePermission($manager, 'timesheets.viewAny');
    grantHrTimePermission($manager, 'timesheets.manageAny');

    $clockIn = CarbonImmutable::parse('2026-04-20 09:00:00', config('app.worker_timezone'))->utc();
    $entry = \App\Domain\Hr\Models\HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'entry_date' => '2026-04-20',
        'clock_in' => $clockIn,
        'clock_out' => null,
        'total_hours' => null,
        'status' => 'active',
        'entry_type' => 'clock',
    ]);

    $this->actingAs($manager)
        ->post("/hr/time/entries/{$entry->id}/correct", [
            'clock_out' => '2026-04-20 17:00',
            'break_minutes' => 30,
            'reason' => 'Confirmed finish time with the on-call supervisor.',
        ])
        ->assertSessionHasNoErrors();

    $entry->refresh();
    expect($entry->clock_out)->not->toBeNull();
    expect($entry->status)->toBe('submitted');
    expect((float) $entry->total_hours)->toBeGreaterThan(0);
    expect($entry->amendments()->where('field_name', 'clock_out')->exists())->toBeTrue();
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
