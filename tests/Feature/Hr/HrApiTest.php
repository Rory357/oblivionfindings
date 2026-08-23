<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->allowedSite = Site::factory()->create(['name' => 'Allowed API Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden API Site']);

    $this->viewer = User::factory()->create([
        'role' => 'hr_api',
        'approved_at' => now(),
    ]);
    profileFor($this->viewer, $this->allowedSite, 'API-VIEWER');

    $role = Role::query()->create([
        'name' => 'hr_api_viewer',
        'label' => 'HR API Viewer',
        'type' => 'custom',
        'level' => 60,
    ]);

    $role->permissions()->sync(
        Permission::query()
            ->whereIn('key', [
                'hr.employees.viewAny',
                'hr.leave.viewAny',
                'hr.leave.approve',
                'hr.compliance.view',
                'timesheets.viewAny',
                'hr.payroll.view',
            ])
            ->pluck('id')
            ->all()
    );
    $this->viewer->roles()->sync([$role->id]);
});

test('hr api endpoints reject users without their required permissions', function () {
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    foreach ([
        '/api/hr/employees',
        '/api/hr/employees/1',
        '/api/hr/leave/requests',
        "/api/hr/leave/balances/{$user->id}",
        '/api/hr/positions',
        '/api/hr/compliance/status',
        '/api/hr/time/entries',
        '/api/hr/payroll/runs',
    ] as $endpoint) {
        $this->actingAs($user, 'sanctum')
            ->getJson($endpoint)
            ->assertForbidden();
    }
});

test('hr api endpoints use canonical Site access and explicit application global permissions', function () {
    $allowedUser = User::factory()->create([
        'approved_at' => now(),
    ]);
    $hiddenUser = User::factory()->create([
        'approved_at' => now(),
    ]);

    $allowedProfile = profileFor($allowedUser, $this->allowedSite, 'API-1');
    $hiddenProfile = profileFor($hiddenUser, $this->hiddenSite, 'API-2');

    HrLeaveRequest::factory()->create([
        'user_id' => $allowedUser->id,
        'leave_type' => 'annual',
        'status' => 'approved',
        'created_by' => $this->viewer->id,
    ]);
    HrLeaveRequest::factory()->create([
        'user_id' => $hiddenUser->id,
        'leave_type' => 'annual',
        'status' => 'approved',
        'created_by' => $this->viewer->id,
    ]);

    HrLeaveBalance::factory()->create([
        'user_id' => $allowedUser->id,
        'leave_type' => 'annual',
        'balance_hours' => 40,
        'year' => 2026,
    ]);
    HrLeaveBalance::factory()->create([
        'user_id' => $hiddenUser->id,
        'leave_type' => 'annual',
        'balance_hours' => 80,
        'year' => 2026,
    ]);

    HrPosition::query()->create([
        'title' => 'API Position 1',
        'code' => 'API-POS-1',
        'employment_type' => 'full_time',
        'created_by' => $this->viewer->id,
    ]);
    HrPosition::query()->create([
        'title' => 'API Position 2',
        'code' => 'API-POS-2',
        'employment_type' => 'full_time',
        'created_by' => $this->viewer->id,
    ]);

    $requirement = HrComplianceRequirement::query()->create([
        'code' => 'API-COMP',
        'name' => 'API Compliance',
        'category' => 'training',
        'check_type' => 'training_course',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->viewer->id,
        'updated_by' => $this->viewer->id,
    ]);
    $otherRequirement = HrComplianceRequirement::query()->create([
        'code' => 'API-COMP-2',
        'name' => 'API Compliance 2',
        'category' => 'training',
        'check_type' => 'training_course',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->viewer->id,
        'updated_by' => $this->viewer->id,
    ]);
    HrStaffComplianceStatus::query()->create([
        'user_id' => $allowedUser->id,
        'requirement_id' => $requirement->id,
        'status' => 'compliant',
    ]);
    HrStaffComplianceStatus::query()->create([
        'user_id' => $hiddenUser->id,
        'requirement_id' => $otherRequirement->id,
        'status' => 'compliant',
    ]);

    HrTimeEntry::factory()->create([
        'user_id' => $allowedUser->id,
        'entry_date' => '2026-06-10',
        'clock_in' => '2026-06-09 21:00:00',
        'clock_out' => '2026-06-10 05:00:00',
        'status' => 'submitted',
        'created_by' => $this->viewer->id,
    ]);
    HrTimeEntry::factory()->create([
        'user_id' => $hiddenUser->id,
        'entry_date' => '2026-06-10',
        'clock_in' => '2026-06-09 21:00:00',
        'clock_out' => '2026-06-10 05:00:00',
        'status' => 'submitted',
        'created_by' => $this->viewer->id,
    ]);

    $allowedPayrollRun = HrPayrollRun::factory()->create([
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-15',
        'status' => 'draft',
        'created_by' => $this->viewer->id,
    ]);
    $hiddenPayrollRun = HrPayrollRun::factory()->create([
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-15',
        'status' => 'draft',
        'created_by' => $this->viewer->id,
    ]);
    HrPayrollRunItem::query()->create([
        'payroll_run_id' => $allowedPayrollRun->id,
        'user_id' => $allowedUser->id,
    ]);
    HrPayrollRunItem::query()->create([
        'payroll_run_id' => $hiddenPayrollRun->id,
        'user_id' => $hiddenUser->id,
    ]);
    $mixedPayrollRun = HrPayrollRun::factory()->create([
        'period_start' => '2026-06-16',
        'period_end' => '2026-06-30',
        'status' => 'draft',
        'created_by' => $this->viewer->id,
    ]);
    foreach ([$allowedUser, $hiddenUser] as $payrollUser) {
        HrPayrollRunItem::query()->create([
            'payroll_run_id' => $mixedPayrollRun->id,
            'user_id' => $payrollUser->id,
        ]);
    }

    $employees = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/employees')
        ->assertOk()
        ->assertJsonCount(2, 'data');
    expect(collect($employees->json('data'))->pluck('employee_number')->all())
        ->toEqualCanonicalizing(['API-1', 'API-VIEWER']);

    $this->actingAs($this->viewer, 'sanctum')->getJson("/api/hr/employees/{$allowedProfile->id}")
        ->assertOk()
        ->assertJsonPath('employee_number', 'API-1');

    $this->actingAs($this->viewer, 'sanctum')->getJson("/api/hr/employees/{$hiddenProfile->id}")
        ->assertNotFound();

    $leaveRequests = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/leave/requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $allowedUser->id);

    $this->actingAs($this->viewer, 'sanctum')->getJson("/api/hr/leave/balances/{$allowedUser->id}")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.user_id', $allowedUser->id);

    $this->actingAs($this->viewer, 'sanctum')->getJson("/api/hr/leave/balances/{$hiddenUser->id}")
        ->assertNotFound();

    $positions = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/positions')
        ->assertOk()
        ->assertJsonCount(2, 'data');
    expect(collect($positions->json('data'))->pluck('code')->all())
        ->toEqualCanonicalizing(['API-POS-1', 'API-POS-2']);

    $compliance = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/compliance/status')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $allowedUser->id);

    $timeEntries = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/time/entries?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $allowedUser->id);

    $payroll = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/payroll/runs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $allowedPayrollRun->id);

    $exportPermission = Permission::query()->where('key', 'hr.payroll.export')->firstOrFail();
    $this->viewer->permissionOverrides()->syncWithoutDetaching([
        $exportPermission->id => ['allowed' => true],
    ]);
    $this->actingAs($this->viewer->fresh(), 'sanctum')
        ->getJson('/api/hr/payroll/runs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $allowedPayrollRun->id);

    $allSitesPermission = Permission::query()->where('key', 'hr.employees.viewAllSites')->firstOrFail();
    $this->viewer->permissionOverrides()->syncWithoutDetaching([
        $allSitesPermission->id => ['allowed' => true],
    ]);
    $applicationPayroll = $this->actingAs($this->viewer->fresh(), 'sanctum')
        ->getJson('/api/hr/payroll/runs')
        ->assertOk()
        ->assertJsonCount(3, 'data');
    expect(collect($applicationPayroll->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing([
            $allowedPayrollRun->id,
            $hiddenPayrollRun->id,
            $mixedPayrollRun->id,
        ]);
});

test('hr api redacts a sensitive leave reason and never exposes the document path for a non-HR viewer', function () {
    // $this->viewer holds hr.leave.viewAny + approve, but NOT hr.leave.manage.
    $staff = User::factory()->create(['approved_at' => now()]);
    profileFor($staff, $this->allowedSite, 'API-SENSITIVE');

    HrLeaveRequest::factory()->create([
        'user_id' => $staff->id,
        'leave_type' => 'sick',
        'status' => 'pending',
        'reason' => 'Specialist referral',
        'supporting_doc_path' => 'leave/'.$staff->id.'/note.pdf',
        'created_by' => $staff->id,
    ]);

    $res = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/leave/requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.leave_type', 'sick')
        ->assertJsonPath('data.0.reason', null)
        ->assertJsonPath('data.0.reason_restricted', true)
        ->assertJsonPath('data.0.has_doc', false);

    // The private-disk storage path must never be serialized to the client.
    expect($res->json('data.0'))->not->toHaveKey('supporting_doc_path');
});

function profileFor(User $user, Site $site, string $employeeNumber): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'employee_number' => $employeeNumber,
        'work_email' => strtolower($employeeNumber).'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}
