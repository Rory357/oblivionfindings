<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\BudgetVarianceService;
use App\Domain\Finance\Services\FinancialInsightsScopeResolver;
use App\Domain\Finance\Services\FinancialInsightsService;
use App\Domain\Finance\Services\SiteFinancialDashboardService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class SiteFinancialDashboardController extends Controller
{
    public function __construct(
        private readonly SiteFinancialDashboardService $dashboardService,
        private readonly BudgetVarianceService $varianceService,
        private readonly FinancialInsightsService $insightsService,
        private readonly FinancialInsightsScopeResolver $scopeResolver,
    ) {}

    public function show(Request $request, string $site)
    {
        abort_unless(ctype_digit($site) && (int) $site > 0, 404);
        $scope = $this->scopeResolver->resolveSite($request->user(), (int) $site);
        abort_if($scope->isDenied(), 404);
        $site = Site::query()->findOrFail($scope->targetSiteId());
        $to = $request->filled('to') ? Carbon::parse($request->query('to')) : Carbon::now();
        $from = $request->filled('from') ? Carbon::parse($request->query('from')) : $to->copy()->subMonth()->startOfMonth();
        $period = $from->format('Y-m');

        $dashboard = $this->dashboardService->getDashboard($site->id, $from, $to);
        $variance = $this->varianceService->siteVariance($site->id, $period, $to->format('Y-m'));
        $insights = $this->insightsService->generate([$site->id], []);

        // Filter insights to this site
        $siteInsights = array_values(array_filter($insights, fn ($i) => ($i['data']['site_id'] ?? null) === $site->id));

        return Inertia::render('finance/site-dashboard/Show', [
            'site' => $site->only('id', 'name', 'type'),
            'dashboard' => $dashboard,
            'variance' => $variance,
            'insights' => array_slice($siteInsights, 0, 5),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }
}
