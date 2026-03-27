<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCashFlowForecast;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinRecurringJournal;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;

class CashFlowForecastService
{
    /**
     * Generate a cash flow forecast for the given period.
     */
    public function generateForecast(?int $orgId, string $periodStart, string $periodEnd, string $periodType = 'weekly'): FinCashFlowForecast
    {
        $openingBalance = $this->calculateOpeningBalance($orgId);
        $periods = $this->generatePeriods($periodStart, $periodEnd, $periodType);

        $forecastData = [];
        $runningBalance = $openingBalance;

        foreach ($periods as $period) {
            $inflows = $this->projectInflows($orgId, $period['start'], $period['end']);
            $outflows = $this->projectOutflows($orgId, $period['start'], $period['end']);

            $netFlow = bcsub((string) $inflows['total'], (string) $outflows['total'], 2);
            $closingBalance = bcadd((string) $runningBalance, $netFlow, 2);

            $forecastData[] = [
                'period_label' => $period['label'],
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'opening_balance' => $runningBalance,
                'inflows' => $inflows,
                'outflows' => $outflows,
                'net_cash_flow' => $netFlow,
                'closing_balance' => $closingBalance,
            ];

            $runningBalance = $closingBalance;
        }

        $forecast = FinCashFlowForecast::create([
            'organization_id' => $orgId,
            'name' => 'Cash Flow Forecast - ' . now()->format('d M Y'),
            'forecast_date' => now()->toDateString(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'period_type' => $periodType,
            'opening_balance' => $openingBalance,
            'forecast_data' => $forecastData,
            'assumptions' => $this->getAssumptions(),
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);

        // Auto-generate scenarios
        $this->generateScenarios($forecast, $forecastData);

        return $forecast->load('scenarios');
    }

    /**
     * Calculate opening balance from all active bank accounts.
     */
    protected function calculateOpeningBalance(?int $orgId): string
    {
        $total = FinBankAccount::forOrganization($orgId)
            ->active()
            ->sum('current_balance');

        return (string) ($total ?? '0.00');
    }

    /**
     * Generate period arrays based on period type.
     */
    protected function generatePeriods(string $periodStart, string $periodEnd, string $periodType): array
    {
        $start = Carbon::parse($periodStart);
        $end = Carbon::parse($periodEnd);
        $periods = [];

        $current = $start->copy();

        while ($current->lt($end)) {
            $periodEnd_ = match ($periodType) {
                'weekly' => $current->copy()->addDays(6)->min($end),
                'fortnightly' => $current->copy()->addDays(13)->min($end),
                'monthly' => $current->copy()->endOfMonth()->min($end),
            };

            $label = match ($periodType) {
                'weekly' => 'Week of ' . $current->format('d M'),
                'fortnightly' => $current->format('d M') . ' - ' . $periodEnd_->format('d M'),
                'monthly' => $current->format('M Y'),
            };

            $periods[] = [
                'label' => $label,
                'start' => $current->toDateString(),
                'end' => $periodEnd_->toDateString(),
            ];

            $current = match ($periodType) {
                'weekly' => $current->addWeek(),
                'fortnightly' => $current->addWeeks(2),
                'monthly' => $current->startOfMonth()->addMonth(),
            };
        }

        return $periods;
    }

    /**
     * Project cash inflows for a period.
     * Sources: outstanding invoices due in period, recurring revenue journals.
     */
    protected function projectInflows(?int $orgId, string $start, string $end): array
    {
        // Outstanding invoices due in this period (accounts receivable)
        $invoiceReceipts = FinInvoice::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereIn('status', ['sent', 'viewed', 'overdue'])
            ->whereBetween('due_date', [$start, $end])
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total')
            ->value('total') ?? '0.00';

        // Overdue invoices expected to be collected (conservative: assume 70% collection)
        $overdueReceipts = FinInvoice::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereIn('status', ['sent', 'viewed', 'overdue'])
            ->where('due_date', '<', $start)
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total')
            ->value('total') ?? '0.00';
        $overdueReceipts = bcmul((string) $overdueReceipts, '0.70', 2);

        // Recurring income from recurring journals (estimate based on template lines)
        $recurringIncome = $this->estimateRecurringIncome($orgId, $start, $end);

        $total = bcadd((string) $invoiceReceipts, (string) $overdueReceipts, 2);
        $total = bcadd($total, (string) $recurringIncome, 2);

        return [
            'total' => $total,
            'invoice_receipts' => (string) $invoiceReceipts,
            'overdue_collections' => $overdueReceipts,
            'recurring_income' => (string) $recurringIncome,
        ];
    }

    /**
     * Project cash outflows for a period.
     * Sources: bills due, recurring expenses, GST payments.
     */
    protected function projectOutflows(?int $orgId, string $start, string $end): array
    {
        // Bills due in this period (unpaid or partially paid)
        $billPayments = FinBill::forOrganization($orgId)
            ->whereIn('status', ['approved', 'partial'])
            ->whereBetween('due_date', [$start, $end])
            ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as total')
            ->value('total') ?? '0.00';

        // Overdue bills expected to be paid
        $overdueBills = FinBill::forOrganization($orgId)
            ->whereIn('status', ['approved', 'partial'])
            ->where('due_date', '<', $start)
            ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as total')
            ->value('total') ?? '0.00';

        // Recurring expenses from recurring journals
        $recurringExpenses = $this->estimateRecurringExpenses($orgId, $start, $end);

        // GST payments due in this period
        $gstPayments = FinGstReturn::forOrganization($orgId)
            ->where('status', 'filed')
            ->where('gst_payable', '>', 0)
            ->whereBetween('period_end', [
                Carbon::parse($start)->subMonths(2)->toDateString(),
                Carbon::parse($end)->toDateString(),
            ])
            ->sum('gst_payable') ?? '0.00';

        $total = bcadd((string) $billPayments, (string) $overdueBills, 2);
        $total = bcadd($total, (string) $recurringExpenses, 2);
        $total = bcadd($total, (string) $gstPayments, 2);

        return [
            'total' => $total,
            'bill_payments' => (string) $billPayments,
            'overdue_bills' => (string) $overdueBills,
            'recurring_expenses' => (string) $recurringExpenses,
            'gst_payments' => (string) $gstPayments,
        ];
    }

    /**
     * Estimate recurring income from active recurring journals.
     */
    protected function estimateRecurringIncome(?int $orgId, string $start, string $end): string
    {
        $recurringJournals = FinRecurringJournal::forOrganization($orgId)
            ->active()
            ->get();

        $total = '0.00';

        foreach ($recurringJournals as $journal) {
            if (! $this->journalFallsInPeriod($journal, $start, $end)) {
                continue;
            }

            $templateLines = $journal->template_lines ?? [];
            foreach ($templateLines as $line) {
                // Credit entries on revenue accounts represent income
                $credit = (string) ($line['credit'] ?? '0');
                if (bccomp($credit, '0', 2) > 0) {
                    $total = bcadd($total, $credit, 2);
                }
            }
        }

        return $total;
    }

    /**
     * Estimate recurring expenses from active recurring journals.
     */
    protected function estimateRecurringExpenses(?int $orgId, string $start, string $end): string
    {
        $recurringJournals = FinRecurringJournal::forOrganization($orgId)
            ->active()
            ->get();

        $total = '0.00';

        foreach ($recurringJournals as $journal) {
            if (! $this->journalFallsInPeriod($journal, $start, $end)) {
                continue;
            }

            $templateLines = $journal->template_lines ?? [];
            foreach ($templateLines as $line) {
                // Debit entries on expense accounts represent expenses
                $debit = (string) ($line['debit'] ?? '0');
                if (bccomp($debit, '0', 2) > 0) {
                    $total = bcadd($total, $debit, 2);
                }
            }
        }

        return $total;
    }

    /**
     * Check if a recurring journal would run during the given period.
     */
    protected function journalFallsInPeriod(FinRecurringJournal $journal, string $start, string $end): bool
    {
        $nextRun = $journal->next_run_date;
        if (! $nextRun) {
            return false;
        }

        $periodStart = Carbon::parse($start);
        $periodEnd = Carbon::parse($end);

        // Check if the next run date falls in or before this period
        // and the frequency would cause it to run during this period
        $frequency = $journal->frequency;
        $current = $nextRun->copy();

        // Iterate through potential run dates
        $maxIterations = 52; // safety limit
        $i = 0;
        while ($current->lte($periodEnd) && $i < $maxIterations) {
            if ($current->gte($periodStart) && $current->lte($periodEnd)) {
                return true;
            }

            $current = match ($frequency) {
                'daily' => $current->addDay(),
                'weekly' => $current->addWeek(),
                'fortnightly' => $current->addWeeks(2),
                'monthly' => $current->addMonth(),
                'quarterly' => $current->addQuarter(),
                'annually' => $current->addYear(),
                default => $current->addMonth(),
            };

            $i++;
        }

        return false;
    }

    /**
     * Get the list of assumptions used in the forecast.
     */
    protected function getAssumptions(): array
    {
        return [
            'Bills are assumed to be paid on their due date.',
            'Invoice receipts are expected on the due date.',
            'Overdue invoices are estimated at 70% collection rate.',
            'Recurring journals will continue at their current frequency.',
            'GST payments are estimated based on filed returns.',
            'Opening balance is the sum of all active bank account balances.',
            'No new bills or invoices beyond currently recorded ones are included.',
        ];
    }

    /**
     * Generate scenario variations: Base Case, Best Case, Worst Case.
     */
    protected function generateScenarios(FinCashFlowForecast $forecast, array $forecastData): void
    {
        $scenarios = [
            [
                'name' => 'Base Case',
                'adjustments' => [
                    'inflow_adjustment' => 1.00,
                    'outflow_adjustment' => 1.00,
                    'description' => 'No adjustments - uses projected figures as-is.',
                ],
            ],
            [
                'name' => 'Best Case',
                'adjustments' => [
                    'inflow_adjustment' => 1.10,
                    'outflow_adjustment' => 0.95,
                    'description' => '+10% inflows, -5% outflows.',
                ],
            ],
            [
                'name' => 'Worst Case',
                'adjustments' => [
                    'inflow_adjustment' => 0.85,
                    'outflow_adjustment' => 1.10,
                    'description' => '-15% inflows, +10% outflows.',
                ],
            ],
        ];

        foreach ($scenarios as $scenario) {
            $adjustedData = $this->applyScenarioAdjustments(
                $forecastData,
                $scenario['adjustments']['inflow_adjustment'],
                $scenario['adjustments']['outflow_adjustment'],
                $forecast->opening_balance,
            );

            $forecast->scenarios()->create([
                'name' => $scenario['name'],
                'adjustments' => $scenario['adjustments'],
                'forecast_data' => $adjustedData,
            ]);
        }
    }

    /**
     * Apply percentage adjustments to forecast data for scenario analysis.
     */
    protected function applyScenarioAdjustments(
        array $forecastData,
        float $inflowMultiplier,
        float $outflowMultiplier,
        string $openingBalance,
    ): array {
        $adjusted = [];
        $runningBalance = $openingBalance;

        foreach ($forecastData as $period) {
            // Adjust inflows
            $adjustedInflows = $period['inflows'];
            $adjustedInflowTotal = bcmul((string) $period['inflows']['total'], (string) $inflowMultiplier, 2);
            $adjustedInflows['total'] = $adjustedInflowTotal;

            // Adjust each inflow category
            foreach (['invoice_receipts', 'overdue_collections', 'recurring_income'] as $key) {
                if (isset($adjustedInflows[$key])) {
                    $adjustedInflows[$key] = bcmul((string) $adjustedInflows[$key], (string) $inflowMultiplier, 2);
                }
            }

            // Adjust outflows
            $adjustedOutflows = $period['outflows'];
            $adjustedOutflowTotal = bcmul((string) $period['outflows']['total'], (string) $outflowMultiplier, 2);
            $adjustedOutflows['total'] = $adjustedOutflowTotal;

            // Adjust each outflow category
            foreach (['bill_payments', 'overdue_bills', 'recurring_expenses', 'gst_payments'] as $key) {
                if (isset($adjustedOutflows[$key])) {
                    $adjustedOutflows[$key] = bcmul((string) $adjustedOutflows[$key], (string) $outflowMultiplier, 2);
                }
            }

            $netFlow = bcsub($adjustedInflowTotal, $adjustedOutflowTotal, 2);
            $closingBalance = bcadd((string) $runningBalance, $netFlow, 2);

            $adjusted[] = [
                'period_label' => $period['period_label'],
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'opening_balance' => $runningBalance,
                'inflows' => $adjustedInflows,
                'outflows' => $adjustedOutflows,
                'net_cash_flow' => $netFlow,
                'closing_balance' => $closingBalance,
            ];

            $runningBalance = $closingBalance;
        }

        return $adjusted;
    }
}
