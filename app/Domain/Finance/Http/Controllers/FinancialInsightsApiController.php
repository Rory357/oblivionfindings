<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\ClientFinancialSummaryService;
use App\Domain\Finance\Services\ClientLedgerService;
use App\Domain\Finance\Services\FinancialInsightsService;
use App\Domain\Finance\Services\FinancialKPIService;
use App\Domain\Finance\Services\SiteFinancialDashboardService;
use App\Http\Controllers\Controller;
use App\Services\UserSiteAccessService;
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
        private readonly UserSiteAccessService $siteAccess,
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
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->siteDashboardService->getDashboard($site, $from, $to);

        return response()->json($data);
    }

    /**
     * GET /finance/api/sites/overview
     *
     * Multi-site cost comparison.
     */
    public function sitesOverview(Request $request): JsonResponse
    {
        $tenantId = $request->user()->organization_id ?? null;
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->siteDashboardService->getSiteSummaries($tenantId, $from, $to);

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
        $this->siteAccess->assertCanAccessClientId(
            $request->user(),
            $client,
            ['reports.viewAny'],
        );
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->clientSummaryService->getSummary($client, $from, $to);

        return response()->json($data);
    }

    /**
     * GET /finance/api/clients/{client}/ledger
     *
     * Chronological ledger with optional running balance.
     */
    public function clientLedger(Request $request, int $client): JsonResponse
    {
        $this->siteAccess->assertCanAccessClientId(
            $request->user(),
            $client,
            ['reports.viewAny'],
        );
        [$from, $to] = $this->parsePeriod($request);
        $withBalance = $request->boolean('balance', false);

        $data = $this->ledgerService->getLedger($client, $from, $to, withRunningBalance: $withBalance);

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
        $tenantId = $request->user()->organization_id ?? null;
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->kpiService->getAll($tenantId, $from, $to);

        return response()->json($data);
    }

    /**
     * GET /finance/api/kpis/sites
     *
     * Site-level KPIs only.
     */
    public function siteKpis(Request $request): JsonResponse
    {
        $tenantId = $request->user()->organization_id ?? null;
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->kpiService->siteKPIs($tenantId, $from, $to);

        return response()->json($data);
    }

    /**
     * GET /finance/api/kpis/clients
     *
     * Client-level KPIs only.
     */
    public function clientKpis(Request $request): JsonResponse
    {
        $tenantId = $request->user()->organization_id ?? null;
        [$from, $to] = $this->parsePeriod($request);

        $data = $this->kpiService->clientKPIs($tenantId, $from, $to);

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
        $tenantId = $request->user()->organization_id ?? null;

        $data = $this->insightsService->generate($tenantId);

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
