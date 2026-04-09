<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Domain\Hr\Models\HrPayslip;
use App\Models\Timesheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Creates cost allocation records from payroll journals.
 *
 * This service does NOT create new financial events or journals.
 * It creates fin_cost_allocations entries that link the EXISTING
 * payroll journal to operational dimensions (site, client, staff, shift).
 *
 * Two allocation types:
 *   1. payroll_cost   = gross wages per employee (DR 5000)
 *   2. employer_oncost = employer KiwiSaver (DR 5010) + employer ACC levy
 *
 * Both types use the SAME timesheet-based attribution logic to ensure
 * on-costs follow wages to the correct site/client.
 *
 * Idempotency:
 *   - Wages: hr_payroll_runs.cost_allocated_at
 *   - On-costs: hr_payroll_runs.oncost_allocated_at
 *   These are separate flags so on-costs can be backfilled independently.
 */
class PayrollCostAllocationService
{
    /**
     * Process BOTH wage and on-cost allocations for a payroll run.
     *
     * @return array{wages: array, oncosts: array}
     */
    public function allocate(HrPayrollRun $payrollRun): array
    {
        $wages = $this->allocateWages($payrollRun);
        $oncosts = $this->allocateEmployerOncosts($payrollRun);

        return ['wages' => $wages, 'oncosts' => $oncosts];
    }

    /**
     * Allocate gross wages (event_type: payroll_cost).
     * Idempotency guard: cost_allocated_at.
     *
     * @return array{allocated_count: int, total_amount: string, skipped: int}
     */
    public function allocateWages(HrPayrollRun $payrollRun): array
    {
        if ($payrollRun->cost_allocated_at !== null) {
            return ['allocated_count' => 0, 'total_amount' => '0.00', 'skipped' => 0];
        }

        $this->validateJournal($payrollRun);

        $journal = FinJournal::find($payrollRun->journal_id);
        $journal->loadMissing('lines');

        $wagesLineId = $this->findDebitLineId($journal, '5000', $payrollRun->tenant_id);
        $items = HrPayrollRunItem::where('payroll_run_id', $payrollRun->id)->get();

        if ($items->isEmpty()) {
            $payrollRun->update(['cost_allocated_at' => now()]);

            return ['allocated_count' => 0, 'total_amount' => '0.00', 'skipped' => 0];
        }

        return DB::transaction(function () use ($payrollRun, $journal, $wagesLineId, $items) {
            $result = $this->allocateItemAmounts(
                $items,
                fn (HrPayrollRunItem $item) => (string) $item->gross_pay,
                $journal->id,
                $wagesLineId,
                'payroll_cost',
                $payrollRun->period_end,
                $payrollRun->tenant_id,
            );

            $payrollRun->update(['cost_allocated_at' => now()]);

            Log::info("PayrollCostAllocationService: Run #{$payrollRun->id} wages — {$result['allocated_count']} allocations, \${$result['total_amount']}");

            return $result;
        });
    }

    /**
     * Allocate employer on-costs (event_type: employer_oncost).
     * Idempotency guard: oncost_allocated_at.
     *
     * On-costs per employee = kiwisaver_employer + acc_levy (from payslip).
     * These follow the SAME site/client split as wages.
     *
     * Journal line: DR 5010 (KiwiSaver Employer) for the KiwiSaver portion.
     * ACC levy has no separate debit line in the journal — it's posted as CR 2110.
     * We link on-cost allocations to the 5010 line if it exists, otherwise the 5000 line.
     *
     * @return array{allocated_count: int, total_amount: string, skipped: int}
     */
    public function allocateEmployerOncosts(HrPayrollRun $payrollRun): array
    {
        if ($payrollRun->oncost_allocated_at !== null) {
            return ['allocated_count' => 0, 'total_amount' => '0.00', 'skipped' => 0];
        }

        $this->validateJournal($payrollRun);

        $journal = FinJournal::find($payrollRun->journal_id);
        $journal->loadMissing('lines');

        // Prefer employer on-cost debit lines: 5010 (KiwiSaver) → 5020 (ACC) → 5000 (Wages fallback)
        $oncostLineId = $this->findDebitLineId($journal, '5010', $payrollRun->tenant_id)
            ?? $this->findDebitLineId($journal, '5020', $payrollRun->tenant_id)
            ?? $this->findDebitLineId($journal, '5000', $payrollRun->tenant_id);

        if (! $oncostLineId) {
            $payrollRun->update(['oncost_allocated_at' => now()]);

            return ['allocated_count' => 0, 'total_amount' => '0.00', 'skipped' => 0];
        }

        // Build a map of user_id → employer on-cost from payslips
        $payslips = HrPayslip::where('payroll_run_id', $payrollRun->id)->get();
        $oncostByUser = [];

        foreach ($payslips as $payslip) {
            $ksEmployer = (string) ($payslip->kiwisaver_employer ?? '0');
            $accLevy = (string) ($payslip->acc_levy ?? '0');
            $total = bcadd($ksEmployer, $accLevy, 2);

            if (bccomp($total, '0', 2) > 0) {
                $oncostByUser[$payslip->user_id] = $total;
            }
        }

        if (empty($oncostByUser)) {
            $payrollRun->update(['oncost_allocated_at' => now()]);

            return ['allocated_count' => 0, 'total_amount' => '0.00', 'skipped' => 0];
        }

        $items = HrPayrollRunItem::where('payroll_run_id', $payrollRun->id)->get();

        return DB::transaction(function () use ($payrollRun, $journal, $oncostLineId, $items, $oncostByUser) {
            $result = $this->allocateItemAmounts(
                $items,
                fn (HrPayrollRunItem $item) => $oncostByUser[$item->user_id] ?? '0',
                $journal->id,
                $oncostLineId,
                'employer_oncost',
                $payrollRun->period_end,
                $payrollRun->tenant_id,
            );

            $payrollRun->update(['oncost_allocated_at' => now()]);

            Log::info("PayrollCostAllocationService: Run #{$payrollRun->id} on-costs — {$result['allocated_count']} allocations, \${$result['total_amount']}");

            return $result;
        });
    }

    /**
     * Process all payroll runs that have been GL-posted but not yet fully allocated.
     * Handles both wage-only backfill (PR8) and on-cost backfill (PR9).
     */
    public function allocateAll(?int $tenantId = null): array
    {
        $query = HrPayrollRun::query()
            ->whereNotNull('journal_id')
            ->whereNotNull('gl_posted_at')
            ->where(function ($q) {
                $q->whereNull('cost_allocated_at')
                    ->orWhereNull('oncost_allocated_at');
            });

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $runs = $query->get();
        $results = ['processed' => 0, 'failed' => 0, 'wage_allocations' => 0, 'oncost_allocations' => 0];

        foreach ($runs as $run) {
            try {
                $result = $this->allocate($run);
                $results['processed']++;
                $results['wage_allocations'] += $result['wages']['allocated_count'];
                $results['oncost_allocations'] += $result['oncosts']['allocated_count'];
            } catch (\Throwable $e) {
                $results['failed']++;
                Log::error("PayrollCostAllocationService: Failed for run #{$run->id}: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * Backfill ONLY employer on-costs for runs that already have wage allocations.
     * Safe to run alongside existing data — will not duplicate wages.
     */
    public function backfillOncostsOnly(?int $tenantId = null): array
    {
        $query = HrPayrollRun::query()
            ->whereNotNull('journal_id')
            ->whereNotNull('gl_posted_at')
            ->whereNotNull('cost_allocated_at')  // Wages already done
            ->whereNull('oncost_allocated_at');   // On-costs not yet done

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $runs = $query->get();
        $results = ['processed' => 0, 'failed' => 0, 'oncost_allocations' => 0];

        foreach ($runs as $run) {
            try {
                $result = $this->allocateEmployerOncosts($run);
                $results['processed']++;
                $results['oncost_allocations'] += $result['allocated_count'];
            } catch (\Throwable $e) {
                $results['failed']++;
                Log::error("PayrollCostAllocationService: On-cost backfill failed for run #{$run->id}: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /* ------------------------------------------------------------------ */
    /*  Shared Allocation Engine                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Allocate amounts for each payroll run item using timesheet-based attribution.
     *
     * @param  \Illuminate\Support\Collection  $items  HrPayrollRunItem collection
     * @param  callable  $amountResolver  fn(HrPayrollRunItem) => string amount
     * @param  int  $journalId
     * @param  int  $journalLineId
     * @param  string  $eventType  'payroll_cost' or 'employer_oncost'
     * @param  mixed  $eventDate
     * @param  int|null  $tenantId
     * @return array{allocated_count: int, total_amount: string, skipped: int}
     */
    private function allocateItemAmounts(
        $items,
        callable $amountResolver,
        int $journalId,
        int $journalLineId,
        string $eventType,
        $eventDate,
        ?int $tenantId,
    ): array {
        $allocatedCount = 0;
        $totalAmount = '0';
        $skipped = 0;

        foreach ($items as $item) {
            $amount = $amountResolver($item);

            if (bccomp($amount, '0', 2) <= 0) {
                $skipped++;

                continue;
            }

            $dimensions = $this->resolveDimensions($item, $tenantId);

            if ($dimensions['split']) {
                // Recalculate split amounts for this specific total
                $splits = $this->recalculateSplitAmounts($dimensions['splits'], $amount);

                foreach ($splits as $split) {
                    FinCostAllocation::create([
                        'journal_id' => $journalId,
                        'journal_line_id' => $journalLineId,
                        'financial_event_id' => null,
                        'site_id' => $split['site_id'],
                        'client_id' => $split['client_id'],
                        'staff_id' => $item->user_id,
                        'asset_id' => null,
                        'shift_id' => $split['shift_id'],
                        'amount' => $split['amount'],
                        'event_type' => $eventType,
                        'event_date' => $eventDate,
                    ]);
                    $allocatedCount++;
                    $totalAmount = bcadd($totalAmount, $split['amount'], 2);
                }
            } else {
                FinCostAllocation::create([
                    'journal_id' => $journalId,
                    'journal_line_id' => $journalLineId,
                    'financial_event_id' => null,
                    'site_id' => $dimensions['site_id'],
                    'client_id' => $dimensions['client_id'],
                    'staff_id' => $item->user_id,
                    'asset_id' => null,
                    'shift_id' => $dimensions['shift_id'],
                    'amount' => $amount,
                    'event_type' => $eventType,
                    'event_date' => $eventDate,
                ]);
                $allocatedCount++;
                $totalAmount = bcadd($totalAmount, $amount, 2);
            }
        }

        return [
            'allocated_count' => $allocatedCount,
            'total_amount' => $totalAmount,
            'skipped' => $skipped,
        ];
    }

    /**
     * Recalculate split amounts for a different total using the same proportions.
     * The splits array already has site/client/shift and the original proportional minutes.
     */
    private function recalculateSplitAmounts(array $originalSplits, string $newTotal): array
    {
        // Calculate total of original amounts to get proportions
        $originalTotal = '0';
        foreach ($originalSplits as $split) {
            $originalTotal = bcadd($originalTotal, $split['amount'], 2);
        }

        if (bccomp($originalTotal, '0', 2) <= 0) {
            // Can't calculate proportions — give everything to the first split
            $result = $originalSplits;
            $result[0]['amount'] = $newTotal;
            for ($i = 1; $i < count($result); $i++) {
                $result[$i]['amount'] = '0.00';
            }

            return $result;
        }

        $result = [];
        $allocated = '0';

        foreach ($originalSplits as $i => $split) {
            if ($i === count($originalSplits) - 1) {
                $amount = bcsub($newTotal, $allocated, 2);
            } else {
                $fraction = bcdiv($split['amount'], $originalTotal, 6);
                $amount = bcmul($newTotal, $fraction, 2);
            }

            $result[] = array_merge($split, ['amount' => $amount]);
            $allocated = bcadd($allocated, $amount, 2);
        }

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /*  Dimension Resolution (unchanged logic from PR8)                    */
    /* ------------------------------------------------------------------ */

    private function resolveDimensions(HrPayrollRunItem $item, ?int $tenantId): array
    {
        $timesheetIds = $item->timesheet_ids;

        if (! empty($timesheetIds) && is_array($timesheetIds)) {
            return $this->resolveFromTimesheets($item, $timesheetIds);
        }

        return $this->resolveFromEmployeeProfile($item->user_id, $tenantId);
    }

    private function resolveFromTimesheets(HrPayrollRunItem $item, array $timesheetIds): array
    {
        $timesheets = Timesheet::whereIn('id', $timesheetIds)
            ->get(['id', 'shift_id', 'shift_site_id', 'client_id', 'starts_at', 'ends_at', 'break_minutes']);

        if ($timesheets->isEmpty()) {
            return $this->resolveFromEmployeeProfile($item->user_id, null);
        }

        $groups = [];
        $totalMinutes = 0;

        foreach ($timesheets as $ts) {
            if (! $ts->starts_at || ! $ts->ends_at) {
                continue;
            }

            $minutes = max($ts->starts_at->diffInMinutes($ts->ends_at) - (int) $ts->break_minutes, 0);
            $key = ($ts->shift_site_id ?? 0) . ':' . ($ts->client_id ?? 0);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'site_id' => $ts->shift_site_id,
                    'client_id' => $ts->client_id,
                    'shift_id' => $ts->shift_id,
                    'minutes' => 0,
                ];
            }
            $groups[$key]['minutes'] += $minutes;
            $totalMinutes += $minutes;
        }

        if (count($groups) === 1) {
            $g = reset($groups);

            return [
                'split' => false,
                'site_id' => $g['site_id'],
                'client_id' => $g['client_id'],
                'shift_id' => $g['shift_id'],
            ];
        }

        if (count($groups) > 1 && $totalMinutes > 0) {
            $grossPay = (string) $item->gross_pay;
            $splits = [];
            $allocated = '0';

            $groupList = array_values($groups);
            foreach ($groupList as $i => $g) {
                if ($i === count($groupList) - 1) {
                    $amount = bcsub($grossPay, $allocated, 2);
                } else {
                    $fraction = bcdiv((string) $g['minutes'], (string) $totalMinutes, 6);
                    $amount = bcmul($grossPay, $fraction, 2);
                }

                $splits[] = [
                    'site_id' => $g['site_id'],
                    'client_id' => $g['client_id'],
                    'shift_id' => $g['shift_id'],
                    'amount' => $amount,
                ];
                $allocated = bcadd($allocated, $amount, 2);
            }

            return ['split' => true, 'splits' => $splits];
        }

        $first = $timesheets->first();

        return [
            'split' => false,
            'site_id' => $first->shift_site_id,
            'client_id' => $first->client_id,
            'shift_id' => $first->shift_id,
        ];
    }

    private function resolveFromEmployeeProfile(int $userId, ?int $tenantId): array
    {
        $profile = HrEmployeeProfile::where('user_id', $userId)
            ->where('is_active', true)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->first(['primary_site_id']);

        return [
            'split' => false,
            'site_id' => $profile?->primary_site_id,
            'client_id' => null,
            'shift_id' => null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function validateJournal(HrPayrollRun $payrollRun): void
    {
        if (! $payrollRun->journal_id) {
            throw new RuntimeException(
                "Payroll run #{$payrollRun->id} has no journal_id — cannot create cost allocations before GL posting."
            );
        }

        $journal = FinJournal::find($payrollRun->journal_id);
        if (! $journal || $journal->status !== 'posted') {
            throw new RuntimeException(
                "Payroll run #{$payrollRun->id} journal #{$payrollRun->journal_id} is not in posted status."
            );
        }
    }

    /**
     * Find a debit journal line by account code.
     */
    private function findDebitLineId(FinJournal $journal, string $accountCode, ?int $orgId): ?int
    {
        $accountId = $this->getAccountId($orgId, $accountCode);

        if (! $accountId) {
            return null;
        }

        $line = $journal->lines->first(
            fn ($line) => bccomp((string) $line->debit, '0', 2) > 0 && $line->account_id === $accountId,
        );

        // Fallback: first debit line regardless of account
        if (! $line && $accountCode === '5000') {
            $line = $journal->lines->first(fn ($line) => bccomp((string) $line->debit, '0', 2) > 0);
        }

        return $line?->id;
    }

    private function getAccountId(?int $orgId, string $code): ?int
    {
        static $cache = [];
        $key = "{$orgId}:{$code}";

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $account = FinAccount::where('organization_id', $orgId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        $cache[$key] = $account?->id;

        return $cache[$key];
    }
}
