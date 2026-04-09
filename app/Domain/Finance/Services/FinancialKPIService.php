<?php

namespace App\Domain\Finance\Services;

use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Financial KPI Service — organisation-wide financial metrics.
 *
 * Site KPIs: cost per resident, cost trends, highest/lowest cost sites.
 * Client KPIs: highest/lowest cost clients, cost vs funding gaps, outliers.
 *
 * Read-only. Composes from existing services — no duplicate calculations.
 */
class FinancialKPIService
{
    public function __construct(
        private readonly SiteCostService $siteCostService,
        private readonly CostPerResidentService $costPerResidentService,
        private readonly ClientCostService $clientCostService,
        private readonly StaffingCostService $staffingCostService,
    ) {}

    /**
     * All KPIs for an organisation.
     */
    public function getAll(?int $tenantId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = $to ?? Carbon::now();
        $from = $from ?? $to->copy()->subMonths(1)->startOfMonth();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'site_kpis' => $this->siteKPIs($tenantId, $from, $to),
            'client_kpis' => $this->clientKPIs($tenantId, $from, $to),
            'staffing_kpis' => $this->staffingKPIs($tenantId, $from, $to),
        ];
    }

    /**
     * Site-level KPIs.
     */
    public function siteKPIs(?int $tenantId, Carbon $from, Carbon $to): array
    {
        $sites = Site::query()->active()->whereIn('type', ['house', 'facility']);
        if ($tenantId) {
            $sites->forTenant($tenantId);
        }
        $sites = $sites->get();

        if ($sites->isEmpty()) {
            return [
                'avg_cost_per_resident' => '0.00',
                'highest_cost_site' => null,
                'lowest_cost_site' => null,
                'cost_trend_pct' => '0.0',
                'sites_ranked' => [],
            ];
        }

        // Build cost data per site
        $siteData = [];
        foreach ($sites as $site) {
            $cpr = $this->costPerResidentService->calculate($site->id, $from, $to);
            $total = $this->siteCostService->totalCost($site->id, $from, $to);

            $siteData[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'total_cost' => $total,
                'cost_per_resident' => $cpr['cost_per_resident'],
                'avg_residents' => $cpr['avg_residents'],
            ];
        }

        // Sort by cost per resident for ranking
        usort($siteData, fn ($a, $b) => bccomp($b['cost_per_resident'], $a['cost_per_resident'], 2));

        // Average cost per resident across all sites
        $totalCPR = '0';
        $sitesWithResidents = 0;
        foreach ($siteData as $sd) {
            if (bccomp($sd['avg_residents'], '0', 2) > 0) {
                $totalCPR = bcadd($totalCPR, $sd['cost_per_resident'], 2);
                $sitesWithResidents++;
            }
        }
        $avgCPR = $sitesWithResidents > 0
            ? bcdiv($totalCPR, (string) $sitesWithResidents, 2)
            : '0.00';

        // Cost trend: compare current period with previous period of same length
        $periodDays = max($from->diffInDays($to) + 1, 1);
        $prevFrom = $from->copy()->subDays($periodDays);
        $prevTo = $from->copy()->subDay();

        $currentTotal = '0';
        $previousTotal = '0';
        foreach ($sites as $site) {
            $currentTotal = bcadd($currentTotal, $this->siteCostService->totalCost($site->id, $from, $to), 2);
            $previousTotal = bcadd($previousTotal, $this->siteCostService->totalCost($site->id, $prevFrom, $prevTo), 2);
        }

        $trendPct = bccomp($previousTotal, '0', 2) > 0
            ? bcmul(bcdiv(bcsub($currentTotal, $previousTotal, 2), $previousTotal, 4), '100', 1)
            : '0.0';

        return [
            'avg_cost_per_resident' => $avgCPR,
            'total_cost' => $currentTotal,
            'previous_period_cost' => $previousTotal,
            'cost_trend_pct' => $trendPct,
            'highest_cost_site' => $siteData[0] ?? null,
            'lowest_cost_site' => end($siteData) ?: null,
            'sites_ranked' => $siteData,
        ];
    }

    /**
     * Client-level KPIs.
     */
    public function clientKPIs(?int $tenantId, Carbon $from, Carbon $to): array
    {
        $query = Client::query()->where(function ($q) {
            $q->where('status', 'active')->orWhereNull('status');
        });

        if ($tenantId) {
            $query->whereHas('site', fn ($q) => $q->forTenant($tenantId));
        }

        $clients = $query->limit(100)->get(); // Cap for performance

        if ($clients->isEmpty()) {
            return [
                'highest_cost_client' => null,
                'lowest_cost_client' => null,
                'avg_client_cost' => '0.00',
                'underfunded_count' => 0,
                'top_outliers' => [],
            ];
        }

        $clientData = [];
        $periodDays = max($from->diffInDays($to) + 1, 1);
        $periodWeeks = bcdiv((string) $periodDays, '7', 2);

        foreach ($clients as $client) {
            $cost = $this->clientCostService->calculate($client->id, $from, $to);
            $funding = $this->getClientFunding($client->id, $from, $to);

            $gap = bcsub($cost['total_cost'], $funding, 2);
            $weeklyGap = bccomp($periodWeeks, '0', 2) > 0
                ? bcdiv($gap, $periodWeeks, 2)
                : $gap;

            $clientData[] = [
                'client_id' => $client->id,
                'client_name' => $client->full_name,
                'total_cost' => $cost['total_cost'],
                'total_funding' => $funding,
                'gap' => $gap,
                'weekly_gap' => $weeklyGap,
                'is_underfunded' => bccomp($gap, '0', 2) > 0,
            ];
        }

        // Sort by total cost descending
        usort($clientData, fn ($a, $b) => bccomp($b['total_cost'], $a['total_cost'], 2));

        $underfunded = array_filter($clientData, fn ($c) => $c['is_underfunded']);

        // Average client cost
        $totalCost = '0';
        foreach ($clientData as $cd) {
            $totalCost = bcadd($totalCost, $cd['total_cost'], 2);
        }
        $avgCost = count($clientData) > 0
            ? bcdiv($totalCost, (string) count($clientData), 2)
            : '0.00';

        return [
            'client_count' => count($clientData),
            'avg_client_cost' => $avgCost,
            'highest_cost_client' => $clientData[0] ?? null,
            'lowest_cost_client' => end($clientData) ?: null,
            'underfunded_count' => count($underfunded),
            'top_outliers' => array_slice($clientData, 0, 10),
        ];
    }

    /**
     * Staffing KPIs: wages, on-costs, total staffing, on-cost percentage.
     */
    public function staffingKPIs(?int $tenantId, Carbon $from, Carbon $to): array
    {
        $sites = Site::query()->active()->whereIn('type', ['house', 'facility']);
        if ($tenantId) {
            $sites->forTenant($tenantId);
        }
        $siteIds = $sites->pluck('id')->toArray();

        if (empty($siteIds)) {
            return [
                'total_wages' => '0.00',
                'total_employer_oncost' => '0.00',
                'total_staffing_cost' => '0.00',
                'oncost_pct_of_wages' => '0.0',
                'staffing_pct_of_total_cost' => '0.0',
                'per_site' => [],
            ];
        }

        $orgStaffing = $this->staffingCostService->forOrganisation($siteIds, $from, $to);
        $perSite = $this->staffingCostService->perSiteComparison($siteIds, $from, $to);

        // Total org cost for staffing-as-%-of-total calculation
        $orgTotalCost = '0';
        foreach ($siteIds as $siteId) {
            $orgTotalCost = bcadd($orgTotalCost, $this->siteCostService->totalCost($siteId, $from, $to), 2);
        }

        $staffingPctOfTotal = bccomp($orgTotalCost, '0', 2) > 0
            ? bcmul(bcdiv($orgStaffing['total'], $orgTotalCost, 4), '100', 1)
            : '0.0';

        // Enrich per-site data with site names
        $siteNames = Site::whereIn('id', $siteIds)->pluck('name', 'id');
        $perSiteEnriched = [];
        foreach ($perSite as $siteId => $staffing) {
            $perSiteEnriched[] = array_merge($staffing, [
                'site_id' => $siteId,
                'site_name' => $siteNames[$siteId] ?? "Site #{$siteId}",
            ]);
        }

        // Sort by total staffing cost descending
        usort($perSiteEnriched, fn ($a, $b) => bccomp($b['total'], $a['total'], 2));

        return [
            'total_wages' => $orgStaffing['wages'],
            'total_employer_oncost' => $orgStaffing['employer_oncost'],
            'total_staffing_cost' => $orgStaffing['total'],
            'oncost_pct_of_wages' => $orgStaffing['oncost_pct'],
            'staffing_pct_of_total_cost' => $staffingPctOfTotal,
            'per_site' => $perSiteEnriched,
        ];
    }

    private function getClientFunding(int $clientId, Carbon $from, Carbon $to): string
    {
        $agreements = ServiceAgreement::where('client_id', $clientId)
            ->where('starts_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $from);
            })
            ->get();

        $periodAllocation = '0';
        foreach ($agreements as $agreement) {
            $agrStart = $agreement->starts_at ?? $from;
            $agrEnd = $agreement->ends_at ?? $to;
            $agrDays = max($agrStart->diffInDays($agrEnd) + 1, 1);

            $overlapStart = $agrStart->gt($from) ? $agrStart : $from;
            $overlapEnd = $agrEnd->lt($to) ? $agrEnd : $to;
            $overlapDays = max($overlapStart->diffInDays($overlapEnd) + 1, 0);

            if ($overlapDays > 0 && $agrDays > 0) {
                $fraction = bcdiv((string) $overlapDays, (string) $agrDays, 6);
                $allocated = bcmul((string) $agreement->total_budget, $fraction, 2);
                $periodAllocation = bcadd($periodAllocation, $allocated, 2);
            }
        }

        return $periodAllocation;
    }
}
