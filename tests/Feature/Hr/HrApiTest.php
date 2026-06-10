<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->viewer = User::factory()->create([
        'role' => 'hr_api',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

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
        'organization_id' => 1,
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

test('hr api endpoints are permissioned and tenant scoped', function () {
    $tenantOneUser = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $tenantTwoUser = User::factory()->create(['organization_id' => 2, 'approved_at' => now()]);

    $tenantOneProfile = profileFor($tenantOneUser, 1, 'API-1');
    $tenantTwoProfile = profileFor($tenantTwoUser, 2, 'API-2');

    HrLeaveRequest::factory()->create([
        'tenant_id' => 1,
        'user_id' => $tenantOneUser->id,
        'leave_type' => 'annual',
        'status' => 'approved',
        'created_by' => $this->viewer->id,
    ]);
    HrLeaveRequest::factory()->create([
        'tenant_id' => 2,
        'user_id' => $tenantTwoUser->id,
        'leave_type' => 'annual',
        'status' => 'approved',
        'created_by' => $this->viewer->id,
    ]);

    HrLeaveBalance::factory()->create([
        'tenant_id' => 1,
        'user_id' => $tenantOneUser->id,
        'leave_type' => 'annual',
        'balance_hours' => 40,
        'year' => 2026,
    ]);
    HrLeaveBalance::factory()->create([
        'tenant_id' => 2,
        'user_id' => $tenantTwoUser->id,
        'leave_type' => 'annual',
        'balance_hours' => 80,
        'year' => 2026,
    ]);

    HrPosition::query()->create([
        'tenant_id' => 1,
        'title' => 'API Position 1',
        'code' => 'API-POS-1',
        'employment_type' => 'full_time',
        'created_by' => $this->viewer->id,
    ]);
    HrPosition::query()->create([
        'tenant_id' => 2,
        'title' => 'API Position 2',
        'code' => 'API-POS-2',
        'employment_type' => 'full_time',
        'created_by' => $this->viewer->id,
    ]);

    $requirement = HrComplianceRequirement::query()->create([
        'tenant_id' => 1,
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
        'tenant_id' => 2,
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
        'tenant_id' => 1,
        'user_id' => $tenantOneUser->id,
        'requirement_id' => $requirement->id,
        'status' => 'compliant',
    ]);
    HrStaffComplianceStatus::query()->create([
        'tenant_id' => 2,
        'user_id' => $tenantTwoUser->id,
        'requirement_id' => $otherRequirement->id,
        'status' => 'compliant',
    ]);

    HrTimeEntry::factory()->create([
        'tenant_id' => 1,
        'user_id' => $tenantOneUser->id,
        'entry_date' => '2026-06-10',
        'clock_in' => '2026-06-09 21:00:00',
        'clock_out' => '2026-06-10 05:00:00',
        'status' => 'submitted',
        'created_by' => $this->viewer->id,
    ]);
    HrTimeEntry::factory()->create([
        'tenant_id' => 2,
        'user_id' => $tenantTwoUser->id,
        'entry_date' => '2026-06-10',
        'clock_in' => '2026-06-09 21:00:00',
        'clock_out' => '2026-06-10 05:00:00',
        'status' => 'submitted',
        'created_by' => $this->viewer->id,
    ]);

    HrPayrollRun::factory()->create([
        'tenant_id' => 1,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-15',
        'status' => 'draft',
        'created_by' => $this->viewer->id,
    ]);
    HrPayrollRun::factory()->create([
        'tenant_id' => 2,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-15',
        'status' => 'draft',
        'created_by' => $this->viewer->id,
    ]);

    $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/employees')
        ->assertOk()
        ->assertJsonPath('data.0.employee_number', 'API-1');

    $this->actingAs($this->viewer, 'sanctum')->getJson("/api/hr/employees/{$tenantOneProfile->id}")
        ->assertOk()
        ->assertJsonPath('employee_number', 'API-1');

    $this->actingAs($this->viewer, 'sanctum')->getJson("/api/hr/employees/{$tenantTwoProfile->id}")
        ->assertNotFound();

    $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/leave/requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $tenantOneUser->id);

    $this->actingAs($this->viewer, 'sanctum')->getJson("/api/hr/leave/balances/{$tenantOneUser->id}")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.user_id', $tenantOneUser->id);

    $this->actingAs($this->viewer, 'sanctum')->getJson("/api/hr/leave/balances/{$tenantTwoUser->id}")
        ->assertForbidden();

    $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/positions')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'API-POS-1');

    $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/compliance/status')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $tenantOneUser->id);

    $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/time/entries?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $tenantOneUser->id);

    $this->actingAs($this->viewer, 'sanctum')->getJson('/api/hr/payroll/runs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tenant_id', 1);
});

function profileFor(User $user, int $tenantId, string $employeeNumber): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'tenant_id' => $tenantId,
        'user_id' => $user->id,
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
