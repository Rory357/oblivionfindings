<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\SiteBudgetLine;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Forecasts future costs at site and category level.
 *
 * Methods:
 *   - Fixed costs (rent): projected from current configuration
 *   - Variable costs (utilities, payroll, maintenance): 3-month rolling average with optional growth factor
 *   - Category-specific logic where different forecast models are appropriate
 *
 * Compares forecast against budget to detect projected overruns.
 *
 * Read-only. No financial mutations.
 */
class FinancialForecastService
{
    private const DEFAULT_LOOKBACK_MONTHS = 3;

    private const DEFAULT_FORECAST_MONTHS = 6;

    public function __construct(
        private readonly SiteCostService $siteCostService,
    ) {}

    /**
     * Forecast for a single site: next N months by category.
     *
     * @return array{
     *     site_id: int,
     *     site_name: string,
     *     forecast_months: int,
     *     lookback_months: int,
     *     growth_factor: string,
     *     monthly_forecasts: array<string, array>,
     *     category_totals: array<string, string>,
     *     grand_total: string,
     *     budget_comparison: array|null,
     * }
     */
    public function siteForecast(
        int $siteId,
        int $forecastMonths = self::DEFAULT_FORECAST_MONTHS,
        int $lookbackMonths = self::DEFAULT_LOOKBACK_MONTHS,
        float $growthFactor = 0.0,
    ): array {
        $site = Site::findOrFail($siteId);
        $now = Carbon::now();

        // Historical data: last N months of actuals by category
        $historyEnd = $now->copy()->subMonth()->endOfMonth();
        $historyStart = $historyEnd->copy()->subMonths($lookbackMonths - 1)->startOfMonth();
        $monthlyHistory = $this->getMonthlyHistoryByCategory($siteId, $historyStart, $historyEnd);

        // Category averages from history
        $categoryAverages = $this->calculateCategoryAverages($monthlyHistory, $lookbackMonths);

        // Check for fixed costs (rent) from site config
        $monthlyRent = $this->getMonthlyRent($site);

        // Build forecast
        $monthlyForecasts = [];
        $categoryTotals = [];
        $grandTotal = '0';
        $growthMultiplier = bcadd('1', (string) $growthFactor, 4);

        for ($i = 0; $i < $forecastMonths; $i++) {
            $forecastMonth = $now->copy()->addMonths($i)->format('Y-m');
            $monthData = [];
            $monthTotal = '0';

            // Compounding growth: apply growth factor per month from now
            $monthGrowth = '1';
            if ($growthFactor > 0 && $i > 0) {
                $monthGrowth = bcpow($growthMultiplier, (string) $i, 4);
            }

            foreach (SiteBudgetLine::CATEGORIES as $category => $label) {
                $projected = $this->forecastCategory($category, $categoryAverages, $monthlyRent, $monthGrowth);

                $monthData[$category] = [
                    'label' => $label,
                    'projected' => $projected,
                    'method' => $this->forecastMethod($category, $monthlyRent),
                ];

                $monthTotal = bcadd($monthTotal, $projected, 2);

                if (! isset($categoryTotals[$category])) {
                    $categoryTotals[$category] = '0';
                }
                $categoryTotals[$category] = bcadd($categoryTotals[$category], $projected, 2);
            }

            $monthlyForecasts[$forecastMonth] = [
                'categories' => $monthData,
                'total' => $monthTotal,
            ];

            $grandTotal = bcadd($grandTotal, $monthTotal, 2);
        }

        // Compare forecast vs budget if budgets exist
        $budgetComparison = $this->compareForecastToBudget($siteId, $monthlyForecasts);

        return [
            'site_id' => $siteId,
            'site_name' => $site->name,
            'forecast_months' => $forecastMonths,
            'lookback_months' => $lookbackMonths,
            'growth_factor' => number_format($growthFactor, 4, '.', ''),
            'monthly_forecasts' => $monthlyForecasts,
            'category_totals' => $categoryTotals,
            'grand_total' => $grandTotal,
            'budget_comparison' => $budgetComparison,
        ];
    }

    /**
     * Organisation-level forecast: all sites combined.
     */
    public function organisationForecast(
        array $siteIds,
        int $forecastMonths = self::DEFAULT_FORECAST_MONTHS,
        float $growthFactor = 0.0,
    ): array {
        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('type', ['house', 'facility'])
            ->get();

        $siteForecasts = [];
        $orgMonthlyTotals = [];
        $orgGrandTotal = '0';

        foreach ($sites as $site) {
            $forecast = $this->siteForecast($site->id, $forecastMonths, growthFactor: $growthFactor);
            $siteForecasts[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'grand_total' => $forecast['grand_total'],
            ];

            foreach ($forecast['monthly_forecasts'] as $month => $data) {
                if (! isset($orgMonthlyTotals[$month])) {
                    $orgMonthlyTotals[$month] = '0';
                }
                $orgMonthlyTotals[$month] = bcadd($orgMonthlyTotals[$month], $data['total'], 2);
            }

            $orgGrandTotal = bcadd($orgGrandTotal, $forecast['grand_total'], 2);
        }

        usort($siteForecasts, fn ($a, $b) => bccomp($b['grand_total'], $a['grand_total'], 2));

        return [
            'forecast_months' => $forecastMonths,
            'growth_factor' => number_format($growthFactor, 4, '.', ''),
            'sites' => $siteForecasts,
            'monthly_totals' => $orgMonthlyTotals,
            'grand_total' => $orgGrandTotal,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Private: History + Averages */
    /* ------------------------------------------------------------------ */

    /**
     * Get monthly cost history by budget category for a site.
     *
     * @return array<string, array<string, string>> category → [month → amount]
     */
    private function getMonthlyHistoryByCategory(int $siteId, Carbon $from, Carbon $to): array
    {
        $rows = FinCostAllocation::forSite($siteId)
            ->forPeriod($from, $to)
            ->select(
                'event_type',
                DB::raw("DATE_FORMAT(event_date, '%Y-%m') as month"),
                DB::raw('SUM(amount) as total'),
            )
            ->groupBy('event_type', 'month')
            ->get();

        // Build reverse map: event_type → category
        $reverseMap = [];
        foreach (SiteBudgetLine::CATEGORY_EVENT_TYPES as $category => $eventTypes) {
            foreach ($eventTypes as $eventType) {
                $reverseMap[$eventType] = $category;
            }
        }

        $history = [];
        foreach ($rows as $row) {
            $category = $reverseMap[$row->event_type] ?? 'other';
            if (! isset($history[$category])) {
                $history[$category] = [];
            }
            if (! isset($history[$category][$row->month])) {
                $history[$category][$row->month] = '0';
            }
            $history[$category][$row->month] = bcadd(
                $history[$category][$row->month],
                (string) $row->total,
                2,
            );
        }

        return $history;
    }

    /**
     * Calculate average monthly cost per category from history.
     *
     * @return array<string, string> category → avg monthly amount
     */
    private function calculateCategoryAverages(array $monthlyHistory, int $lookbackMonths): array
    {
        $averages = [];
        $divisor = (string) max($lookbackMonths, 1);

        foreach ($monthlyHistory as $category => $months) {
            $total = '0';
            foreach ($months as $amount) {
                $total = bcadd($total, $amount, 2);
            }
            $averages[$category] = bcdiv($total, $divisor, 2);
        }

        return $averages;
    }

    /* ------------------------------------------------------------------ */
    /*  Private: Forecast Logic */
    /* ------------------------------------------------------------------ */

    /**
     * Forecast a single category for one month.
     */
    private function forecastCategory(string $category, array $categoryAverages, ?string $monthlyRent, string $monthGrowth): string
    {
        // Rent: use fixed amount from site config if available
        if ($category === 'rent' && $monthlyRent !== null && bccomp($monthlyRent, '0', 2) > 0) {
            return $monthlyRent; // Rent doesn't get growth factor — it's fixed
        }

        $average = $categoryAverages[$category] ?? '0';

        if (bccomp($average, '0', 2) <= 0) {
            return '0.00';
        }

        // Apply growth factor for variable costs
        return bcmul($average, $monthGrowth, 2);
    }

    private function forecastMethod(string $category, ?string $monthlyRent): string
    {
        if ($category === 'rent' && $monthlyRent !== null && bccomp($monthlyRent, '0', 2) > 0) {
            return 'fixed_from_lease';
        }

        return 'rolling_average';
    }

    /**
     * Get monthly rent from site configuration.
     */
    private function getMonthlyRent(Site $site): ?string
    {
        if (! $site->rent_amount || ! $site->rent_frequency) {
            return null;
        }

        $annual = match ($site->rent_frequency) {
            'weekly' => (float) $site->rent_amount * 52,
            'fortnightly' => (float) $site->rent_amount * 26,
            'monthly' => (float) $site->rent_amount * 12,
            'quarterly' => (float) $site->rent_amount * 4,
            'annually' => (float) $site->rent_amount,
            default => (float) $site->rent_amount * 12,
        };

        return bcdiv((string) $annual, '12', 2);
    }

    /**
     * Compare forecast months against budget lines (if budgets exist).
     */
    private function compareForecastToBudget(int $siteId, array $monthlyForecasts): ?array
    {
        $months = array_keys($monthlyForecasts);
        if (empty($months)) {
            return null;
        }

        $budgetLines = SiteBudgetLine::forSite($siteId)
            ->forPeriodRange(min($months), max($months))
            ->get()
            ->groupBy(fn ($line) => "{$line->period}:{$line->category}");

        if ($budgetLines->isEmpty()) {
            return null;
        }

        $overruns = [];

        foreach ($monthlyForecasts as $month => $data) {
            foreach ($data['categories'] as $category => $catData) {
                $key = "{$month}:{$category}";
                $budgetLine = $budgetLines->get($key)?->first();

                if (! $budgetLine) {
                    continue;
                }

                $planned = (string) $budgetLine->planned_amount;
                $projected = $catData['projected'];
                $variance = bcsub($projected, $planned, 2);

                if (bccomp($variance, '0', 2) > 0) {
                    $overruns[] = [
                        'month' => $month,
                        'category' => $category,
                        'label' => $catData['label'],
                        'planned' => $planned,
                        'projected' => $projected,
                        'variance' => $variance,
                    ];
                }
            }
        }

        return empty($overruns) ? null : $overruns;
    }
}
