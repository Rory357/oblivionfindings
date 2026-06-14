<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Models\FinPaymentAllocation;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    /**
     * Generate a trial balance as of a given date.
     *
     * For each active account, sum posted journal lines up to asOfDate.
     * Assets/Expenses = debit balance (debits - credits)
     * Liabilities/Equity/Revenue = credit balance (credits - debits)
     */
    public function getTrialBalance(?int $orgId, string $asOfDate): array
    {
        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        $balances = FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->where('fin_journals.organization_id', $orgId)
            ->where('fin_journals.status', 'posted')
            ->where('fin_journals.journal_date', '<=', $asOfDate)
            ->groupBy('fin_journal_lines.account_id')
            ->select(
                'fin_journal_lines.account_id',
                DB::raw('COALESCE(SUM(fin_journal_lines.debit), 0) as total_debits'),
                DB::raw('COALESCE(SUM(fin_journal_lines.credit), 0) as total_credits'),
            )
            ->get()
            ->keyBy('account_id');

        $rows = [];
        $grandTotalDebits = '0';
        $grandTotalCredits = '0';

        foreach ($accounts as $account) {
            $balance = $balances->get($account->id);
            $totalDebits = (float) ($balance->total_debits ?? 0);
            $totalCredits = (float) ($balance->total_credits ?? 0);
            $openingBalance = (float) $account->opening_balance;

            $debitBalance = 0;
            $creditBalance = 0;

            if (in_array($account->type, ['asset', 'expense'])) {
                $net = $totalDebits - $totalCredits + $openingBalance;
                if ($net >= 0) {
                    $debitBalance = round($net, 2);
                } else {
                    $creditBalance = round(abs($net), 2);
                }
            } else {
                $net = $totalCredits - $totalDebits + $openingBalance;
                if ($net >= 0) {
                    $creditBalance = round($net, 2);
                } else {
                    $debitBalance = round(abs($net), 2);
                }
            }

            if ($debitBalance == 0 && $creditBalance == 0) {
                continue;
            }

            $rows[] = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->type,
                'debit_balance' => $debitBalance,
                'credit_balance' => $creditBalance,
            ];

            $grandTotalDebits = bcadd($grandTotalDebits, (string) $debitBalance, 2);
            $grandTotalCredits = bcadd($grandTotalCredits, (string) $creditBalance, 2);
        }

        return [
            'as_of_date' => $asOfDate,
            'rows' => $rows,
            'total_debits' => (float) $grandTotalDebits,
            'total_credits' => (float) $grandTotalCredits,
        ];
    }

    /**
     * Generate a Profit & Loss statement for a date range.
     */
    public function getProfitAndLoss(?int $orgId, string $startDate, string $endDate): array
    {
        $revenueAccounts = $this->getAccountBalancesForPeriod($orgId, 'revenue', $startDate, $endDate);
        $expenseAccounts = $this->getAccountBalancesForPeriod($orgId, 'expense', $startDate, $endDate);

        $totalRevenue = 0;
        $revenue = [];
        foreach ($revenueAccounts as $row) {
            $amount = round((float) $row->total_credits - (float) $row->total_debits, 2);
            if ($amount == 0) {
                continue;
            }
            $revenue[] = [
                'account_code' => $row->code,
                'account_name' => $row->name,
                'sub_type' => $row->sub_type,
                'amount' => $amount,
            ];
            $totalRevenue = bcadd((string) $totalRevenue, (string) $amount, 2);
        }

        $totalExpenses = 0;
        $expenses = [];
        foreach ($expenseAccounts as $row) {
            $amount = round((float) $row->total_debits - (float) $row->total_credits, 2);
            if ($amount == 0) {
                continue;
            }
            $expenses[] = [
                'account_code' => $row->code,
                'account_name' => $row->name,
                'sub_type' => $row->sub_type,
                'amount' => $amount,
            ];
            $totalExpenses = bcadd((string) $totalExpenses, (string) $amount, 2);
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'revenue' => $revenue,
            'total_revenue' => (float) $totalRevenue,
            'expenses' => $expenses,
            'total_expenses' => (float) $totalExpenses,
            'net_profit' => round((float) $totalRevenue - (float) $totalExpenses, 2),
        ];
    }

    /**
     * Generate a Balance Sheet as of a given date.
     */
    public function getBalanceSheet(?int $orgId, string $asOfDate): array
    {
        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        $balances = FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->where('fin_journals.organization_id', $orgId)
            ->where('fin_journals.status', 'posted')
            ->where('fin_journals.journal_date', '<=', $asOfDate)
            ->groupBy('fin_journal_lines.account_id')
            ->select(
                'fin_journal_lines.account_id',
                DB::raw('COALESCE(SUM(fin_journal_lines.debit), 0) as total_debits'),
                DB::raw('COALESCE(SUM(fin_journal_lines.credit), 0) as total_credits'),
            )
            ->get()
            ->keyBy('account_id');

        $assets = [];
        $totalAssets = 0;
        $liabilities = [];
        $totalLiabilities = 0;
        $equity = [];
        $totalEquity = 0;

        foreach ($accounts as $account) {
            $balance = $balances->get($account->id);
            $totalDebits = (float) ($balance->total_debits ?? 0);
            $totalCredits = (float) ($balance->total_credits ?? 0);
            $openingBalance = (float) $account->opening_balance;

            switch ($account->type) {
                case 'asset':
                    $bal = round($totalDebits - $totalCredits + $openingBalance, 2);
                    if ($bal != 0) {
                        $assets[] = [
                            'account_code' => $account->code,
                            'account_name' => $account->name,
                            'sub_type' => $account->sub_type,
                            'balance' => $bal,
                        ];
                        $totalAssets = round($totalAssets + $bal, 2);
                    }
                    break;

                case 'liability':
                    $bal = round($totalCredits - $totalDebits + $openingBalance, 2);
                    if ($bal != 0) {
                        $liabilities[] = [
                            'account_code' => $account->code,
                            'account_name' => $account->name,
                            'sub_type' => $account->sub_type,
                            'balance' => $bal,
                        ];
                        $totalLiabilities = round($totalLiabilities + $bal, 2);
                    }
                    break;

                case 'equity':
                    $bal = round($totalCredits - $totalDebits + $openingBalance, 2);
                    if ($bal != 0) {
                        $equity[] = [
                            'account_code' => $account->code,
                            'account_name' => $account->name,
                            'sub_type' => $account->sub_type,
                            'balance' => $bal,
                        ];
                        $totalEquity = round($totalEquity + $bal, 2);
                    }
                    break;
            }
        }

        // Retained earnings = cumulative P&L (all revenue - expenses up to asOfDate)
        $retainedEarnings = $this->calculateRetainedEarnings($orgId, $asOfDate);
        if ($retainedEarnings != 0) {
            $equity[] = [
                'account_code' => '',
                'account_name' => 'Retained Earnings (Current Year)',
                'sub_type' => 'retained_earnings',
                'balance' => $retainedEarnings,
            ];
            $totalEquity = round($totalEquity + $retainedEarnings, 2);
        }

        return [
            'as_of_date' => $asOfDate,
            'assets' => $assets,
            'total_assets' => $totalAssets,
            'liabilities' => $liabilities,
            'total_liabilities' => $totalLiabilities,
            'equity' => $equity,
            'total_equity' => $totalEquity,
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
        ];
    }

    /**
     * Generate a simplified cash flow statement.
     */
    public function getCashFlow(?int $orgId, string $startDate, string $endDate): array
    {
        // Get bank account GL account IDs
        $bankGlAccountIds = FinBankAccount::forOrganization($orgId)
            ->active()
            ->pluck('gl_account_id')
            ->filter()
            ->values()
            ->toArray();

        if (empty($bankGlAccountIds)) {
            return [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'operating' => [],
                'total_operating' => 0,
                'investing' => [],
                'total_investing' => 0,
                'financing' => [],
                'total_financing' => 0,
                'net_cash_change' => 0,
                'opening_cash' => 0,
                'closing_cash' => 0,
            ];
        }

        // Opening cash: balance up to day before start date
        $openingCash = $this->calculateCashBalance($orgId, $bankGlAccountIds, $startDate);

        // Get all journal lines involving bank accounts during the period
        $bankLines = FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->where('fin_journals.organization_id', $orgId)
            ->where('fin_journals.status', 'posted')
            ->whereBetween('fin_journals.journal_date', [$startDate, $endDate])
            ->whereIn('fin_journal_lines.account_id', $bankGlAccountIds)
            ->select(
                'fin_journal_lines.journal_id',
                'fin_journal_lines.debit',
                'fin_journal_lines.credit',
            )
            ->get();

        $journalIds = $bankLines->pluck('journal_id')->unique()->values()->toArray();

        // Get the contra-entries (non-bank lines) on those journals to classify cash flow
        $contraLines = FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->join('fin_accounts', 'fin_journal_lines.account_id', '=', 'fin_accounts.id')
            ->whereIn('fin_journal_lines.journal_id', $journalIds)
            ->whereNotIn('fin_journal_lines.account_id', $bankGlAccountIds)
            ->select(
                'fin_accounts.name as account_name',
                'fin_accounts.type as account_type',
                'fin_accounts.sub_type as account_sub_type',
                DB::raw('SUM(fin_journal_lines.debit) as total_debit'),
                DB::raw('SUM(fin_journal_lines.credit) as total_credit'),
            )
            ->groupBy('fin_accounts.id', 'fin_accounts.name', 'fin_accounts.type', 'fin_accounts.sub_type')
            ->get();

        $operating = [];
        $totalOperating = 0;
        $investing = [];
        $totalInvesting = 0;
        $financing = [];
        $totalFinancing = 0;

        foreach ($contraLines as $line) {
            // Cash inflow = credit on contra account (debit on bank)
            // Cash outflow = debit on contra account (credit on bank)
            $cashImpact = round((float) $line->total_credit - (float) $line->total_debit, 2);

            $entry = [
                'account_name' => $line->account_name,
                'amount' => $cashImpact,
            ];

            if ($cashImpact == 0) {
                continue;
            }

            if (in_array($line->account_sub_type, ['fixed_asset', 'capital']) || $line->account_type === 'asset' && $line->account_sub_type === 'fixed') {
                $investing[] = $entry;
                $totalInvesting = round($totalInvesting + $cashImpact, 2);
            } elseif ($line->account_type === 'equity') {
                $financing[] = $entry;
                $totalFinancing = round($totalFinancing + $cashImpact, 2);
            } else {
                $operating[] = $entry;
                $totalOperating = round($totalOperating + $cashImpact, 2);
            }
        }

        $netCashChange = round($totalOperating + $totalInvesting + $totalFinancing, 2);
        $closingCash = round($openingCash + $netCashChange, 2);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'operating' => $operating,
            'total_operating' => $totalOperating,
            'investing' => $investing,
            'total_investing' => $totalInvesting,
            'financing' => $financing,
            'total_financing' => $totalFinancing,
            'net_cash_change' => $netCashChange,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
        ];
    }

    /**
     * Aged Payables report — unpaid bills grouped by vendor with aging buckets.
     */
    public function getAgedPayables(?int $orgId): array
    {
        $today = now()->startOfDay();

        $bills = FinBill::forOrganization($orgId)
            ->whereIn('status', ['approved', 'partially_paid'])
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->with('vendor:id,name')
            ->get();

        $vendorBuckets = [];

        foreach ($bills as $bill) {
            $vendorName = $bill->vendor->name ?? 'Unknown';
            $vendorId = $bill->vendor_id;
            $amountDue = $bill->getAmountDue();
            $daysOverdue = $bill->due_date->gt($today) ? 0 : (int) $bill->due_date->diffInDays($today);

            if (! isset($vendorBuckets[$vendorId])) {
                $vendorBuckets[$vendorId] = [
                    'vendor_name' => $vendorName,
                    'current' => 0,
                    'days_1_30' => 0,
                    'days_31_60' => 0,
                    'days_61_90' => 0,
                    'days_90_plus' => 0,
                    'total' => 0,
                ];
            }

            if ($daysOverdue === 0) {
                $vendorBuckets[$vendorId]['current'] = round($vendorBuckets[$vendorId]['current'] + $amountDue, 2);
            } elseif ($daysOverdue <= 30) {
                $vendorBuckets[$vendorId]['days_1_30'] = round($vendorBuckets[$vendorId]['days_1_30'] + $amountDue, 2);
            } elseif ($daysOverdue <= 60) {
                $vendorBuckets[$vendorId]['days_31_60'] = round($vendorBuckets[$vendorId]['days_31_60'] + $amountDue, 2);
            } elseif ($daysOverdue <= 90) {
                $vendorBuckets[$vendorId]['days_61_90'] = round($vendorBuckets[$vendorId]['days_61_90'] + $amountDue, 2);
            } else {
                $vendorBuckets[$vendorId]['days_90_plus'] = round($vendorBuckets[$vendorId]['days_90_plus'] + $amountDue, 2);
            }

            $vendorBuckets[$vendorId]['total'] = round($vendorBuckets[$vendorId]['total'] + $amountDue, 2);
        }

        $rows = array_values($vendorBuckets);

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        $grandTotal = [
            'current' => round(array_sum(array_column($rows, 'current')), 2),
            'days_1_30' => round(array_sum(array_column($rows, 'days_1_30')), 2),
            'days_31_60' => round(array_sum(array_column($rows, 'days_31_60')), 2),
            'days_61_90' => round(array_sum(array_column($rows, 'days_61_90')), 2),
            'days_90_plus' => round(array_sum(array_column($rows, 'days_90_plus')), 2),
            'total' => round(array_sum(array_column($rows, 'total')), 2),
        ];

        return [
            'rows' => $rows,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Aged Receivables report — unpaid invoices grouped by client with aging buckets.
     */
    public function getAgedReceivables(?int $orgId): array
    {
        $today = now()->startOfDay();

        // Live FinInvoice (the legacy App\Models\Invoice table is a write-orphan).
        $invoices = FinInvoice::where('organization_id', $orgId)
            ->where('status', 'sent')
            ->with('client:id,first_name,last_name')
            ->get();

        // Net partial payments (FinPaymentAllocation rows tagged against FinInvoice).
        $paidByInvoice = FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->whereIn('allocatable_id', $invoices->pluck('id'))
            ->groupBy('allocatable_id')
            ->selectRaw('allocatable_id, SUM(amount) as total_paid')
            ->pluck('total_paid', 'allocatable_id');

        $clientBuckets = [];

        foreach ($invoices as $invoice) {
            $clientName = $invoice->client
                ? trim($invoice->client->first_name.' '.$invoice->client->last_name)
                : ($invoice->client_name ?: 'Unknown');
            $clientId = $invoice->client_id ?? ('name:'.$invoice->client_name);
            $amountDue = round((float) $invoice->total_amount - (float) ($paidByInvoice[$invoice->id] ?? 0), 2);
            if ($amountDue <= 0) {
                continue;
            }
            $daysOverdue = $invoice->due_date->gt($today) ? 0 : (int) $invoice->due_date->diffInDays($today);

            if (! isset($clientBuckets[$clientId])) {
                $clientBuckets[$clientId] = [
                    'client_name' => $clientName,
                    'current' => 0,
                    'days_1_30' => 0,
                    'days_31_60' => 0,
                    'days_61_90' => 0,
                    'days_90_plus' => 0,
                    'total' => 0,
                ];
            }

            if ($daysOverdue === 0) {
                $clientBuckets[$clientId]['current'] = round($clientBuckets[$clientId]['current'] + $amountDue, 2);
            } elseif ($daysOverdue <= 30) {
                $clientBuckets[$clientId]['days_1_30'] = round($clientBuckets[$clientId]['days_1_30'] + $amountDue, 2);
            } elseif ($daysOverdue <= 60) {
                $clientBuckets[$clientId]['days_31_60'] = round($clientBuckets[$clientId]['days_31_60'] + $amountDue, 2);
            } elseif ($daysOverdue <= 90) {
                $clientBuckets[$clientId]['days_61_90'] = round($clientBuckets[$clientId]['days_61_90'] + $amountDue, 2);
            } else {
                $clientBuckets[$clientId]['days_90_plus'] = round($clientBuckets[$clientId]['days_90_plus'] + $amountDue, 2);
            }

            $clientBuckets[$clientId]['total'] = round($clientBuckets[$clientId]['total'] + $amountDue, 2);
        }

        $rows = array_values($clientBuckets);

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        $grandTotal = [
            'current' => round(array_sum(array_column($rows, 'current')), 2),
            'days_1_30' => round(array_sum(array_column($rows, 'days_1_30')), 2),
            'days_31_60' => round(array_sum(array_column($rows, 'days_31_60')), 2),
            'days_61_90' => round(array_sum(array_column($rows, 'days_61_90')), 2),
            'days_90_plus' => round(array_sum(array_column($rows, 'days_90_plus')), 2),
            'total' => round(array_sum(array_column($rows, 'total')), 2),
        ];

        return [
            'rows' => $rows,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Funding stream summary — revenue and expenses grouped by funding stream.
     */
    public function getFundingStreamSummary(?int $orgId, string $startDate, string $endDate): array
    {
        $fundingStreams = FinFundingStream::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get();

        $lines = FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->join('fin_accounts', 'fin_journal_lines.account_id', '=', 'fin_accounts.id')
            ->where('fin_journals.organization_id', $orgId)
            ->where('fin_journals.status', 'posted')
            ->whereBetween('fin_journals.journal_date', [$startDate, $endDate])
            ->whereNotNull('fin_journal_lines.funding_stream_id')
            ->select(
                'fin_journal_lines.funding_stream_id',
                'fin_accounts.type as account_type',
                DB::raw('SUM(fin_journal_lines.debit) as total_debits'),
                DB::raw('SUM(fin_journal_lines.credit) as total_credits'),
            )
            ->groupBy('fin_journal_lines.funding_stream_id', 'fin_accounts.type')
            ->get();

        $streamData = [];
        foreach ($fundingStreams as $stream) {
            $streamData[$stream->id] = [
                'funding_stream_id' => $stream->id,
                'funding_stream_name' => $stream->name,
                'funding_stream_code' => $stream->code,
                'revenue' => 0,
                'expenses' => 0,
                'net_margin' => 0,
                'margin_pct' => 0,
            ];
        }

        foreach ($lines as $line) {
            $fsId = $line->funding_stream_id;
            if (! isset($streamData[$fsId])) {
                continue;
            }

            if ($line->account_type === 'revenue') {
                $streamData[$fsId]['revenue'] = round($streamData[$fsId]['revenue'] + (float) $line->total_credits - (float) $line->total_debits, 2);
            } elseif ($line->account_type === 'expense') {
                $streamData[$fsId]['expenses'] = round($streamData[$fsId]['expenses'] + (float) $line->total_debits - (float) $line->total_credits, 2);
            }
        }

        $totalRevenue = 0;
        $totalExpenses = 0;

        foreach ($streamData as &$sd) {
            $sd['net_margin'] = round($sd['revenue'] - $sd['expenses'], 2);
            $sd['margin_pct'] = $sd['revenue'] > 0
                ? round(($sd['net_margin'] / $sd['revenue']) * 100, 1)
                : 0;
            $totalRevenue = round($totalRevenue + $sd['revenue'], 2);
            $totalExpenses = round($totalExpenses + $sd['expenses'], 2);
        }
        unset($sd);

        $rows = array_values($streamData);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rows' => $rows,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'total_net_margin' => round($totalRevenue - $totalExpenses, 2),
        ];
    }

    /**
     * Helper: get account balances for a type within a period.
     */
    private function getAccountBalancesForPeriod(?int $orgId, string $accountType, string $startDate, string $endDate)
    {
        return FinAccount::forOrganization($orgId)
            ->active()
            ->ofType($accountType)
            ->leftJoin('fin_journal_lines', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->leftJoin('fin_journals', function ($join) use ($orgId, $startDate, $endDate) {
                $join->on('fin_journal_lines.journal_id', '=', 'fin_journals.id')
                    ->where('fin_journals.organization_id', $orgId)
                    ->where('fin_journals.status', 'posted')
                    ->whereBetween('fin_journals.journal_date', [$startDate, $endDate]);
            })
            ->select(
                'fin_accounts.id',
                'fin_accounts.code',
                'fin_accounts.name',
                'fin_accounts.sub_type',
                DB::raw('COALESCE(SUM(fin_journal_lines.debit), 0) as total_debits'),
                DB::raw('COALESCE(SUM(fin_journal_lines.credit), 0) as total_credits'),
            )
            ->groupBy('fin_accounts.id', 'fin_accounts.code', 'fin_accounts.name', 'fin_accounts.sub_type')
            ->orderBy('fin_accounts.code')
            ->get();
    }

    /**
     * Helper: calculate retained earnings (cumulative P&L) up to a date.
     */
    private function calculateRetainedEarnings(?int $orgId, string $asOfDate): float
    {
        $result = FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->join('fin_accounts', 'fin_journal_lines.account_id', '=', 'fin_accounts.id')
            ->where('fin_journals.organization_id', $orgId)
            ->where('fin_journals.status', 'posted')
            ->where('fin_journals.journal_date', '<=', $asOfDate)
            ->whereIn('fin_accounts.type', ['revenue', 'expense'])
            ->select(
                'fin_accounts.type',
                DB::raw('SUM(fin_journal_lines.debit) as total_debits'),
                DB::raw('SUM(fin_journal_lines.credit) as total_credits'),
            )
            ->groupBy('fin_accounts.type')
            ->get()
            ->keyBy('type');

        $revenue = $result->get('revenue');
        $expense = $result->get('expense');

        $totalRevenue = $revenue ? (float) $revenue->total_credits - (float) $revenue->total_debits : 0;
        $totalExpenses = $expense ? (float) $expense->total_debits - (float) $expense->total_credits : 0;

        return round($totalRevenue - $totalExpenses, 2);
    }

    /**
     * Helper: calculate cash balance from bank GL accounts up to (but not including) a date.
     */
    private function calculateCashBalance(?int $orgId, array $bankGlAccountIds, string $beforeDate): float
    {
        $result = FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->where('fin_journals.organization_id', $orgId)
            ->where('fin_journals.status', 'posted')
            ->where('fin_journals.journal_date', '<', $beforeDate)
            ->whereIn('fin_journal_lines.account_id', $bankGlAccountIds)
            ->select(
                DB::raw('COALESCE(SUM(fin_journal_lines.debit), 0) as total_debits'),
                DB::raw('COALESCE(SUM(fin_journal_lines.credit), 0) as total_credits'),
            )
            ->first();

        $openingBalances = FinAccount::whereIn('id', $bankGlAccountIds)
            ->sum('opening_balance');

        return round(
            (float) $result->total_debits - (float) $result->total_credits + (float) $openingBalances,
            2
        );
    }
}
