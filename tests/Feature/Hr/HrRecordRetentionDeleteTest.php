<?php

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Domain\Hr\Models\HrPayslip;
use App\Models\User;
use Illuminate\Database\QueryException;

test('users with statutory hr records must be deactivated instead of hard deleted', function () {
    $actor = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'RET-001',
        'work_email' => "retention-{$user->id}@example.test",
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);

    $leaveRequest = HrLeaveRequest::factory()->create([
        'user_id' => $user->id,
        'leave_type' => 'annual',
        'status' => 'approved',
        'created_by' => $actor->id,
    ]);

    $payrollRun = HrPayrollRun::factory()->create([
        'period_start' => now()->subWeek()->toDateString(),
        'period_end' => now()->toDateString(),
        'status' => 'draft',
        'created_by' => $actor->id,
    ]);

    $payrollRunItem = HrPayrollRunItem::query()->create([
        'payroll_run_id' => $payrollRun->id,
        'user_id' => $user->id,
        'timesheet_ids' => [],
        'regular_hours' => 8,
        'gross_pay' => 240,
    ]);

    $payslip = HrPayslip::query()->create([
        'payroll_run_id' => $payrollRun->id,
        'employee_profile_id' => $profile->id,
        'user_id' => $user->id,
        'pay_period_start' => now()->subWeek()->toDateString(),
        'pay_period_end' => now()->toDateString(),
        'gross_pay' => 240,
        'net_pay' => 190,
        'status' => 'approved',
        'created_by' => $actor->id,
    ]);

    $case = HrCase::query()->create([
        'case_number' => 'RET-CASE-001',
        'user_id' => $user->id,
        'case_type' => 'disciplinary',
        'severity' => 'medium',
        'status' => 'open',
        'title' => 'Retention test',
        'description' => 'This record must survive user deactivation.',
        'opened_at' => now(),
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);

    expect(fn () => $user->delete())->toThrow(QueryException::class);

    expect(HrLeaveRequest::query()->whereKey($leaveRequest->id)->exists())->toBeTrue()
        ->and(HrPayrollRunItem::query()->whereKey($payrollRunItem->id)->exists())->toBeTrue()
        ->and(HrPayslip::query()->whereKey($payslip->id)->exists())->toBeTrue()
        ->and(HrCase::query()->whereKey($case->id)->exists())->toBeTrue();
});
