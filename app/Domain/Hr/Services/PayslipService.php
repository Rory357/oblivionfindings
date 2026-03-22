<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

class PayslipService
{
    public function __construct(
        protected NzPayrollCalculatorService $calculator,
    ) {}

    /**
     * Generate a payslip for a single employee for a given pay period.
     *
     * Reads the employee's pay configuration from their profile and uses
     * the NZ payroll calculator to compute all statutory deductions.
     *
     * @param  HrEmployeeProfile  $profile      The employee profile
     * @param  string             $periodStart   Pay period start date (Y-m-d)
     * @param  string             $periodEnd     Pay period end date (Y-m-d)
     * @param  array              $timeData      Time data: regular_hours, overtime_hours, allowances, deductions
     * @return HrPayslip
     */
    public function generatePayslip(
        HrEmployeeProfile $profile,
        string $periodStart,
        string $periodEnd,
        array $timeData = [],
    ): HrPayslip {
        $annualSalary = (float) ($profile->annual_salary ?? 0);
        $hourlyRate = $profile->hourly_rate ? (float) $profile->hourly_rate : null;
        $regularHours = $timeData['regular_hours'] ?? 0;
        $overtimeHours = $timeData['overtime_hours'] ?? 0;
        $allowances = $timeData['allowances'] ?? [];
        $deductions = $timeData['deductions'] ?? [];
        $taxCode = $profile->tax_code ?? 'M';
        $kiwiSaverRate = (float) ($profile->kiwisaver_rate ?? 3);
        $payFrequency = $profile->pay_frequency ?? 'fortnightly';
        $employmentType = $profile->employment_type ?? 'permanent';

        // Determine if employee has student loan from tax code
        $hasStudentLoan = str_contains(strtoupper($taxCode), 'SL');

        $result = $this->calculator->calculatePayPeriod(
            annualSalary: $annualSalary,
            hourlyRate: $hourlyRate,
            hoursWorked: $regularHours,
            overtimeHours: $overtimeHours,
            payFrequency: $payFrequency,
            taxCode: $taxCode,
            kiwiSaverRate: $kiwiSaverRate,
            hasStudentLoan: $hasStudentLoan,
            employmentType: $employmentType,
            allowances: $allowances,
            deductions: $deductions,
        );

        return HrPayslip::create([
            'tenant_id' => $profile->tenant_id,
            'employee_profile_id' => $profile->id,
            'user_id' => $profile->user_id,
            'pay_period_start' => $periodStart,
            'pay_period_end' => $periodEnd,
            'gross_pay' => $result['gross_pay'],
            'regular_hours' => $regularHours,
            'overtime_hours' => $overtimeHours,
            'hourly_rate' => $hourlyRate ? (string) $hourlyRate : null,
            'paye' => $result['paye'],
            'acc_levy' => $result['acc_levy'],
            'kiwisaver_employee' => $result['kiwisaver_employee'],
            'kiwisaver_employer' => $result['kiwisaver_employer'],
            'student_loan' => $result['student_loan'],
            'holiday_pay' => $result['holiday_pay'],
            'total_deductions' => $result['total_deductions'],
            'net_pay' => $result['net_pay'],
            'allowances' => $result['allowances'],
            'other_deductions' => $result['deductions'],
            'tax_code' => $taxCode,
            'kiwisaver_rate' => $kiwiSaverRate,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Generate payslips for all employees in a payroll run.
     *
     * Iterates through all run items, looks up employee profiles,
     * and generates individual payslips within a database transaction.
     *
     * @param  HrPayrollRun  $run
     * @return Collection<int, HrPayslip>
     */
    public function generateBulkPayslips(HrPayrollRun $run): Collection
    {
        return DB::transaction(function () use ($run) {
            $items = $run->items()->with('user')->get();
            $payslips = collect();

            foreach ($items as $item) {
                $profile = HrEmployeeProfile::where('user_id', $item->user_id)
                    ->where('tenant_id', $run->tenant_id)
                    ->first();

                if (! $profile) {
                    continue;
                }

                $timeData = [
                    'regular_hours' => $item->regular_hours,
                    'overtime_hours' => $item->overtime_hours,
                    'allowances' => $item->allowances ?? [],
                    'deductions' => [],
                ];

                $payslip = $this->generatePayslip(
                    $profile,
                    $run->period_start->format('Y-m-d'),
                    $run->period_end->format('Y-m-d'),
                    $timeData,
                );

                $payslip->update(['payroll_run_id' => $run->id]);
                $payslips->push($payslip);
            }

            return $payslips;
        });
    }

    /**
     * Generate a PDF payslip document.
     *
     * Renders the payslip data into an HTML view and saves it as a file.
     * Uses a simple HTML approach for maximum compatibility.
     *
     * @param  HrPayslip  $payslip
     * @return string  Storage path of the generated file
     */
    public function generatePayslipPdf(HrPayslip $payslip): string
    {
        $payslip->load(['user', 'employeeProfile']);

        $html = View::make('hr.payslip-pdf', [
            'payslip' => $payslip,
            'employee' => $payslip->user,
            'profile' => $payslip->employeeProfile,
        ])->render();

        $filename = sprintf(
            'payslips/%s/%s_%s_%s.html',
            $payslip->tenant_id,
            $payslip->user_id,
            $payslip->pay_period_start->format('Y-m-d'),
            $payslip->pay_period_end->format('Y-m-d'),
        );

        Storage::disk('private')->put($filename, $html);

        $payslip->update(['pdf_path' => $filename]);

        return $filename;
    }
}
