<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollExportProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->allowedSite = Site::factory()->create(['name' => 'Allowed payroll Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden payroll Site']);
});

function payrollCanonicalStaff(string $name, ?Site $site, array $profile = []): User
{
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-PAY-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site?->id,
        'secondary_site_ids' => [],
        ...$profile,
    ]);

    return $user;
}

function grantPayrollCanonicalPermission(User $user, string $key): void
{
    $permission = Permission::query()->where('key', $key)->firstOrFail();
    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
}

/** @param list<User> $staff */
function payrollCanonicalRun(array $staff, string $status = 'locked'): HrPayrollRun
{
    $run = HrPayrollRun::factory()->create([
        'status' => $status,
        'period_start' => now()->subDays(14)->toDateString(),
        'period_end' => now()->subDay()->toDateString(),
    ]);
    foreach ($staff as $employee) {
        HrPayrollRunItem::query()->create([
            'payroll_run_id' => $run->id,
            'user_id' => $employee->id,
        ]);
    }

    return $run;
}

function payrollCanonicalTimesheet(User $staff, Site $site, User $approver, string $date): Timesheet
{
    $client = Client::factory()->create(['site_id' => $site->id]);

    return Timesheet::query()->create([
        'user_id' => $staff->id,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'work_date' => $date,
        'starts_at' => $date.' 09:00:00',
        'ends_at' => $date.' 17:00:00',
        'break_minutes' => 0,
        'status' => 'approved',
        'submitted_at' => $date.' 18:00:00',
        'approved_at' => $date.' 19:00:00',
        'approved_by' => $approver->id,
        'created_by' => $staff->id,
    ]);
}

test('view-only payroll lists only indivisible runs wholly proven at accessible Sites', function () {
    $viewer = payrollCanonicalStaff('Payroll Site viewer', $this->allowedSite);
    grantPayrollCanonicalPermission($viewer, 'hr.payroll.view');

    $allowedFormer = payrollCanonicalStaff('Allowed former employee', $this->allowedSite, [
        'is_active' => false,
        'end_date' => now()->subMonth()->toDateString(),
    ]);
    $hiddenStaff = payrollCanonicalStaff('Hidden payroll employee', $this->hiddenSite);
    $missingProvenance = payrollCanonicalStaff('Missing payroll provenance', null);

    $allowed = payrollCanonicalRun([$allowedFormer]);
    $hidden = payrollCanonicalRun([$hiddenStaff]);
    $mixed = payrollCanonicalRun([$allowedFormer, $hiddenStaff]);
    $unproven = payrollCanonicalRun([$missingProvenance]);

    $response = $this->actingAs($viewer)->get(route('hr.payroll.index'))->assertOk();
    $ids = collect($response->inertiaProps('runs.data'))->pluck('id');

    expect($ids)->toContain($allowed->id)
        ->not->toContain($hidden->id, $mixed->id, $unproven->id)
        ->and($response->inertiaProps('statusCounts.total'))->toBe(1);
});

test('payroll export action alone remains scoped to the callers canonical Sites', function () {
    $manager = payrollCanonicalStaff('Application payroll manager', $this->allowedSite);
    grantPayrollCanonicalPermission($manager, 'hr.payroll.view');
    grantPayrollCanonicalPermission($manager, 'hr.payroll.export');

    $allowedStaff = payrollCanonicalStaff('Allowed payroll employee', $this->allowedSite);
    $hiddenStaff = payrollCanonicalStaff('Other Site payroll employee', $this->hiddenSite);
    $allowed = payrollCanonicalRun([$allowedStaff]);
    $hidden = payrollCanonicalRun([$hiddenStaff]);
    $mixed = payrollCanonicalRun([$allowedStaff, $hiddenStaff]);

    $response = $this->actingAs($manager)->get(route('hr.payroll.index'))->assertOk();
    $ids = collect($response->inertiaProps('runs.data'))->pluck('id');

    expect($ids)->toContain($allowed->id)
        ->not->toContain($hidden->id, $mixed->id)
        ->and($response->inertiaProps('statusCounts.total'))->toBe(1);
});

test('creating an application payroll run collects approved work across Sites', function () {
    $manager = payrollCanonicalStaff('Application payroll operator', $this->allowedSite);
    grantPayrollCanonicalPermission($manager, 'hr.payroll.view');
    grantPayrollCanonicalPermission($manager, 'hr.payroll.export');
    grantPayrollCanonicalPermission($manager, 'hr.employees.viewAllSites');

    $allowedStaff = payrollCanonicalStaff('First Site employee', $this->allowedSite, [
        'hourly_rate' => 30,
    ]);
    $hiddenStaff = payrollCanonicalStaff('Second Site employee', $this->hiddenSite, [
        'hourly_rate' => 31,
    ]);
    $workDate = now()->subDays(3)->toDateString();
    payrollCanonicalTimesheet($allowedStaff, $this->allowedSite, $manager, $workDate);
    payrollCanonicalTimesheet($hiddenStaff, $this->hiddenSite, $manager, $workDate);

    $this->actingAs($manager)->post(route('hr.payroll.runs.store'), [
        'period_start' => now()->subWeek()->toDateString(),
        'period_end' => now()->toDateString(),
    ])->assertSessionHas('success');

    $run = HrPayrollRun::query()->where('created_by', $manager->id)->firstOrFail();
    expect($run->items()->pluck('user_id')->all())
        ->toContain($allowedStaff->id, $hiddenStaff->id)
        ->and($run->total_staff)->toBe(2);
});

test('profile names and default selection are application identities', function () {
    $manager = payrollCanonicalStaff('Payroll profile manager', $this->allowedSite);
    grantPayrollCanonicalPermission($manager, 'hr.payroll.view');
    grantPayrollCanonicalPermission($manager, 'hr.payroll.export');
    grantPayrollCanonicalPermission($manager, 'hr.employees.viewAllSites');

    $payload = [
        'name' => 'Canonical payroll profile',
        'is_default' => true,
        'mappings' => [
            ['header' => 'Employee', 'source' => 'employee_number'],
        ],
    ];

    $this->actingAs($manager)
        ->post(route('hr.payroll.profiles.store'), $payload)
        ->assertSessionHas('success');
    $this->actingAs($manager)
        ->post(route('hr.payroll.profiles.store'), $payload)
        ->assertSessionHasErrors('name');

    expect(HrPayrollExportProfile::query()->where('is_default', true)->count())
        ->toBe(1);
});
