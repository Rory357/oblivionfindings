<?php

namespace Tests\Unit\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\LeaveReportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveReportTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected User $staffTenantA;

    protected User $staffTenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->staffTenantA = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staffTenantA->id,
            'employee_number' => 'T1-001',
            'work_email' => $this->staffTenantA->email,
            'position_title' => 'Caregiver',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subYear()->toDateString(),
            'is_active' => true,
        ]);

        $this->staffTenantB = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::query()->create([
            'tenant_id' => 2,
            'user_id' => $this->staffTenantB->id,
            'employee_number' => 'T2-001',
            'work_email' => $this->staffTenantB->email,
            'position_title' => 'Caregiver',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subYear()->toDateString(),
            'is_active' => true,
        ]);
    }

    public function test_absenteeism_report_is_scoped_to_tenant(): void
    {
        HrLeaveRequest::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staffTenantA->id,
            'leave_type' => 'sick',
            'status' => 'approved',
            'starts_at' => now()->startOfYear()->addDays(10),
            'ends_at' => now()->startOfYear()->addDays(11),
            'hours_requested' => 8,
        ]);

        HrLeaveRequest::query()->create([
            'tenant_id' => 2,
            'user_id' => $this->staffTenantB->id,
            'leave_type' => 'sick',
            'status' => 'approved',
            'starts_at' => now()->startOfYear()->addDays(20),
            'ends_at' => now()->startOfYear()->addDays(21),
            'hours_requested' => 16,
        ]);

        $service = app(LeaveReportService::class);

        $reportTenantA = $service->getAbsenteeismReport(1, now()->year);
        $reportTenantB = $service->getAbsenteeismReport(2, now()->year);

        $totalA = collect($reportTenantA['monthly'])->sum('count');
        $totalB = collect($reportTenantB['monthly'])->sum('count');

        $this->assertSame(1, $totalA);
        $this->assertSame(1, $totalB);

        // Tenant A should not see Tenant B's staff
        $namesA = collect($reportTenantA['top_absentees'])->pluck('user_id');
        $this->assertContains($this->staffTenantA->id, $namesA->all());
        $this->assertNotContains($this->staffTenantB->id, $namesA->all());
    }

    public function test_bradford_factor_is_scoped_to_tenant(): void
    {
        HrLeaveRequest::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staffTenantA->id,
            'leave_type' => 'sick',
            'status' => 'approved',
            'starts_at' => now()->startOfYear()->addDays(5),
            'ends_at' => now()->startOfYear()->addDays(6),
            'hours_requested' => 8,
        ]);

        HrLeaveRequest::query()->create([
            'tenant_id' => 2,
            'user_id' => $this->staffTenantB->id,
            'leave_type' => 'sick',
            'status' => 'approved',
            'starts_at' => now()->startOfYear()->addDays(15),
            'ends_at' => now()->startOfYear()->addDays(16),
            'hours_requested' => 8,
        ]);

        $service = app(LeaveReportService::class);

        $reportA = $service->getBradfordFactor(1, now()->year);
        $reportB = $service->getBradfordFactor(2, now()->year);

        $idsA = collect($reportA['employees'])->pluck('user_id');
        $idsB = collect($reportB['employees'])->pluck('user_id');

        $this->assertCount(1, $idsA);
        $this->assertContains($this->staffTenantA->id, $idsA->all());
        $this->assertNotContains($this->staffTenantB->id, $idsA->all());

        $this->assertCount(1, $idsB);
        $this->assertContains($this->staffTenantB->id, $idsB->all());
    }

    public function test_leave_utilization_is_scoped_to_tenant(): void
    {
        HrLeaveBalance::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staffTenantA->id,
            'leave_type' => 'annual',
            'balance_hours' => 160,
            'used_hours' => 40,
            'pending_hours' => 0,
            'year' => now()->year,
        ]);

        HrLeaveBalance::query()->create([
            'tenant_id' => 2,
            'user_id' => $this->staffTenantB->id,
            'leave_type' => 'annual',
            'balance_hours' => 200,
            'used_hours' => 80,
            'pending_hours' => 0,
            'year' => now()->year,
        ]);

        $service = app(LeaveReportService::class);

        $reportA = $service->getLeaveUtilizationReport(1, now()->year);
        $reportB = $service->getLeaveUtilizationReport(2, now()->year);

        $idsA = collect($reportA['employees'])->pluck('user_id');
        $idsB = collect($reportB['employees'])->pluck('user_id');

        $this->assertCount(1, $idsA);
        $this->assertContains($this->staffTenantA->id, $idsA->all());
        $this->assertNotContains($this->staffTenantB->id, $idsA->all());

        $this->assertCount(1, $idsB);
        $this->assertContains($this->staffTenantB->id, $idsB->all());
    }

    public function test_null_tenant_is_rejected_by_service(): void
    {
        $service = app(LeaveReportService::class);

        // All three methods require a non-null int tenantId (enforced by type signature).
        // Passing null triggers a PHP TypeError — no unscoped query is possible.
        $this->expectException(\TypeError::class);
        $service->getAbsenteeismReport(null, now()->year);
    }

    public function test_null_tenant_is_rejected_by_bradford_factor(): void
    {
        $service = app(LeaveReportService::class);

        $this->expectException(\TypeError::class);
        $service->getBradfordFactor(null, now()->year);
    }

    public function test_null_tenant_is_rejected_by_utilization(): void
    {
        $service = app(LeaveReportService::class);

        $this->expectException(\TypeError::class);
        $service->getLeaveUtilizationReport(null, now()->year);
    }
}
