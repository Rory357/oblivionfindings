<?php

use App\Domain\Finance\Services\IrdFilingService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;

/**
 * Gap 4.3: IRD payday (Employment Information) filing built off a POSTED payroll
 * run. buildPaydayFilingPayload was dead code — createPaydayFiling now wires it
 * from the run's payslips and validateFiling accepts a well-formed payday filing.
 */
function paydayPayslip(HrPayrollRun $run, float $gross, float $paye, float $ksEmp, float $ksEr, float $studentLoan): void
{
    $profile = HrEmployeeProfile::factory()->create(['tenant_id' => 1]);

    HrPayslip::create([
        'tenant_id' => 1,
        'payroll_run_id' => $run->id,
        'employee_profile_id' => $profile->id,
        'user_id' => $profile->user_id,
        'pay_period_start' => $run->period_start->toDateString(),
        'pay_period_end' => $run->period_end->toDateString(),
        'payment_date' => '2026-06-16',
        'gross_pay' => $gross,
        'paye' => $paye,
        'kiwisaver_employee' => $ksEmp,
        'kiwisaver_employer' => $ksEr,
        'student_loan' => $studentLoan,
        'net_pay' => $gross - $paye - $ksEmp - $studentLoan,
        'status' => 'final',
    ]);
}

it('builds a payday filing from a posted payroll run with payslip totals', function () {
    $run = HrPayrollRun::create([
        'tenant_id' => 1,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-14',
        'status' => 'locked',
        'journal_id' => 999, // GL journal posted
    ]);
    paydayPayslip($run, 1000, 200, 30, 30, 50);
    paydayPayslip($run, 500, 100, 15, 15, 0);

    $service = app(IrdFilingService::class);
    $filing = $service->createPaydayFiling(1, $run, '49091850');

    expect($filing->filing_type)->toBe('payday')
        ->and($filing->payroll_run_id)->toBe($run->id)
        ->and((string) $filing->total_amount)->toBe('300.00');           // total PAYE payable to IRD

    expect($filing->filing_data['return_type'])->toBe('EI')
        ->and($filing->filing_data['total_gross'])->toBe('1500.00')
        ->and($filing->filing_data['total_paye'])->toBe('300.00')
        ->and($filing->filing_data['total_student_loan'])->toBe('50.00')
        ->and($filing->filing_data['payroll_run_id'])->toBe($run->id)
        ->and($filing->filing_data['employees'])->toHaveCount(2);

    // A well-formed payday filing (valid IRD check digit) validates clean.
    expect($service->validateFiling($filing))->toBe([]);
    expect($filing->fresh()->status)->toBe('validated');
});
