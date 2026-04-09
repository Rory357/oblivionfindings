<?php

namespace App\Domain\Finance\Services;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Composes data from SiteCostService, CostPerResidentService, and StaffingCostService
 * into a single dashboard-ready payload for a site.
 *
 * Read-only. No financial mutations.
 */
class SiteFinancialDashboardService
{
    public function __construct(
        private readonly SiteCostService $siteCostService,
        private readonly CostPerResidentService $costPerResidentService,
        private readonly StaffingCostService $staffingCostService,
    ) {}

    /**
     * Full dashboard data for a single site.
     */
    public function getDashboard(int $siteId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = $to ?? Carbon::now();
        $from = $from ?? $to->copy()->subMonths(1)->startOfMonth();
        $site = Site::findOrFail($siteId);

        $breakdown = $this->siteCostService->breakdown($siteId, $from, $to);
        $costPerResident = $this->costPerResidentService->calculate($siteId, $from, $to);
        $staffing = $this->staffingCostService->forSite($siteId, $from, $to);

        // Staffing as % of total cost
        $staffingPctOfTotal = bccomp($breakdown['total'], '0', 2) > 0
            ? bcmul(bcdiv($staffing['total'], $breakdown['total'], 4), '100', 1)
            : '0.0';

        // Trend: last 6 months
        $trendFrom = $to->copy()->subMonths(6)->startOfMonth();
        $trend = $this->siteCostService->monthlyTrend($siteId, $trendFrom, $to);
        $costPerResidentTrend = $this->costPerResidentService->monthlyTrend($siteId, $trendFrom, $to);

        $chartCategories = $this->buildChartBreakdown($breakdown['categories']);

        return [
            'site_id' => $siteId,
            'site_name' => $site->name,
            'site_type' => $site->type,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'hero_cards' => [
                'total_cost' => $breakdown['total'],
                'cost_per_resident' => $costPerResident['cost_per_resident'],
                'avg_residents' => $costPerResident['avg_residents'],
                'occupancy_method' => $costPerResident['occupancy_method'],
            ],
            'staffing' => [
                'wages' => $staffing['wages'],
                'employer_oncost' => $staffing['employer_oncost'],
                'total_staffing_cost' => $staffing['total'],
                'oncost_pct_of_wages' => $staffing['oncost_pct'],
                'staffing_pct_of_total' => $staffingPctOfTotal,
            ],
            'breakdown' => [
                'categories' => $breakdown['categories'],
                'chart' => $chartCategories,
            ],
            'trend' => [
                'monthly_cost' => $trend,
                'cost_per_resident' => $costPerResidentTrend,
            ],
            'occupancy' => [
                'resident_days' => $costPerResident['total_resident_days'],
                'period_days' => $costPerResident['period_days'],
                'avg_residents' => $costPerResident['avg_residents'],
                'method' => $costPerResident['occupancy_method'],
            ],
        ];
    }

    /**
     * Compact summary for multi-site overview.
     */
    public function getSiteSummaries(?int $tenantId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = $to ?? Carbon::now();
        $from = $from ?? $to->copy()->subMonths(1)->startOfMonth();

        $query = Site::query()->active()->whereIn('type', ['house', 'facility']);
        if ($tenantId) {
            $query->forTenant($tenantId);
        }

        $sites = $query->get();
        $siteIds = $sites->pluck('id')->toArray();

        // Batch staffing costs for all sites in one query
        $staffingBySite = $this->staffingCostService->perSiteComparison($siteIds, $from, $to);

        $summaries = [];

        foreach ($sites as $site) {
            $breakdown = $this->siteCostService->breakdown($site->id, $from, $to);
            $cpr = $this->costPerResidentService->calculate($site->id, $from, $to);
            $staffing = $staffingBySite[$site->id] ?? ['wages' => '0.00', 'employer_oncost' => '0.00', 'total' => '0.00', 'oncost_pct' => '0.0'];

            $summaries[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_type' => $site->type,
                'total_cost' => $breakdown['total'],
                'cost_per_resident' => $cpr['cost_per_resident'],
                'avg_residents' => $cpr['avg_residents'],
                'staffing' => [
                    'wages' => $staffing['wages'],
                    'employer_oncost' => $staffing['employer_oncost'],
                    'total_staffing_cost' => $staffing['total'],
                    'oncost_pct_of_wages' => $staffing['oncost_pct'],
                ],
            ];
        }

        usort($summaries, fn ($a, $b) => bccomp($b['total_cost'], $a['total_cost'], 2));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'sites' => $summaries,
        ];
    }

    private function buildChartBreakdown(array $categories): array
    {
        $chart = [];
        foreach ($categories as $type => $data) {
            $chart[] = [
                'label' => $data['label'],
                'value' => (float) $data['amount'],
                'type' => $type,
            ];
        }

        usort($chart, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $chart;
    }
}
