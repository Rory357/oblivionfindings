<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinIrdFiling;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Services\Calendar\FinanceCalendarAggregator;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\BillingEntry;
use App\Models\FundingClaim;
use App\Models\ServiceAgreement;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardAggregatorService
{
    /** @var array<int,string> */
    private const PERIODS = ['month', 'quarter', 'fy'];

    public function __construct(
        private AccountsReceivableService $arService,
        private FinanceCalendarAggregator $calendarAggregator,
    ) {}

    /**
     * Get all dashboard data for the finance module, scoped to a period and
     * optional cost-centre (site) / funding-stream (funder) filters.
     *
     * @param  list<int>  $costCentreIds  Cost-centre ids to scope GL aggregates to (empty = all).
     * @param  list<int>  $fundingStreamIds  Funding-stream ids to scope GL aggregates to (empty = all).
     */
    public function getDashboardData(
        ?int $orgId,
        string $period = 'month',
        array $costCentreIds = [],
        array $fundingStreamIds = [],
    ): array {
        $period = in_array($period, self::PERIODS, true) ? $period : 'month';
        [$start, $end] = $this->periodRange($period);

        $revenue = $this->getTotal($orgId, 'revenue', $start, $end, $costCentreIds, $fundingStreamIds);
        $expenses = $this->getTotal($orgId, 'expense', $start, $end, $costCentreIds, $fundingStreamIds);
        // AR is a point-in-time balance (not period-scoped) read from the live
        // FinInvoice table via the canonical AccountsReceivableService.
        $arAging = $this->getArAging($orgId);
        $fundedResidents = $this->getFundedResidentCount($orgId);

        return [
            'period' => $period,
            'periodLabel' => $this->periodLabel($period),
            'totalRevenue' => $revenue,
            'totalExpenses' => $expenses,
            'netProfit' => round($revenue - $expenses, 2),
            'fundedResidents' => $fundedResidents,
            'revenuePerResident' => $fundedResidents > 0 ? round($revenue / $fundedResidents, 2) : 0.0,
            'gstDue' => $this->getGstDueAttention($orgId),
            'cashBalance' => $this->getCashBalance($orgId),
            'accountsReceivable' => $arAging['total'],
            'arAging' => $arAging,
            'accountsPayable' => $this->getOutstandingPayables($orgId),
            'revenueByMonth' => $this->getMonthlyTotals($orgId, 'revenue', 6, $costCentreIds, $fundingStreamIds),
            'expensesByMonth' => $this->getMonthlyTotals($orgId, 'expense', 6, $costCentreIds, $fundingStreamIds),
            'topExpenseCategories' => $this->getTopExpenseCategories($orgId, $start, $end, $costCentreIds, $fundingStreamIds),
            'revenueByFundingStream' => $this->getRevenueByFundingStream($orgId, $start, $end, $costCentreIds, $fundingStreamIds),
            'fundingClaims' => $this->getFundingClaims($orgId),
            'fundingUtilisation' => $this->getFundingUtilisation($orgId),
            'upcomingBillsDue' => $this->getUpcomingBills($orgId),
            'apDueWithin7' => $this->getApDueWithin7($orgId),
            'cashRunwayDays' => $this->getCashRunwayDays($orgId),
            'payrollAwaitingApproval' => $this->getPayrollAwaitingApproval($orgId),
            'paydayFilingDue' => $this->getPaydayFilingDue($orgId),
            'recentJournals' => $this->getRecentJournals($orgId),
        ];
    }

    /**
     * Payroll runs not yet posted to the GL (draft, or locked but unposted) —
     * "awaiting approval/processing". Tenant resolves to the org id here.
     */
    private function getPayrollAwaitingApproval(?int $orgId): array
    {
        $rows = HrPayrollRun::query()
            ->when($orgId, fn ($q) => $q->where('tenant_id', $orgId))
            ->whereIn('status', ['draft', 'locked'])
            ->whereNull('journal_id')
            ->get(['total_gross']);

        return [
            'count' => $rows->count(),
            'total_gross' => round((float) $rows->sum(fn ($r) => (float) $r->total_gross), 2),
        ];
    }

    /**
     * Posted payroll runs that still owe an IRD payday filing (no payday
     * FinIrdFiling links back to them).
     */
    private function getPaydayFilingDue(?int $orgId): array
    {
        $postedRunIds = HrPayrollRun::query()
            ->when($orgId, fn ($q) => $q->where('tenant_id', $orgId))
            ->whereNotNull('journal_id')
            ->pluck('id');

        // IRD filings are application-wide canonical records. The legacy
        // organization storage column is inert and must not be an access or
        // ownership boundary.
        $filedRunIds = FinIrdFiling::query()
            ->ofType('payday')
            ->whereNotNull('payroll_run_id')
            ->pluck('payroll_run_id');

        return ['count' => $postedRunIds->diff($filedRunIds)->count()];
    }

    /** @return array{0:string,1:string} [startDate, endDate] for the period. */
    private function periodRange(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'quarter' => [
                $now->copy()->firstOfQuarter()->toDateString(),
                $now->copy()->lastOfQuarter()->toDateString(),
            ],
            'fy' => $this->financialYearRange($now),
            default => [
                $now->copy()->startOfMonth()->toDateString(),
                $now->copy()->endOfMonth()->toDateString(),
            ],
        };
    }

    /** NZ financial year: 1 Apr – 31 Mar. */
    private function financialYearRange(Carbon $now): array
    {
        $startYear = $now->month >= 4 ? $now->year : $now->year - 1;

        return [
            Carbon::create($startYear, 4, 1)->toDateString(),
            Carbon::create($startYear + 1, 3, 31)->toDateString(),
        ];
    }

    private function periodLabel(string $period): string
    {
        $now = Carbon::now();

        return match ($period) {
            'quarter' => 'Q'.$now->quarter.' '.$now->year,
            'fy' => 'FY'.($now->month >= 4 ? $now->year + 1 : $now->year),
            default => $now->format('F Y'),
        };
    }

    /**
     * Apply the org / cost-centre / funding-stream filters that are common to
     * every posted-GL aggregate query.
     *
     * @param  list<int>  $costCentreIds
     * @param  list<int>  $fundingStreamIds
     */
    private function applyFilters(
        QueryBuilder $query,
        ?int $orgId,
        array $costCentreIds,
        array $fundingStreamIds,
    ): QueryBuilder {
        return $query
            ->when($orgId, fn ($q) => $q->where('fin_journals.organization_id', $orgId))
            ->where('fin_journals.status', 'posted')
            ->when($costCentreIds, fn ($q) => $q->whereIn('fin_journal_lines.cost_centre_id', $costCentreIds))
            ->when($fundingStreamIds, fn ($q) => $q->whereIn('fin_journal_lines.funding_stream_id', $fundingStreamIds));
    }

    private function getTotal(
        ?int $orgId,
        string $accountType,
        string $startDate,
        string $endDate,
        array $costCentreIds = [],
        array $fundingStreamIds = [],
    ): float {
        $query = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->join('fin_accounts', 'fin_journal_lines.account_id', '=', 'fin_accounts.id')
            ->whereBetween('fin_journals.journal_date', [$startDate, $endDate])
            ->where('fin_accounts.type', $accountType);

        $result = $this->applyFilters($query, $orgId, $costCentreIds, $fundingStreamIds)
            ->select(
                DB::raw('COALESCE(SUM(fin_journal_lines.debit), 0) as total_debits'),
                DB::raw('COALESCE(SUM(fin_journal_lines.credit), 0) as total_credits'),
            )
            ->first();

        if ($accountType === 'revenue') {
            return round((float) $result->total_credits - (float) $result->total_debits, 2);
        }

        return round((float) $result->total_debits - (float) $result->total_credits, 2);
    }

    private function getCashBalance(?int $orgId): float
    {
        return (float) FinBankAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('is_active', true)
            ->sum('current_balance');
    }

    /**
     * Accounts-receivable aging snapshot, sourced from the canonical
     * AccountsReceivableService (live FinInvoice, net of FinPaymentAllocation).
     * Buckets keyed for the frontend; `over60` = 61–90 + 90+ for the AR KPI.
     */
    private function getArAging(?int $orgId): array
    {
        $totals = $this->arService->getAgedReceivables($orgId)['totals'];

        return [
            'current' => (float) $totals['current'],
            'd1_30' => (float) $totals['1_30'],
            'd31_60' => (float) $totals['31_60'],
            'd61_90' => (float) $totals['61_90'],
            'd90_plus' => (float) $totals['90_plus'],
            'over60' => round((float) $totals['61_90'] + (float) $totals['90_plus'], 2),
            'total' => (float) $totals['total'],
        ];
    }

    private function getOutstandingPayables(?int $orgId): float
    {
        return (float) FinBill::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereIn('status', ['approved', 'partially_paid'])
            ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as total_outstanding')
            ->value('total_outstanding');
    }

    private function getMonthlyTotals(
        ?int $orgId,
        string $accountType,
        int $months,
        array $costCentreIds = [],
        array $fundingStreamIds = [],
    ): array {
        $result = [];
        $now = Carbon::now();

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $start = $month->copy()->startOfMonth()->toDateString();
            $end = $month->copy()->endOfMonth()->toDateString();

            $result[] = [
                'month' => $month->format('M Y'),
                'amount' => $this->getTotal($orgId, $accountType, $start, $end, $costCentreIds, $fundingStreamIds),
            ];
        }

        return $result;
    }

    private function getTopExpenseCategories(
        ?int $orgId,
        string $startDate,
        string $endDate,
        array $costCentreIds = [],
        array $fundingStreamIds = [],
    ): array {
        $query = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->join('fin_accounts', 'fin_journal_lines.account_id', '=', 'fin_accounts.id')
            ->whereBetween('fin_journals.journal_date', [$startDate, $endDate])
            ->where('fin_accounts.type', 'expense');

        return $this->applyFilters($query, $orgId, $costCentreIds, $fundingStreamIds)
            ->select(
                'fin_accounts.name as account_name',
                DB::raw('SUM(fin_journal_lines.debit) - SUM(fin_journal_lines.credit) as total'),
            )
            ->groupBy('fin_accounts.id', 'fin_accounts.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'account_name' => $row->account_name,
                'amount' => round((float) $row->total, 2),
            ])
            ->toArray();
    }

    /**
     * Revenue grouped by funding stream (real GL — lines tagged with
     * funding_stream_id), period-scoped. Untagged revenue → "Unassigned".
     */
    private function getRevenueByFundingStream(
        ?int $orgId,
        string $startDate,
        string $endDate,
        array $costCentreIds = [],
        array $fundingStreamIds = [],
    ): array {
        $query = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->join('fin_accounts', 'fin_journal_lines.account_id', '=', 'fin_accounts.id')
            ->leftJoin('fin_funding_streams', 'fin_journal_lines.funding_stream_id', '=', 'fin_funding_streams.id')
            ->whereBetween('fin_journals.journal_date', [$startDate, $endDate])
            ->where('fin_accounts.type', 'revenue');

        return $this->applyFilters($query, $orgId, $costCentreIds, $fundingStreamIds)
            ->select(
                DB::raw("COALESCE(fin_funding_streams.name, 'Unassigned') as stream_name"),
                DB::raw('SUM(fin_journal_lines.credit) - SUM(fin_journal_lines.debit) as total'),
            )
            ->groupBy('fin_funding_streams.id', 'fin_funding_streams.name')
            ->havingRaw('SUM(fin_journal_lines.credit) - SUM(fin_journal_lines.debit) <> 0')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->stream_name,
                'amount' => round((float) $row->total, 2),
            ])
            ->toArray();
    }

    /**
     * Recent funding claims for the dashboard table. Funder name comes from the
     * linked service agreement's funding_body. Org-scoped snapshot (claims have
     * no GL funding-stream dimension, so the funder filter doesn't apply here).
     */
    private function getFundingClaims(?int $orgId): array
    {
        return FundingClaim::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with('serviceAgreement:id,funding_body')
            ->orderByDesc('period_end')
            ->limit(6)
            ->get()
            ->map(fn (FundingClaim $claim) => [
                'reference' => $claim->claim_reference ?: ('FC-'.$claim->id),
                'funder' => $claim->serviceAgreement?->funding_body ?: 'Unassigned',
                'period' => $claim->period_end?->format('M Y') ?? '—',
                'status' => $claim->status,
                'amount' => (float) $claim->total_amount,
            ])
            ->toArray();
    }

    /**
     * Funding-claim utilisation buckets (point-in-time snapshot from existing
     * data, no invented figures):
     *  - claimed & paid: FundingClaim status=paid
     *  - awaiting remittance: claimed but unpaid (status submitted/approved)
     *  - delivered, not yet claimed: pending BillingEntry within the last 90 days
     *  - write-off risk: pending BillingEntry older than 90 days
     * utilisation_pct = claimed value ÷ total deliverable value.
     */
    private function getFundingUtilisation(?int $orgId): array
    {
        $byStatus = FundingClaim::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw('status, COALESCE(SUM(total_amount), 0) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $paid = (float) ($byStatus['paid'] ?? 0);
        $awaiting = (float) ($byStatus['submitted'] ?? 0) + (float) ($byStatus['approved'] ?? 0);

        $cutoff = Carbon::now()->subDays(90)->toDateString();
        $deliveredUnclaimed = (float) BillingEntry::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'pending')
            ->where('service_date', '>=', $cutoff)
            ->sum('amount');
        $writeOffRisk = (float) BillingEntry::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'pending')
            ->where('service_date', '<', $cutoff)
            ->sum('amount');

        $claimed = $paid + $awaiting;
        $unclaimed = $deliveredUnclaimed + $writeOffRisk;
        $deliverable = $claimed + $unclaimed;

        return [
            'claimed_paid' => round($paid, 2),
            'awaiting_remittance' => round($awaiting, 2),
            'delivered_unclaimed' => round($deliveredUnclaimed, 2),
            'write_off_risk' => round($writeOffRisk, 2),
            'unclaimed_total' => round($unclaimed, 2),
            'utilisation_pct' => $deliverable > 0 ? (int) round(($claimed / $deliverable) * 100) : 0,
        ];
    }

    /** Distinct residents (clients) on active service agreements — the funded population. */
    private function getFundedResidentCount(?int $orgId): int
    {
        return (int) ServiceAgreement::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'active')
            ->distinct()
            ->count('client_id');
    }

    /**
     * The next unsettled GST obligation within ~45 days, from the finance
     * calendar's GST provider (computed IRD deadlines). Null when none is due.
     */
    private function getGstDueAttention(?int $orgId): ?array
    {
        $items = $this->calendarAggregator->itemsForRange(
            $orgId,
            Carbon::today(),
            Carbon::today()->addDays(45),
            ['sources' => ['gst_due']],
        );

        foreach ($items as $item) {
            if (in_array($item->status, ['due', 'overdue'], true)) {
                return [
                    'due' => $item->start,
                    'amount' => $item->amount,
                    'status' => $item->status,
                    'ref' => $item->ref,
                ];
            }
        }

        return null;
    }

    private function getUpcomingBills(?int $orgId): array
    {
        return FinBill::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereIn('status', ['approved', 'partially_paid'])
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->with('vendor:id,name')
            ->orderBy('due_date')
            ->limit(10)
            ->get()
            ->map(fn (FinBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'vendor_name' => $bill->vendor?->name ?? 'Unknown',
                'due_date' => $bill->due_date->toDateString(),
                'amount_due' => $bill->getAmountDue(),
            ])
            ->toArray();
    }

    /** AP falling due within the next 7 days — count + outstanding total. */
    private function getApDueWithin7(?int $orgId): array
    {
        $rows = FinBill::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereIn('status', ['approved', 'partially_paid'])
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->get(['total_amount', 'amount_paid']);

        return [
            'count' => $rows->count(),
            'total' => round($rows->sum(fn ($b) => (float) $b->total_amount - (float) $b->amount_paid), 2),
        ];
    }

    /**
     * Cash runway estimate: cash on hand ÷ average daily expense over the
     * trailing 90 days. Null when there is no recent expense to project from.
     */
    private function getCashRunwayDays(?int $orgId): ?int
    {
        $cash = $this->getCashBalance($orgId);
        $expenses = $this->getTotal(
            $orgId,
            'expense',
            Carbon::now()->subDays(90)->toDateString(),
            Carbon::now()->toDateString(),
        );

        if ($expenses <= 0) {
            return null;
        }

        $perDay = $expenses / 90;

        return $perDay > 0 ? (int) round($cash / $perDay) : null;
    }

    private function getRecentJournals(?int $orgId): array
    {
        return FinJournal::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'posted')
            ->with('createdBy:id,name')
            ->orderByDesc('posted_at')
            ->limit(5)
            ->get()
            ->map(fn (FinJournal $journal) => [
                'id' => $journal->id,
                'journal_number' => $journal->journal_number,
                'journal_date' => $journal->journal_date->toDateString(),
                'description' => $journal->description,
                'total_amount' => (float) $journal->total_amount,
                'type' => $journal->type,
                'created_by' => $journal->createdBy?->name,
            ])
            ->toArray();
    }
}
