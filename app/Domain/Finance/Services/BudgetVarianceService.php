<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\SiteBudgetLine;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calculates planned vs actual variance at site, category, and organisation level.
 *
 * Planned: from site_budget_lines (set by managers).
 * Actual: calculated dynamically from fin_cost_allocations (posted GL data).
 * Variance: derived at query time, never stored.
 *
 * Read-only. No financial mutations.
 */
class BudgetVarianceService
{
    /**
     * Variance for a single site for a period range.
     *
     * @return array{
     *     site_id: int,
     *     site_name: string,
     *     period_from: string,
     *     period_to: string,
     *     lines: array<int, array>,
     *     totals: array{planned: string, actual: string, variance: string, variance_pct: string},
     * }
     */
    public function siteVariance(int $siteId, string $fromPeriod, string $toPeriod): array
    {
        $site = Site::findOrFail($siteId);

        $budgetLines = SiteBudgetLine::forSite($siteId)
            ->forPeriodRange($fromPeriod, $toPeriod)
            ->get()
            ->groupBy('category');

        // Get actuals from cost allocations grouped by category
        $actuals = $this->getActualsByCategory($siteId, $fromPeriod, $toPeriod);

        // Merge: all budgeted categories + any actuals in categories without budget
        $allCategories = collect(SiteBudgetLine::CATEGORIES)->keys()
            ->merge($actuals->keys())
            ->unique();

        $lines = [];
        $totalPlanned = '0';
        $totalActual = '0';

        foreach ($allCategories as $category) {
            $planned = '0';
            if ($budgetLines->has($category)) {
                foreach ($budgetLines[$category] as $line) {
                    $planned = bcadd($planned, (string) $line->planned_amount, 2);
                }
            }

            $actual = $actuals->get($category, '0.00');
            $variance = bcsub($actual, $planned, 2);
            $variancePct = bccomp($planned, '0', 2) !== 0
                ? bcmul(bcdiv($variance, $planned, 4), '100', 1)
                : (bccomp($actual, '0', 2) > 0 ? '100.0' : '0.0');

            $totalPlanned = bcadd($totalPlanned, $planned, 2);
            $totalActual = bcadd($totalActual, $actual, 2);

            // Only include categories with budget or actual
            if (bccomp($planned, '0', 2) === 0 && bccomp($actual, '0', 2) === 0) {
                continue;
            }

            $lines[] = [
                'category' => $category,
                'label' => SiteBudgetLine::CATEGORIES[$category] ?? ucfirst(str_replace('_', ' ', $category)),
                'planned' => $planned,
                'actual' => $actual,
                'variance' => $variance,
                'variance_pct' => $variancePct,
                'status' => $this->varianceStatus($variance, $planned),
            ];
        }

        // Sort: over-budget items first
        usort($lines, fn ($a, $b) => bccomp($b['variance'], $a['variance'], 2));

        $totalVariance = bcsub($totalActual, $totalPlanned, 2);
        $totalVariancePct = bccomp($totalPlanned, '0', 2) !== 0
            ? bcmul(bcdiv($totalVariance, $totalPlanned, 4), '100', 1)
            : '0.0';

        return [
            'site_id' => $siteId,
            'site_name' => $site->name,
            'period_from' => $fromPeriod,
            'period_to' => $toPeriod,
            'lines' => $lines,
            'totals' => [
                'planned' => $totalPlanned,
                'actual' => $totalActual,
                'variance' => $totalVariance,
                'variance_pct' => $totalVariancePct,
                'status' => $this->varianceStatus($totalVariance, $totalPlanned),
            ],
        ];
    }

    /**
     * Variance across all sites for a period (organisation level).
     */
    public function organisationVariance(?array $siteIds, string $fromPeriod, string $toPeriod): array
    {
        $sites = Site::query()
            ->when($siteIds !== null, fn ($query) => $query->whereIn('id', $siteIds))
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('type', ['house', 'facility'])
            ->get();

        $siteResults = [];
        $orgTotalPlanned = '0';
        $orgTotalActual = '0';

        foreach ($sites as $site) {
            $result = $this->siteVariance($site->id, $fromPeriod, $toPeriod);
            $siteResults[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'planned' => $result['totals']['planned'],
                'actual' => $result['totals']['actual'],
                'variance' => $result['totals']['variance'],
                'variance_pct' => $result['totals']['variance_pct'],
                'status' => $result['totals']['status'],
            ];
            $orgTotalPlanned = bcadd($orgTotalPlanned, $result['totals']['planned'], 2);
            $orgTotalActual = bcadd($orgTotalActual, $result['totals']['actual'], 2);
        }

        usort($siteResults, fn ($a, $b) => bccomp($b['variance'], $a['variance'], 2));

        $orgVariance = bcsub($orgTotalActual, $orgTotalPlanned, 2);
        $orgVariancePct = bccomp($orgTotalPlanned, '0', 2) !== 0
            ? bcmul(bcdiv($orgVariance, $orgTotalPlanned, 4), '100', 1)
            : '0.0';

        return [
            'period_from' => $fromPeriod,
            'period_to' => $toPeriod,
            'sites' => $siteResults,
            'totals' => [
                'planned' => $orgTotalPlanned,
                'actual' => $orgTotalActual,
                'variance' => $orgVariance,
                'variance_pct' => $orgVariancePct,
                'status' => $this->varianceStatus($orgVariance, $orgTotalPlanned),
            ],
        ];
    }

    /**
     * Monthly variance trend for a site (each month as a row).
     */
    public function monthlyTrend(int $siteId, string $fromPeriod, string $toPeriod): array
    {
        $months = [];
        $current = Carbon::parse($fromPeriod.'-01');
        $end = Carbon::parse($toPeriod.'-01');

        while ($current->lte($end)) {
            $period = $current->format('Y-m');
            $result = $this->siteVariance($siteId, $period, $period);

            $months[$period] = $result['totals'];
            $current->addMonth();
        }

        return $months;
    }

    /* ------------------------------------------------------------------ */
    /*  Private */
    /* ------------------------------------------------------------------ */

    /**
     * Get actual costs grouped by budget category for a site + period range.
     *
     * Maps event_types to budget categories using SiteBudgetLine::CATEGORY_EVENT_TYPES.
     *
     * @return Collection<string, string> category → total amount
     */
    private function getActualsByCategory(int $siteId, string $fromPeriod, string $toPeriod): Collection
    {
        $from = Carbon::parse($fromPeriod.'-01')->startOfMonth();
        $to = Carbon::parse($toPeriod.'-01')->endOfMonth();

        $rawActuals = FinCostAllocation::forSite($siteId)
            ->forPeriod($from, $to)
            ->select('event_type', DB::raw('SUM(amount) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        // Map event_types to budget categories
        $categoryTotals = collect();

        // Build reverse map: event_type → category
        $reverseMap = [];
        foreach (SiteBudgetLine::CATEGORY_EVENT_TYPES as $category => $eventTypes) {
            foreach ($eventTypes as $eventType) {
                $reverseMap[$eventType] = $category;
            }
        }

        foreach ($rawActuals as $eventType => $amount) {
            $category = $reverseMap[$eventType] ?? 'other';
            $current = $categoryTotals->get($category, '0.00');
            $categoryTotals[$category] = bcadd((string) $current, (string) $amount, 2);
        }

        return $categoryTotals;
    }

    private function varianceStatus(string $variance, string $planned): string
    {
        if (bccomp($planned, '0', 2) === 0) {
            return bccomp($variance, '0', 2) > 0 ? 'over_budget' : 'on_track';
        }

        $pct = (float) bcmul(bcdiv($variance, $planned, 4), '100', 1);

        if ($pct >= 10) {
            return 'over_budget';
        }
        if ($pct >= 0) {
            return 'approaching';
        }

        return 'under_budget';
    }
}
