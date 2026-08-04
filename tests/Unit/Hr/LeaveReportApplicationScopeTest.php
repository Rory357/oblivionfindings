<?php

namespace Tests\Unit\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\LeaveReportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveReportApplicationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $currentStaff;

    protected User $formerStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentStaff = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->currentStaff->id,
            'employee_number' => 'APP-001',
            'is_active' => true,
            'start_date' => now()->subYear(),
            'end_date' => null,
        ]);

        $this->formerStaff = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->formerStaff->id,
            'employee_number' => 'APP-002',
            'is_active' => false,
            'start_date' => now()->subYears(2),
            'end_date' => now()->subMonth(),
        ]);
    }

    public function test_absenteeism_report_retains_the_complete_application_history(): void
    {
        $this->sickLeave($this->currentStaff, 10, 8);
        $this->sickLeave($this->formerStaff, 20, 16);

        $report = app(LeaveReportService::class)->getAbsenteeismReport(now()->year);

        $this->assertSame(2, collect($report['monthly'])->sum('count'));
        $this->assertEqualsCanonicalizing(
            [$this->currentStaff->id, $this->formerStaff->id],
            collect($report['top_absentees'])->pluck('user_id')->all(),
        );
    }

    public function test_bradford_factor_reports_the_complete_application_history(): void
    {
        $this->sickLeave($this->currentStaff, 5, 8);
        $this->sickLeave($this->formerStaff, 15, 8);

        $report = app(LeaveReportService::class)->getBradfordFactor(now()->year);

        $this->assertEqualsCanonicalizing(
            [$this->currentStaff->id, $this->formerStaff->id],
            collect($report['employees'])->pluck('user_id')->all(),
        );
    }

    public function test_leave_utilisation_reports_all_retained_balances_once(): void
    {
        $this->leaveBalance($this->currentStaff, 160, 40);
        $this->leaveBalance($this->formerStaff, 200, 80);

        $report = app(LeaveReportService::class)->getLeaveUtilizationReport(now()->year);

        $this->assertEqualsCanonicalizing(
            [$this->currentStaff->id, $this->formerStaff->id],
            collect($report['employees'])->pluck('user_id')->all(),
        );
    }

    private function sickLeave(User $staff, int $dayOffset, int $hours): void
    {
        HrLeaveRequest::query()->create([
            'user_id' => $staff->id,
            'leave_type' => 'sick',
            'status' => 'approved',
            'starts_at' => now()->startOfYear()->addDays($dayOffset),
            'ends_at' => now()->startOfYear()->addDays($dayOffset + 1),
            'hours_requested' => $hours,
        ]);
    }

    private function leaveBalance(User $staff, int $entitlement, int $used): void
    {
        HrLeaveBalance::query()->create([
            'user_id' => $staff->id,
            'leave_type' => 'annual',
            'balance_hours' => $entitlement,
            'used_hours' => $used,
            'pending_hours' => 0,
            'year' => now()->year,
        ]);
    }
}
