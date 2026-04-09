<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinCostAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Unified staffing cost calculations across the system.
 *
 * Provides a consistent "staffing bundle" that separates:
 *   - wages (payroll_cost)
 *   - employer on-costs (employer_oncost: KiwiSaver employer + ACC employer levy)
 *   - total staffing cost
 *   - employer on-cost percentage
 *
 * All data sourced from fin_cost_allocations — no duplicate calculations.
 * Read-only. No financial mutations.
 */
class StaffingCostService
{
    /**
     * Staffing cost bundle for a site.
     *
     * @return array{wages: string, employer_oncost: string, total: string, oncost_pct: string}
     */
    public function forSite(int $siteId, Carbon $from, Carbon $to): array
    {
        $totals = FinCostAllocation::forSite($siteId)
            ->forPeriod($from, $to)
            ->whereIn('event_type', ['payroll_cost', 'employer_oncost'])
            ->select('event_type', DB::raw('SUM(amount) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        return $this->buildBundle($totals);
    }

    /**
     * Staffing cost bundle for a client (direct attribution only).
     *
     * @return array{wages: string, employer_oncost: string, total: string, oncost_pct: string}
     */
    public function forClient(int $clientId, Carbon $from, Carbon $to): array
    {
        $totals = FinCostAllocation::forClient($clientId)
            ->forPeriod($from, $to)
            ->whereIn('event_type', ['payroll_cost', 'employer_oncost'])
            ->select('event_type', DB::raw('SUM(amount) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        return $this->buildBundle($totals);
    }

    /**
     * Staffing cost bundle for the entire organisation.
     *
     * @return array{wages: string, employer_oncost: string, total: string, oncost_pct: string}
     */
    public function forOrganisation(array $siteIds, Carbon $from, Carbon $to): array
    {
        $totals = FinCostAllocation::query()
            ->whereIn('site_id', $siteIds)
            ->forPeriod($from, $to)
            ->whereIn('event_type', ['payroll_cost', 'employer_oncost'])
            ->select('event_type', DB::raw('SUM(amount) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        return $this->buildBundle($totals);
    }

    /**
     * Staffing cost per site — for comparison tables.
     *
     * @param  int[]  $siteIds
     * @return array<int, array{wages: string, employer_oncost: string, total: string, oncost_pct: string}>
     */
    public function perSiteComparison(array $siteIds, Carbon $from, Carbon $to): array
    {
        $rows = FinCostAllocation::query()
            ->whereIn('site_id', $siteIds)
            ->forPeriod($from, $to)
            ->whereIn('event_type', ['payroll_cost', 'employer_oncost'])
            ->select('site_id', 'event_type', DB::raw('SUM(amount) as total'))
            ->groupBy('site_id', 'event_type')
            ->get();

        $bySite = [];
        foreach ($rows as $row) {
            $bySite[$row->site_id][$row->event_type] = (string) $row->total;
        }

        $results = [];
        foreach ($siteIds as $siteId) {
            $results[$siteId] = $this->buildBundle(collect($bySite[$siteId] ?? []));
        }

        return $results;
    }

    /**
     * Build the staffing bundle from event_type totals.
     */
    private function buildBundle($totals): array
    {
        $wages = number_format((float) ($totals['payroll_cost'] ?? 0), 2, '.', '');
        $oncost = number_format((float) ($totals['employer_oncost'] ?? 0), 2, '.', '');
        $total = bcadd($wages, $oncost, 2);

        // On-cost percentage of wages (not of total)
        $oncostPct = bccomp($wages, '0', 2) > 0
            ? bcmul(bcdiv($oncost, $wages, 4), '100', 1)
            : '0.0';

        return [
            'wages' => $wages,
            'employer_oncost' => $oncost,
            'total' => $total,
            'oncost_pct' => $oncostPct,
        ];
    }
}
