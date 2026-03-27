<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardAggregatorService
{
    /**
     * Get all dashboard data for the finance module.
     */
    public function getDashboardData(?int $orgId): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();

        return [
            'totalRevenue' => $this->getCurrentMonthTotal($orgId, 'revenue', $monthStart, $monthEnd),
            'totalExpenses' => $this->getCurrentMonthTotal($orgId, 'expense', $monthStart, $monthEnd),
            'netProfit' => $this->getCurrentMonthTotal($orgId, 'revenue', $monthStart, $monthEnd)
                - $this->getCurrentMonthTotal($orgId, 'expense', $monthStart, $monthEnd),
            'cashBalance' => $this->getCashBalance($orgId),
            'accountsReceivable' => $this->getOutstandingReceivables($orgId),
            'accountsPayable' => $this->getOutstandingPayables($orgId),
            'revenueByMonth' => $this->getMonthlyTotals($orgId, 'revenue', 6),
            'expensesByMonth' => $this->getMonthlyTotals($orgId, 'expense', 6),
            'topExpenseCategories' => $this->getTopExpenseCategories($orgId, $monthStart, $monthEnd),
            'upcomingBillsDue' => $this->getUpcomingBills($orgId),
            'recentJournals' => $this->getRecentJournals($orgId),
        ];
    }

    private function getCurrentMonthTotal(?int $orgId, string $accountType, string $startDate, string $endDate): float
    {
        $result = FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->join('fin_accounts', 'fin_journal_lines.account_id', '=', 'fin_accounts.id')
            ->when($orgId, fn ($q) => $q->where('fin_journals.organization_id', $orgId))
            ->where('fin_journals.status', 'posted')
            ->whereBetween('fin_journals.journal_date', [$startDate, $endDate])
            ->where('fin_accounts.type', $accountType)
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

    private function getMonthlyTotals(?int $orgId, string $accountType, int $months): array
    {
        $result = [];
        $now = Carbon::now();

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $start = $month->copy()->startOfMonth()->toDateString();
            $end = $month->copy()->endOfMonth()->toDateString();

            $total = $this->getCurrentMonthTotal($orgId, $accountType, $start, $end);

            $result[] = [
                'month' => $month->format('M Y'),
                'amount' => $total,
            ];
        }

        return $result;
    }

    private function getTopExpenseCategories(?int $orgId, string $startDate, string $endDate): array
    {
        return FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->join('fin_accounts', 'fin_journal_lines.account_id', '=', 'fin_accounts.id')
            ->when($orgId, fn ($q) => $q->where('fin_journals.organization_id', $orgId))
            ->where('fin_journals.status', 'posted')
            ->whereBetween('fin_journals.journal_date', [$startDate, $endDate])
            ->where('fin_accounts.type', 'expense')
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
