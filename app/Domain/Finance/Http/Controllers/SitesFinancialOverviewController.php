<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\BudgetVarianceService;
use App\Domain\Finance\Services\SiteCostService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class SitesFinancialOverviewController extends Controller
{
    public function __construct(
        private readonly SiteCostService $siteCostService,
        private readonly BudgetVarianceService $varianceService,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : Carbon::now()->endOfDay();
        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : $to->copy()->startOfMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $user = $request->user();
        $tenantId = $user?->organization_id;
        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds($user);

        $sites = Site::query()
            ->active()
            ->forTenant($tenantId)
            ->whereIn('type', ['house', 'residential', 'facility'])
            ->when($accessibleSiteIds !== [], fn ($query) => $query->whereIn('id', $accessibleSiteIds))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'region', 'tenant_id']);

        $siteIds = $sites->pluck('id')->all();
        $comparison = $siteIds === []
            ? []
            : $this->siteCostService->compareSites($siteIds, $from, $to);

        $fromPeriod = $from->format('Y-m');
        $toPeriod = $to->format('Y-m');
        $rows = [];
        $totalSpend = '0.00';
        $sitesOverBudget = 0;
        $categoryKeys = [];

        foreach ($sites as $site) {
            $cost = $comparison[$site->id] ?? [
                'categories' => [],
                'total' => '0.00',
                'site_id' => $site->id,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
            ];
            $variance = $this->varianceService->siteVariance($site->id, $fromPeriod, $toPeriod);
            $trend = $this->siteCostService->monthlyTrend($site->id, $from->copy()->startOfMonth(), $to);

            $categories = collect($cost['categories'])
                ->map(function (array $category, string $key) use (&$categoryKeys) {
                    $categoryKeys[$key] = $category['label'] ?? ucfirst(str_replace('_', ' ', $key));

                    return [
                        'key' => $key,
                        'label' => $category['label'] ?? ucfirst(str_replace('_', ' ', $key)),
                        'amount' => $category['amount'] ?? '0.00',
                        'count' => $category['count'] ?? 0,
                    ];
                })
                ->values()
                ->all();

            $topCategory = collect($categories)
                ->sortByDesc(fn (array $category) => (float) $category['amount'])
                ->first();

            $status = $variance['totals']['status'] ?? 'on_track';
            if ($status === 'over_budget') {
                $sitesOverBudget++;
            }

            $totalSpend = bcadd($totalSpend, (string) ($cost['total'] ?? '0.00'), 2);

            $rows[] = [
                'site' => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'type' => $site->type,
                    'region' => $site->region,
                ],
                'total_cost' => $cost['total'] ?? '0.00',
                'budget' => [
                    'planned' => $variance['totals']['planned'] ?? '0.00',
                    'actual' => $variance['totals']['actual'] ?? '0.00',
                    'variance' => $variance['totals']['variance'] ?? '0.00',
                    'variance_pct' => $variance['totals']['variance_pct'] ?? '0.0',
                    'status' => $status,
                ],
                'top_category' => $topCategory ?: null,
                'categories' => $categories,
                'trend' => collect($trend)
                    ->map(fn (string $amount, string $month) => [
                        'month' => $month,
                        'amount' => $amount,
                    ])
                    ->values()
                    ->all(),
                'dashboard_url' => "/finance/sites/{$site->id}/financial-dashboard",
            ];
        }

        usort($rows, fn (array $a, array $b) => (float) $b['total_cost'] <=> (float) $a['total_cost']);

        return Inertia::render('finance/sites-overview/Show', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'kpis' => [
                'total_cost' => $totalSpend,
                'sites_over_budget' => $sitesOverBudget,
                'site_count' => count($rows),
                'avg_cost_per_site' => count($rows) > 0
                    ? number_format((float) $totalSpend / count($rows), 2, '.', '')
                    : '0.00',
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'top_spenders' => array_slice($rows, 0, 5),
            ],
            'sites' => $rows,
            'categoryKeys' => collect($categoryKeys)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }
}
