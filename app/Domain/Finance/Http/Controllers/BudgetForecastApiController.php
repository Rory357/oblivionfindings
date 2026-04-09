<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\BudgetVarianceService;
use App\Domain\Finance\Services\FinancialForecastService;
use App\Domain\Finance\Models\SiteBudgetLine;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * JSON API endpoints for budgets, variance analysis, and forecasting.
 *
 * Read-only endpoints compose from BudgetVarianceService and FinancialForecastService.
 */
class BudgetForecastApiController extends Controller
{
    public function __construct(
        private readonly BudgetVarianceService $varianceService,
        private readonly FinancialForecastService $forecastService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Budget Endpoints                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * GET /finance/api/budgets
     *
     * Organisation-level budget overview for a period.
     */
    public function budgetOverview(Request $request): JsonResponse
    {
        $tenantId = $request->user()->organization_id ?? null;
        [$fromPeriod, $toPeriod] = $this->parsePeriodRange($request);

        $data = $this->varianceService->organisationVariance($tenantId, $fromPeriod, $toPeriod);

        return response()->json($data);
    }

    /**
     * GET /finance/api/sites/{site}/budget
     *
     * Site-level budget with planned amounts.
     */
    public function siteBudget(Request $request, int $site): JsonResponse
    {
        [$fromPeriod, $toPeriod] = $this->parsePeriodRange($request);

        $lines = SiteBudgetLine::forSite($site)
            ->forPeriodRange($fromPeriod, $toPeriod)
            ->orderBy('period')
            ->orderBy('category')
            ->get()
            ->map(fn ($line) => [
                'id' => $line->id,
                'period' => $line->period,
                'category' => $line->category,
                'label' => $line->getCategoryLabel(),
                'planned_amount' => $line->planned_amount,
                'notes' => $line->notes,
                'approved_at' => $line->approved_at?->toISOString(),
            ]);

        return response()->json([
            'site_id' => $site,
            'period_from' => $fromPeriod,
            'period_to' => $toPeriod,
            'lines' => $lines,
            'categories' => SiteBudgetLine::CATEGORIES,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Variance Endpoints                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * GET /finance/api/variance
     *
     * Organisation-level planned vs actual variance.
     */
    public function organisationVariance(Request $request): JsonResponse
    {
        $tenantId = $request->user()->organization_id ?? null;
        [$fromPeriod, $toPeriod] = $this->parsePeriodRange($request);

        $data = $this->varianceService->organisationVariance($tenantId, $fromPeriod, $toPeriod);

        return response()->json($data);
    }

    /**
     * GET /finance/api/sites/{site}/variance
     *
     * Site-level planned vs actual variance with category breakdown.
     */
    public function siteVariance(Request $request, int $site): JsonResponse
    {
        [$fromPeriod, $toPeriod] = $this->parsePeriodRange($request);

        $data = $this->varianceService->siteVariance($site, $fromPeriod, $toPeriod);

        return response()->json($data);
    }

    /**
     * GET /finance/api/sites/{site}/variance/trend
     *
     * Monthly variance trend for a site.
     */
    public function siteVarianceTrend(Request $request, int $site): JsonResponse
    {
        [$fromPeriod, $toPeriod] = $this->parsePeriodRange($request);

        $data = $this->varianceService->monthlyTrend($site, $fromPeriod, $toPeriod);

        return response()->json([
            'site_id' => $site,
            'period_from' => $fromPeriod,
            'period_to' => $toPeriod,
            'months' => $data,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Forecast Endpoints                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * GET /finance/api/forecast
     *
     * Organisation-level cost forecast.
     */
    public function organisationForecast(Request $request): JsonResponse
    {
        $tenantId = $request->user()->organization_id ?? null;
        $months = min((int) $request->query('months', 6), 12);
        $growth = min((float) $request->query('growth', 0), 0.5);

        $data = $this->forecastService->organisationForecast($tenantId, $months, $growth);

        return response()->json($data);
    }

    /**
     * GET /finance/api/sites/{site}/forecast
     *
     * Site-level cost forecast with category breakdown and budget comparison.
     */
    public function siteForecast(Request $request, int $site): JsonResponse
    {
        $months = min((int) $request->query('months', 6), 12);
        $growth = min((float) $request->query('growth', 0), 0.5);

        $data = $this->forecastService->siteForecast($site, $months, growthFactor: $growth);

        return response()->json($data);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Parse period range from query params (YYYY-MM format).
     *
     * @return array{string, string}
     */
    private function parsePeriodRange(Request $request): array
    {
        $now = Carbon::now();

        $toPeriod = $request->filled('to')
            ? $request->query('to')
            : $now->subMonth()->format('Y-m');

        $fromPeriod = $request->filled('from')
            ? $request->query('from')
            : Carbon::parse($toPeriod . '-01')->format('Y-m'); // Same month if not specified

        return [$fromPeriod, $toPeriod];
    }
}
