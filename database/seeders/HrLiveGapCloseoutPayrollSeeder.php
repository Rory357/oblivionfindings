<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Domain\Hr\Models\HrPayslip;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class HrLiveGapCloseoutPayrollSeeder extends Seeder
{
    /**
     * The browser fixture owns the marked employee setup and cleanup.
     * This is synthetic legacy history, without verified source-use evidence.
     *
     * @return array{run: HrPayrollRun, item: HrPayrollRunItem, payslip: HrPayslip}
     */
    public function seedPayroll(string $marker, User $admin, HrEmployeeProfile $profile): array
    {
        $employee = $profile->user;
        if (trim($marker) === ''
            || $profile->employee_number !== $marker.'-PAY'
            || ! $employee
            || $employee->email !== strtolower($marker).'.payroll@example.test') {
            throw new InvalidArgumentException('The marked synthetic payroll employee is required.');
        }

        return DB::transaction(function () use ($marker, $admin, $profile, $employee): array {
            $run = HrPayrollRun::query()->firstOrCreate([
                'notes' => $marker.' payroll run',
                'created_by' => $admin->id,
            ], [
                'source_provenance_status' => 'legacy_no_paid_leave',
                'period_start' => now()->subWeeks(2)->startOfWeek()->toDateString(),
                'period_end' => now()->subWeek()->endOfWeek()->toDateString(),
                'status' => 'locked',
                'locked_at' => now()->subDay(),
                'locked_by' => $admin->id,
                'total_hours' => 80,
                'total_gross' => 2400,
                'total_staff' => 1,
            ]);
            $item = HrPayrollRunItem::query()->firstOrCreate([
                'payroll_run_id' => $run->id,
                'user_id' => $employee->id,
            ], [
                'timesheet_ids' => [],
                'base_hourly_rate' => 30,
                'regular_hours' => 80,
                'overtime_hours' => 0,
                'leave_hours' => 0,
                'leave_pay' => 0,
                'gross_pay' => 2400,
                'allowances' => [],
                'notes' => $marker.' synthetic payroll item',
            ]);
            $payslip = HrPayslip::query()->firstOrCreate([
                'payroll_run_id' => $run->id,
                'employee_profile_id' => $profile->id,
                'user_id' => $employee->id,
            ], [
                'pay_period_start' => $run->period_start,
                'pay_period_end' => $run->period_end,
                'payment_date' => now()->subDays(2)->toDateString(),
                'gross_pay' => 2400,
                'regular_hours' => 80,
                'overtime_hours' => 0,
                'hourly_rate' => 30,
                'paye' => 480,
                'acc_levy' => 34,
                'kiwisaver_employee' => 72,
                'kiwisaver_employer' => 72,
                'esct' => 12,
                'student_loan' => 0,
                'holiday_pay' => 0,
                'total_deductions' => 586,
                'net_pay' => 1814,
                'allowances' => [],
                'other_deductions' => [],
                'tax_code' => 'M',
                'kiwisaver_rate' => 3,
                'status' => 'final',
                'created_by' => $admin->id,
            ]);

            return compact('run', 'item', 'payslip');
        });
    }
}
