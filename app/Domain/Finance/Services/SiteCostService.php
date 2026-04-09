<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinCostAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates all costs allocated to a site across every operational module.
 *
 * Data source: fin_cost_allocations table, which is populated by FinancialEventService
 * whenever a financial event with site_id is posted. This includes:
 *   - Payroll costs (shifts at this site)
 *   - Fleet costs (fuel, maintenance for site-assigned vehicles)
 *   - Asset maintenance
 *   - Rent
 *   - Utilities
 *   - House ledger entries
 *   - Training (if allocated to site)
 *   - Mileage (if allocated to site)
 */
class SiteCostService
{
    /**
     * Total cost for a site across all event types in a date range.
     */
    public function totalCost(int $siteId, Carbon $from, Carbon $to): string
    {
        $total = FinCostAllocation::forSite($siteId)
            ->forPeriod($from, $to)
            ->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * Cost breakdown by event_type for a site in a date range.
     *
     * Returns an array of category → amount, plus a 'total' key.
     *
     * @return array{
     *     categories: array<string, array{amount: string, label: string, count: int}>,
     *     total: string,
     *     site_id: int,
     *     period_from: string,
     *     period_to: string,
     * }
     */
    public function breakdown(int $siteId, Carbon $from, Carbon $to): array
    {
        $rows = FinCostAllocation::forSite($siteId)
            ->forPeriod($from, $to)
            ->select('event_type', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as entry_count'))
            ->groupBy('event_type')
            ->orderByDesc('total_amount')
            ->get();

        $categories = [];
        $grandTotal = '0';

        foreach ($rows as $row) {
            $amount = number_format((float) $row->total_amount, 2, '.', '');
            $categories[$row->event_type] = [
                'amount' => $amount,
                'label' => $this->eventTypeLabel($row->event_type),
                'count' => (int) $row->entry_count,
            ];
            $grandTotal = bcadd($grandTotal, $amount, 2);
        }

        return [
            'categories' => $categories,
            'total' => $grandTotal,
            'site_id' => $siteId,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
        ];
    }

    /**
     * Monthly cost trend for a site over a range of months.
     *
     * @return array<string, string>  month (Y-m) → total cost
     */
    public function monthlyTrend(int $siteId, Carbon $from, Carbon $to): array
    {
        $rows = FinCostAllocation::forSite($siteId)
            ->forPeriod($from, $to)
            ->select(
                DB::raw("DATE_FORMAT(event_date, '%Y-%m') as month"),
                DB::raw('SUM(amount) as total_amount'),
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $trend = [];
        foreach ($rows as $row) {
            $trend[$row->month] = number_format((float) $row->total_amount, 2, '.', '');
        }

        return $trend;
    }

    /**
     * Compare costs across multiple sites for a period.
     *
     * @param  int[]  $siteIds
     * @return array<int, array{total: string, breakdown: array}>
     */
    public function compareSites(array $siteIds, Carbon $from, Carbon $to): array
    {
        $results = [];

        foreach ($siteIds as $siteId) {
            $results[$siteId] = $this->breakdown($siteId, $from, $to);
        }

        return $results;
    }

    /**
     * Human-readable label for event types.
     */
    private function eventTypeLabel(string $eventType): string
    {
        return match ($eventType) {
            'payroll_cost' => 'Payroll & Staffing',
            'employer_oncost' => 'Employer On-Costs',
            'fuel_expense' => 'Fleet Fuel',
            'fleet_maintenance_expense' => 'Fleet Maintenance',
            'asset_maintenance_expense' => 'Asset Maintenance',
            'site_rent_expense' => 'Rent / Lease',
            'site_utilities_expense' => 'Utilities',
            'site_utilities_true_up' => 'Utilities (True-up)',
            'house_ledger_expense' => 'House Operating',
            'house_ledger_income' => 'House Income',
            'expense_claim' => 'Staff Expenses',
            'training_cost' => 'Training',
            'mileage_reimbursement' => 'Travel / Mileage',
            'leave_provision' => 'Leave Provision',
            'client_ledger_expense' => 'Client Expenses',
            'client_ledger_income' => 'Client Income',
            default => str_replace('_', ' ', ucfirst($eventType)),
        };
    }
}
