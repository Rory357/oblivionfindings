<?php

use App\Domain\Finance\Jobs\PostPayrollJournalJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\PayrollJournalService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\NzPayrollCalculatorService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * C5 payroll seam: ESCT (Employer Superannuation Contribution Tax) is now
 * calculated, stored on payslips, split out of the KiwiSaver remittance in the
 * payroll journal (CR 2150 ESCT Payable to IRD; the fund receives the employer
 * contribution NET of ESCT), and totalled on the IRD payday filing — which
 * previously hardcoded '0.00'. Plus GL-failure surfacing: a failed payroll
 * journal post writes hr_payroll_runs.gl_error instead of vanishing into
 * failed_jobs. Helpers `pes_*`.
 */
function pes_seedPayrollAccounts(): void
{
    foreach ([
        ['5000', 'Wages & Salaries', 'expense'], ['5010', 'KiwiSaver Employer', 'expense'], ['5020', 'ACC Employer Levy', 'expense'],
        ['2100', 'PAYE Payable', 'liability'], ['2110', 'ACC Levy Payable', 'liability'], ['2120', 'KiwiSaver Payable', 'liability'],
        ['2130', 'Student Loan Payable', 'liability'], ['2150', 'ESCT Payable', 'liability'], ['2300', 'Accrued Wages', 'liability'],
    ] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true,
        ]);
    }
    FinFiscalPeriod::create([
        'organization_id' => 1, 'name' => 'FY', 'status' => 'open',
        'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(),
    ]);
}

/** @return array{0: HrPayrollRun, 1: HrPayslip} */
function pes_lockedRunWithPayslip(array $slip = []): array
{
    $user = User::factory()->create(['organization_id' => 1]);
    $profile = HrEmployeeProfile::factory()->create(['user_id' => $user->id]);

    $run = HrPayrollRun::create([
        'tenant_id' => 1,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'locked',
        'locked_at' => now(),
        'total_gross' => $slip['gross_pay'] ?? 3000,
    ]);

    $payslip = HrPayslip::create(array_merge([
        'tenant_id' => 1,
        'payroll_run_id' => $run->id,
        'employee_profile_id' => $profile->id,
        'user_id' => $user->id,
        'pay_period_start' => $run->period_start,
        'pay_period_end' => $run->period_end,
        'gross_pay' => 3000,
        'paye' => 500,
        'acc_levy' => 40,
        'kiwisaver_employee' => 90,
        'kiwisaver_employer' => 90,
        'esct' => 15.75, // 17.5% band on the $90 employer contribution
        'student_loan' => 0,
        'holiday_pay' => 0,
        'total_deductions' => 630,
        'net_pay' => 2370,
        'status' => 'final',
    ], $slip));

    return [$run, $payslip];
}

it('applies the IRD ESCT rate bands to the employer contribution', function () {
    $calc = app(NzPayrollCalculatorService::class);

    expect($calc->esctRate(16000))->toBe(0.105)
        ->and($calc->esctRate(50000))->toBe(0.175)
        ->and($calc->esctRate(80000))->toBe(0.30)
        ->and($calc->esctRate(200000))->toBe(0.33)
        ->and($calc->esctRate(300000))->toBe(0.39)
        ->and($calc->calculateEsct(78000, 90.0))->toBe(27.0) // 30% band
        ->and($calc->calculateEsct(78000, 0.0))->toBe(0.0);
});

it('splits ESCT out of the KiwiSaver remittance in the payroll journal', function () {
    pes_seedPayrollAccounts();
    [$run] = pes_lockedRunWithPayslip();

    app(PayrollJournalService::class)->postPayrollJournal($run);

    $journal = FinJournal::with('lines.account')->where('source_id', $run->id)->firstOrFail();
    $byCode = fn (string $code) => $journal->lines->firstWhere('account.code', $code);

    $dr = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $cr = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    expect(bccomp($dr, $cr, 2))->toBe(0)
        ->and((float) $byCode('5010')->debit)->toBe(90.0)      // employer contribution stays GROSS as the expense
        ->and((float) $byCode('2120')->credit)->toBe(164.25)   // 90 ee + 90 er − 15.75 esct → the fund
        ->and((float) $byCode('2150')->credit)->toBe(15.75);   // esct → IRD
});

it('surfaces a failed GL post on the run and clears it on a successful retry', function () {
    // No accounts seeded yet → the post throws (missing 5000) and the run
    // records why, instead of the failure vanishing into failed_jobs.
    [$run] = pes_lockedRunWithPayslip();

    try {
        PostPayrollJournalJob::dispatchSync($run);
    } catch (\Throwable) {
        // expected — the job rethrows after persisting gl_error
    }

    $run->refresh();
    expect($run->gl_error)->not->toBeNull()
        ->and($run->journal_id)->toBeNull();

    // Fix the chart, retry → posts and clears the surfaced error.
    pes_seedPayrollAccounts();
    PostPayrollJournalJob::dispatchSync($run->fresh());

    $run->refresh();
    expect($run->journal_id)->not->toBeNull()
        ->and($run->gl_error)->toBeNull();
});
