<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinExternalSettlement;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;
use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PayrollJournalService
{
    use SanitizesCsvOutput;

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
        $organizationId = (int) HrPayrollRun::query()
            ->whereKey($payrollRun->id)
            ->firstOrFail(['tenant_id'])
            ->tenant_id;

        return DB::transaction(function () use ($payrollRun, $organizationId) {
            $this->journalPostingService->lockJournalSequence($organizationId);
            $payrollRun = HrPayrollRun::query()
                ->whereKey($payrollRun->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payrollRun->journal_id !== null) {
                return FinJournal::query()->findOrFail($payrollRun->journal_id);
            }

            $orgId = $payrollRun->tenant_id;
            if ((int) $orgId !== $organizationId) {
                throw new RuntimeException('The payroll organisation changed while acquiring its journal-sequence mutex.');
            }

            $existingJournal = $this->findExistingPayrollJournal($payrollRun);
            if ($existingJournal) {
                $this->linkPayrollRunToJournal($payrollRun, $existingJournal);

                return $existingJournal;
            }

            $this->assertReleasablePayrollRun($payrollRun);

            // Load all payslips for this run
            $payslips = HrPayslip::where('payroll_run_id', $payrollRun->id)->get();

            if ($payslips->isEmpty()) {
                throw new RuntimeException(
                    "Payroll run #{$payrollRun->id} has no payslips to post."
                );
            }

            // Aggregate totals across all payslips
            $totalGross = '0';
            $totalPaye = '0';
            $totalAccLevy = '0';
            $totalKiwisaverEmployee = '0';
            $totalKiwisaverEmployer = '0';
            $totalEsct = '0';
            $totalStudentLoan = '0';

            foreach ($payslips as $payslip) {
                $totalGross = bcadd($totalGross, (string) $payslip->gross_pay, 2);
                $totalPaye = bcadd($totalPaye, (string) $payslip->paye, 2);
                $totalAccLevy = bcadd($totalAccLevy, (string) $payslip->acc_levy, 2);
                $totalKiwisaverEmployee = bcadd($totalKiwisaverEmployee, (string) $payslip->kiwisaver_employee, 2);
                $totalKiwisaverEmployer = bcadd($totalKiwisaverEmployer, (string) $payslip->kiwisaver_employer, 2);
                $totalEsct = bcadd($totalEsct, (string) ($payslip->esct ?? '0'), 2);
                $totalStudentLoan = bcadd($totalStudentLoan, (string) $payslip->student_loan, 2);
            }

            $totalAccruedWages = bcsub(
                bcsub(
                    bcsub($totalGross, $totalPaye, 2),
                    $totalKiwisaverEmployee,
                    2
                ),
                $totalStudentLoan,
                2
            );

            // Build journal lines (only include where amount > 0)
            $lines = [];

            // DR 5000 Wages & Salaries (gross pay)
            if (bccomp($totalGross, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '5000')->id,
                    'description' => 'Wages & Salaries',
                    'debit' => $totalGross,
                    'credit' => 0,
                ];
            }

            // DR 5010 KiwiSaver - Employer
            if (bccomp($totalKiwisaverEmployer, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '5010')->id,
                    'description' => 'KiwiSaver - Employer Contribution',
                    'debit' => $totalKiwisaverEmployer,
                    'credit' => 0,
                ];
            }

            // DR 5020 ACC Employer Levy (expense recognition for the employer ACC obligation)
            if (bccomp($totalAccLevy, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '5020')->id,
                    'description' => 'ACC Employer Levy',
                    'debit' => $totalAccLevy,
                    'credit' => 0,
                ];
            }

            // CR 2100 PAYE Payable
            if (bccomp($totalPaye, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '2100')->id,
                    'description' => 'PAYE Payable',
                    'debit' => 0,
                    'credit' => $totalPaye,
                ];
            }

            // CR 2110 ACC Levy Payable
            if (bccomp($totalAccLevy, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '2110')->id,
                    'description' => 'ACC Levy Payable',
                    'debit' => 0,
                    'credit' => $totalAccLevy,
                ];
            }

            // CR 2120 KiwiSaver Payable (employee + employer NET of ESCT — the
            // fund receives the employer contribution less ESCT, which is
            // remitted to IRD instead)
            $totalKiwisaverPayable = bcsub(bcadd($totalKiwisaverEmployee, $totalKiwisaverEmployer, 2), $totalEsct, 2);
            if (bccomp($totalKiwisaverPayable, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '2120')->id,
                    'description' => 'KiwiSaver Payable',
                    'debit' => 0,
                    'credit' => $totalKiwisaverPayable,
                ];
            }

            // CR 2150 ESCT Payable (to IRD, deducted from the employer
            // contribution — 2140 is Child Support Payable in the chart)
            if (bccomp($totalEsct, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '2150')->id,
                    'description' => 'ESCT Payable',
                    'debit' => 0,
                    'credit' => $totalEsct,
                ];
            }

            // CR 2130 Student Loan Payable
            if (bccomp($totalStudentLoan, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '2130')->id,
                    'description' => 'Student Loan Payable',
                    'debit' => 0,
                    'credit' => $totalStudentLoan,
                ];
            }

            // CR 2300 Accrued Wages (gross less employee PAYE/KiwiSaver/student-loan deductions)
            if (bccomp($totalAccruedWages, '0', 2) > 0) {
                $lines[] = [
                    'account_id' => $this->findAccountByCode($orgId, '2300')->id,
                    'description' => 'Accrued Wages / Net Pay',
                    'debit' => 0,
                    'credit' => $totalAccruedWages,
                ];
            }

            if (count($lines) < 2) {
                throw new RuntimeException(
                    "Payroll run #{$payrollRun->id} produced fewer than 2 journal lines. Cannot post."
                );
            }

            $periodStart = $payrollRun->period_start->toDateString();
            $periodEnd = $payrollRun->period_end->toDateString();

            $journal = $this->journalPostingService->createDraftJournal($orgId, [
                'journal_date' => $periodEnd,
                'type' => 'payroll',
                'source_type' => 'payroll_run',
                'source_id' => $payrollRun->id,
                'description' => "Payroll - {$periodStart} to {$periodEnd}",
                'lines' => $lines,
            ]);

            $payrollRun->update([
                'journal_id' => $journal->id,
            ]);

            $journal = $this->journalPostingService->post($journal);

            $payrollRun->update([
                'gl_posted_at' => now(),
            ]);

            return $journal;
        });
    }

    /* ------------------------------------------------------------------
     |  Pay employee net pay (clear the accrued-wages liability)
     | ------------------------------------------------------------------ */

    /**
     * Post a bank-accepted net-pay instruction. The external-settlement owner
     * must already hold the 000080 sequence mutex, payroll run and settlement
     * row in that order inside one transaction.
     */
    public function postAcceptedNetPay(
        HrPayrollRun $payrollRun,
        FinExternalSettlement $settlement,
        User $actor,
    ): FinJournal {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Accepted net pay must be posted inside the external-settlement transaction.');
        }
        if ($settlement->purpose !== ExternalSettlementService::PAYROLL_NET_PAY
            || $settlement->status !== 'accepted'
            || $settlement->source_type !== $payrollRun->getMorphClass()
            || (int) $settlement->source_id !== (int) $payrollRun->id) {
            throw new RuntimeException('Payroll net pay is missing canonical bank-acceptance evidence.');
        }
        if ($payrollRun->journal_id === null) {
            throw new RuntimeException(
                "Payroll run #{$payrollRun->id} must be posted to the GL before net pay can be paid."
            );
        }
        if ($payrollRun->payment_journal_id !== null) {
            throw new RuntimeException('The payroll run is already linked to a net-pay journal.');
        }

        $this->assertReleasablePayrollRun($payrollRun);

        $orgId = $payrollRun->tenant_id;
        $bankAccount = FinBankAccount::query()
            ->where('organization_id', $orgId)
            ->whereKey($settlement->bank_account_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();
        $bankAccount->loadMissing('glAccount');
        if (! $bankAccount->glAccount?->is_active
            || (int) $bankAccount->glAccount?->organization_id !== (int) $orgId) {
            throw new RuntimeException('The accepted payroll bank account no longer has an active organisation GL account.');
        }
        $payslips = HrPayslip::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($payslips->isEmpty()) {
            throw new RuntimeException(
                "Payroll run #{$payrollRun->id} has no payslips to pay."
            );
        }

        $totalNet = '0';
        foreach ($payslips as $payslip) {
            $totalNet = bcadd($totalNet, (string) $payslip->net_pay, 2);
        }

        if (bccomp($totalNet, '0', 2) <= 0
            || bccomp($totalNet, (string) $settlement->amount, 2) !== 0) {
            throw new RuntimeException(
                "Payroll run #{$payrollRun->id} no longer matches its accepted net-pay file."
            );
        }

        $accruedWagesAccountId = $this->findAccountByCode($orgId, '2300')->id;
        $bankAccountId = (int) $bankAccount->gl_account_id;

        $lines = [
            [
                'account_id' => $accruedWagesAccountId,
                'description' => 'Net pay disbursement',
                'debit' => $totalNet,
                'credit' => 0,
            ],
            [
                'account_id' => $bankAccountId,
                'description' => 'Net pay disbursement',
                'debit' => 0,
                'credit' => $totalNet,
            ],
        ];

        $periodStart = $payrollRun->period_start->toDateString();
        $periodEnd = $payrollRun->period_end->toDateString();

        $journal = $this->journalPostingService->createAndPost($orgId, [
            'journal_date' => $periodEnd,
            'type' => 'payroll',
            'source_type' => FinExternalSettlement::class,
            'source_id' => $settlement->id,
            'description' => "Payroll net pay - {$periodStart} to {$periodEnd}",
            'actor_id' => $actor->id,
            'lines' => $lines,
        ]);

        HrPayslip::where('payroll_run_id', $payrollRun->id)->update([
            'status' => 'paid',
            'payment_date' => now()->toDateString(),
        ]);

        $payrollRun->update([
            'net_paid_at' => now(),
            'payment_journal_id' => $journal->id,
        ]);

        return $journal;
    }

    private function assertReleasablePayrollRun(HrPayrollRun $payrollRun): void
    {
        if (! in_array($payrollRun->source_provenance_status, ['verified', 'legacy_no_paid_leave'], true)) {
            throw new RuntimeException(
                "Payroll run #{$payrollRun->id} has unverified paid-leave provenance and cannot be posted or paid.",
            );
        }
        if ($payrollRun->locked_at === null
            || ! in_array($payrollRun->status, ['locked', 'exported'], true)) {
            throw new RuntimeException(
                "Payroll run #{$payrollRun->id} must be locked before it can be posted or paid.",
            );
        }
    }

    /**
     * Gap 4.2: build a NZ direct-credit (bank batch) CSV from a run's payslip
     * net pay + each employee's bank account, so the bank can pay employees.
     * Mirrors the vendor payment-run bank file. Generated on demand (no storage).
     */
    public function buildNetPayDirectCreditCsv(HrPayrollRun $payrollRun): string
    {
        $payslips = HrPayslip::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->with(['user:id,name', 'employeeProfile:id,bank_account'])
            ->get();

        $period = $payrollRun->period_start->format('d/m/Y').'-'.$payrollRun->period_end->format('d/m/Y');

        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new RuntimeException('Unable to prepare the net-pay bank file.');
        }

        $this->putCsv($handle, ['Employee Name', 'Bank Account Number', 'Amount', 'Reference']);
        foreach ($payslips as $slip) {
            // The shared writer both neutralises spreadsheet formula prefixes
            // and correctly quotes commas, line breaks and double quotes.
            $this->putCsv($handle, [
                $slip->user?->name ?? 'Employee',
                $slip->employeeProfile?->bank_account ?? '',
                bcadd((string) $slip->net_pay, '0.00', 2),
                "Net pay {$period}",
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            throw new RuntimeException('Unable to read the prepared net-pay bank file.');
        }

        return $csv;
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
            'journal_id' => null,
            'gl_posted_at' => null,
        ]);

        return $reversingJournal;
    }

    /* ------------------------------------------------------------------
     |  Helper: find a GL account by code (cached per request)
     | ------------------------------------------------------------------ */

    private function findExistingPayrollJournal(HrPayrollRun $payrollRun): ?FinJournal
    {
        return FinJournal::query()
            ->where('organization_id', $payrollRun->tenant_id)
            ->where('type', 'payroll')
            ->where('source_type', 'payroll_run')
            ->where('source_id', $payrollRun->id)
            ->where('status', 'posted')
            ->first();
    }

    private function linkPayrollRunToJournal(HrPayrollRun $payrollRun, FinJournal $journal): void
    {
        $payrollRun->update([
            'journal_id' => $journal->id,
            'gl_posted_at' => $payrollRun->gl_posted_at ?? $journal->posted_at ?? now(),
        ]);
    }

    public function findAccountByCode(?int $orgId, string $code): FinAccount
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
