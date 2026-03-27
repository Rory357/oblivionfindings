<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PayrollJournalService
{
    /**
     * GL account code → FinAccount cache (per-request, keyed by orgId:code).
     *
     * @var array<string, FinAccount>
     */
    private array $accountCache = [];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /* ------------------------------------------------------------------
     |  Post a payroll run to the General Ledger
     | ------------------------------------------------------------------ */

    public function postPayrollJournal(HrPayrollRun $payrollRun): FinJournal
    {
        if ($payrollRun->journal_id !== null) {
            throw new InvalidArgumentException(
                "Payroll run #{$payrollRun->id} has already been posted to journal #{$payrollRun->journal_id}."
            );
        }

        $orgId = $payrollRun->tenant_id;

        // Load all payslips for this run
        $payslips = HrPayslip::where('payroll_run_id', $payrollRun->id)->get();

        if ($payslips->isEmpty()) {
            throw new RuntimeException(
                "Payroll run #{$payrollRun->id} has no payslips to post."
            );
        }

        // Aggregate totals across all payslips
        $totalGross              = '0';
        $totalPaye               = '0';
        $totalAccLevy            = '0';
        $totalKiwisaverEmployee  = '0';
        $totalKiwisaverEmployer  = '0';
        $totalStudentLoan        = '0';
        $totalNetPay             = '0';

        foreach ($payslips as $payslip) {
            $totalGross             = bcadd($totalGross, (string) $payslip->gross_pay, 2);
            $totalPaye              = bcadd($totalPaye, (string) $payslip->paye, 2);
            $totalAccLevy           = bcadd($totalAccLevy, (string) $payslip->acc_levy, 2);
            $totalKiwisaverEmployee = bcadd($totalKiwisaverEmployee, (string) $payslip->kiwisaver_employee, 2);
            $totalKiwisaverEmployer = bcadd($totalKiwisaverEmployer, (string) $payslip->kiwisaver_employer, 2);
            $totalStudentLoan       = bcadd($totalStudentLoan, (string) $payslip->student_loan, 2);
            $totalNetPay            = bcadd($totalNetPay, (string) $payslip->net_pay, 2);
        }

        // Build journal lines (only include where amount > 0)
        $lines = [];

        // DR 5000 Wages & Salaries (gross pay)
        if (bccomp($totalGross, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '5000')->id,
                'description' => 'Wages & Salaries',
                'debit'       => $totalGross,
                'credit'      => 0,
            ];
        }

        // DR 5010 KiwiSaver - Employer
        if (bccomp($totalKiwisaverEmployer, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '5010')->id,
                'description' => 'KiwiSaver - Employer Contribution',
                'debit'       => $totalKiwisaverEmployer,
                'credit'      => 0,
            ];
        }

        // CR 2100 PAYE Payable
        if (bccomp($totalPaye, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '2100')->id,
                'description' => 'PAYE Payable',
                'debit'       => 0,
                'credit'      => $totalPaye,
            ];
        }

        // CR 2110 ACC Levy Payable
        if (bccomp($totalAccLevy, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '2110')->id,
                'description' => 'ACC Levy Payable',
                'debit'       => 0,
                'credit'      => $totalAccLevy,
            ];
        }

        // CR 2120 KiwiSaver Payable (employee + employer)
        $totalKiwisaverPayable = bcadd($totalKiwisaverEmployee, $totalKiwisaverEmployer, 2);
        if (bccomp($totalKiwisaverPayable, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '2120')->id,
                'description' => 'KiwiSaver Payable',
                'debit'       => 0,
                'credit'      => $totalKiwisaverPayable,
            ];
        }

        // CR 2130 Student Loan Payable
        if (bccomp($totalStudentLoan, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '2130')->id,
                'description' => 'Student Loan Payable',
                'debit'       => 0,
                'credit'      => $totalStudentLoan,
            ];
        }

        // CR 2300 Accrued Wages (net pay)
        if (bccomp($totalNetPay, '0', 2) > 0) {
            $lines[] = [
                'account_id'  => $this->findAccountByCode($orgId, '2300')->id,
                'description' => 'Accrued Wages / Net Pay',
                'debit'       => 0,
                'credit'      => $totalNetPay,
            ];
        }

        if (count($lines) < 2) {
            throw new RuntimeException(
                "Payroll run #{$payrollRun->id} produced fewer than 2 journal lines. Cannot post."
            );
        }

        $periodStart = $payrollRun->period_start->toDateString();
        $periodEnd   = $payrollRun->period_end->toDateString();

        $journal = $this->journalPostingService->createAndPost($orgId, [
            'journal_date' => $periodEnd,
            'type'         => 'payroll',
            'source_type'  => 'payroll_run',
            'source_id'    => $payrollRun->id,
            'description'  => "Payroll - {$periodStart} to {$periodEnd}",
            'lines'        => $lines,
        ]);

        $payrollRun->update([
            'journal_id'   => $journal->id,
            'gl_posted_at' => now(),
        ]);

        return $journal;
    }

    /* ------------------------------------------------------------------
     |  Reverse a previously posted payroll journal
     | ------------------------------------------------------------------ */

    public function reversePayrollJournal(HrPayrollRun $payrollRun): FinJournal
    {
        if ($payrollRun->journal_id === null) {
            throw new InvalidArgumentException(
                "Payroll run #{$payrollRun->id} has no journal to reverse."
            );
        }

        $journal = FinJournal::findOrFail($payrollRun->journal_id);

        $reversingJournal = $this->journalPostingService->reverse(
            $journal,
            "Reversal of payroll run #{$payrollRun->id}"
        );

        $payrollRun->update([
            'journal_id'   => null,
            'gl_posted_at' => null,
        ]);

        return $reversingJournal;
    }

    /* ------------------------------------------------------------------
     |  Helper: find a GL account by code (cached per request)
     | ------------------------------------------------------------------ */

    public function findAccountByCode(int $orgId, string $code): FinAccount
    {
        $cacheKey = "{$orgId}:{$code}";

        if (isset($this->accountCache[$cacheKey])) {
            return $this->accountCache[$cacheKey];
        }

        $account = FinAccount::where('organization_id', $orgId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new RuntimeException(
                "GL account with code '{$code}' not found (or inactive) for organisation #{$orgId}."
            );
        }

        $this->accountCache[$cacheKey] = $account;

        return $account;
    }
}
