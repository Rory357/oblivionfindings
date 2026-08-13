<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\ClientFinancialSummaryService;
use App\Domain\Finance\Services\ClientLedgerService;
use App\Domain\Finance\Services\FinancialInsightsService;
use App\Domain\Finance\Services\FinancialInsightsScopeResolver;
use App\Domain\Finance\Services\FinancialKPIService;
use App\Domain\Finance\Services\SiteFinancialDashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * JSON API endpoints for financial dashboards, KPIs, and insights.
 *
 * All endpoints are read-only and compose from existing services.
 * No financial mutations occur through these endpoints.
 */
class FinancialInsightsApiController extends Controller
{
    public function __construct(
        private readonly SiteFinancialDashboardService $siteDashboardService,
        private readonly ClientFinancialSummaryService $clientSummaryService,
        private readonly ClientLedgerService $ledgerService,
        private readonly FinancialKPIService $kpiService,
        private readonly FinancialInsightsService $insightsService,
        private readonly FinancialInsightsScopeResolver $scopeResolver,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Site Endpoints */
    /* ------------------------------------------------------------------ */

    /**
     * GET /finance/api/sites/{site}/financial-summary
     *
     * Full financial dashboard data for a single site.
     */
    public function siteFinancialSummary(Request $request, int $site): JsonResponse
    {
        $scope = $this->scopeResolver->resolveSite($request->user(), $site);
        abort_if($scope->isDenied(), 404);
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->siteDashboardService->getDashboard($scope->targetSiteId(), $from, $to);

        return response()->json($data);
    }

    /**
     * GET /finance/api/sites/overview
     *
     * Multi-site cost comparison.
     */
    public function sitesOverview(Request $request): JsonResponse
    {
        $scope = $this->scopeResolver->resolveAggregate($request->user());
        abort_if($scope->isDenied(), 403);
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->siteDashboardService->getSiteSummaries($scope->siteIds, $from, $to);

        return response()->json($data);
    }

    /* ------------------------------------------------------------------ */
    /*  Client Endpoints */
    /* ------------------------------------------------------------------ */

    /**
     * GET /finance/api/clients/{client}/financial-summary
     *
     * Combined ledger + cost summary for a client.
     */
    public function clientFinancialSummary(Request $request, int $client): JsonResponse
    {
        $scope = $this->scopeResolver->resolveClient($request->user(), $client);
        abort_if($scope->isDenied(), 404);
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->clientSummaryService->getSummary($scope->targetClientId(), $from, $to);

        return response()->json($data);
    }

    /**
     * GET /finance/api/clients/{client}/ledger
     *
     * Chronological ledger with optional running balance.
     */
    public function clientLedger(Request $request, int $client): JsonResponse
    {
        $scope = $this->scopeResolver->resolveClient($request->user(), $client);
        abort_if($scope->isDenied(), 404);
        [$from, $to] = $this->parsePeriod($request);
        $withBalance = $request->boolean('balance', false);

        $data = $this->ledgerService->getLedger(
            $scope->targetClientId(),
            $from,
            $to,
            withRunningBalance: $withBalance,
        );

        return response()->json($data);
    }

    /* ------------------------------------------------------------------ */
    /*  KPI Endpoints */
    /* ------------------------------------------------------------------ */

    /**
     * GET /finance/api/kpis
     *
     * Organisation-wide financial KPIs.
     */
    public function kpis(Request $request): JsonResponse
    {
        $scope = $this->scopeResolver->resolveAggregate($request->user());
        abort_if($scope->isDenied(), 403);
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->kpiService->getAll($scope->siteIds, $scope->clientIds, $from, $to);

        return response()->json($data);
    }

    /**
     * GET /finance/api/kpis/sites
     *
     * Site-level KPIs only.
     */
    public function siteKpis(Request $request): JsonResponse
    {
        $scope = $this->scopeResolver->resolveAggregate($request->user());
        abort_if($scope->isDenied(), 403);
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->kpiService->siteKPIs($scope->siteIds, $from, $to);

        return response()->json($data);
    }

    /**
     * GET /finance/api/kpis/clients
     *
     * Client-level KPIs only.
     */
    public function clientKpis(Request $request): JsonResponse
    {
        $scope = $this->scopeResolver->resolveAggregate($request->user());
        abort_if($scope->isDenied(), 403);
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->kpiService->clientKPIs($scope->clientIds, $from, $to);

        return response()->json($data);
    }

    /* ------------------------------------------------------------------ */
    /*  Insights Endpoint */
    /* ------------------------------------------------------------------ */

    /**
     * GET /finance/api/insights
     *
     * Actionable financial insights and alerts.
     */
    public function insights(Request $request): JsonResponse
    {
        $scope = $this->scopeResolver->resolveAggregate($request->user());
        abort_if($scope->isDenied(), 403);

        $data = $this->insightsService->generate($scope->siteIds, $scope->clientIds);

        return response()->json([
            'insights' => $data,
            'generated_at' => now()->toISOString(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Parse from/to query params with sensible defaults (last full month).
     *
     * @return array{Carbon, Carbon}
     */
    private function parsePeriod(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))
            : Carbon::now();

        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))
            : $to->copy()->subMonth()->startOfMonth();

        return [$from, $to];
    }
}
