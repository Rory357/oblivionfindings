<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\FinancialInsightsService;
use App\Domain\Finance\Services\FinancialKPIService;
use App\Domain\Finance\Services\SiteFinancialDashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ExecutiveFinancialDashboardController extends Controller
{
    public function __construct(
        private readonly FinancialKPIService $kpiService,
        private readonly FinancialInsightsService $insightsService,
        private readonly SiteFinancialDashboardService $siteDashboardService,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->organization_id ?? null;
        $to = $request->filled('to') ? Carbon::parse($request->query('to')) : Carbon::now();
        $from = $request->filled('from') ? Carbon::parse($request->query('from')) : $to->copy()->subMonth()->startOfMonth();

        $kpis = $this->kpiService->getAll($tenantId, $from, $to);
        $insights = $this->insightsService->generate($tenantId);
        $siteSummaries = $this->siteDashboardService->getSiteSummaries($tenantId, $from, $to);

        return Inertia::render('finance/executive-dashboard/Index', [
            'kpis' => $kpis,
            'insights' => array_slice($insights, 0, 8),
            'siteSummaries' => $siteSummaries,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }
}
