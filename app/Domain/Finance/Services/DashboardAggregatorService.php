<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Models\Invoice;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardAggregatorService
{
    /** @var array<int,string> */
    private const PERIODS = ['month', 'quarter', 'fy'];

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

        return [
            'period' => $period,
            'periodLabel' => $this->periodLabel($period),
            'totalRevenue' => $revenue,
            'totalExpenses' => $expenses,
            'netProfit' => round($revenue - $expenses, 2),
            'cashBalance' => $this->getCashBalance($orgId),
            // TODO(3.1) Phase C: still reads the orphaned App\Models\Invoice
            // write-orphan; repoint to FinInvoice and add AR aging buckets.
            'accountsReceivable' => $this->getOutstandingReceivables($orgId),
            'accountsPayable' => $this->getOutstandingPayables($orgId),
            'revenueByMonth' => $this->getMonthlyTotals($orgId, 'revenue', 6, $costCentreIds, $fundingStreamIds),
            'expensesByMonth' => $this->getMonthlyTotals($orgId, 'expense', 6, $costCentreIds, $fundingStreamIds),
            'topExpenseCategories' => $this->getTopExpenseCategories($orgId, $start, $end, $costCentreIds, $fundingStreamIds),
            'revenueByFundingStream' => $this->getRevenueByFundingStream($orgId, $start, $end, $costCentreIds, $fundingStreamIds),
            'upcomingBillsDue' => $this->getUpcomingBills($orgId),
            'apDueWithin7' => $this->getApDueWithin7($orgId),
            'cashRunwayDays' => $this->getCashRunwayDays($orgId),
            'recentJournals' => $this->getRecentJournals($orgId),
        ];
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

    private function getOutstandingReceivables(?int $orgId): float
    {
        return (float) Invoice::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'sent')
            ->sum('total_amount');
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
