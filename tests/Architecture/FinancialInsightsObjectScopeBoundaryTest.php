<?php

use App\Domain\Finance\Http\Controllers\BudgetForecastApiController;
use App\Domain\Finance\Http\Controllers\ClientFinancialsController;
use App\Domain\Finance\Http\Controllers\ExecutiveFinancialDashboardController;
use App\Domain\Finance\Http\Controllers\FinancialInsightsApiController;
use App\Domain\Finance\Http\Controllers\SiteFinancialDashboardController;
use App\Domain\Finance\Http\Controllers\SitesFinancialOverviewController;

function financialInsightsMethodSource(string $controller, string $method): string
{
    $reflection = new ReflectionMethod($controller, $method);
    $lines = file($reflection->getFileName());

    return implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
}

it('keeps every Financial Insights JSON endpoint behind the one object scope resolver', function () {
    $contracts = [
        FinancialInsightsApiController::class => [
            'siteFinancialSummary' => 'resolveSite(',
            'sitesOverview' => 'resolveAggregate(',
            'clientFinancialSummary' => 'resolveClient(',
            'clientLedger' => 'resolveClient(',
            'kpis' => 'resolveAggregate(',
            'siteKpis' => 'resolveAggregate(',
            'clientKpis' => 'resolveAggregate(',
            'insights' => 'resolveAggregate(',
        ],
        BudgetForecastApiController::class => [
            'budgetOverview' => 'resolveAggregate(',
            'siteBudget' => 'resolveSite(',
            'organisationVariance' => 'resolveAggregate(',
            'siteVariance' => 'resolveSite(',
            'siteVarianceTrend' => 'resolveSite(',
            'organisationForecast' => 'resolveAggregate(',
            'siteForecast' => 'resolveSite(',
        ],
    ];

    foreach ($contracts as $controller => $methods) {
        foreach ($methods as $method => $resolverCall) {
            $source = financialInsightsMethodSource($controller, $method);
            $resolverPosition = strpos($source, '$this->scopeResolver->'.$resolverCall);

            expect($resolverPosition)->not->toBeFalse()
                ->and(substr($source, 0, $resolverPosition))
                ->not->toContain('Service->')
                ->not->toContain('SiteBudgetLine::')
                ->not->toContain('Client::')
                ->not->toContain('Site::');

            expect($source)
                ->toContain('$this->scopeResolver->'.$resolverCall)
                ->not->toContain('organization_id')
                ->not->toContain('tenant_id')
                ->not->toContain('reports.viewAny')
                ->not->toContain('withTrashed(');
        }
    }
});

it('keeps matching Financial Insights pages on the same resolver boundary', function () {
    $contracts = [
        ClientFinancialsController::class => ['show', 'resolveClient('],
        SiteFinancialDashboardController::class => ['show', 'resolveSite('],
        SitesFinancialOverviewController::class => ['index', 'resolveAggregate('],
        ExecutiveFinancialDashboardController::class => ['index', 'resolveAggregate('],
    ];

    foreach ($contracts as $controller => [$method, $resolverCall]) {
        $source = financialInsightsMethodSource($controller, $method);
        $resolverPosition = strpos($source, '$this->scopeResolver->'.$resolverCall);

        expect($resolverPosition)->not->toBeFalse()
            ->and(substr($source, 0, $resolverPosition))
            ->not->toContain('Service->')
            ->not->toContain('Client::')
            ->not->toContain('Site::');

        expect($source)
            ->toContain('$this->scopeResolver->'.$resolverCall)
            ->not->toContain('reports.viewAny')
            ->not->toContain('organization_id')
            ->not->toContain('tenant_id')
            ->not->toContain('withTrashed(');
    }
});

it('accounts for all fifteen routes in the Financial Insights API group', function () {
    $expectedActions = [
        "[FinancialInsightsApiController::class, 'sitesOverview']",
        "[FinancialInsightsApiController::class, 'siteFinancialSummary']",
        "[FinancialInsightsApiController::class, 'clientFinancialSummary']",
        "[FinancialInsightsApiController::class, 'clientLedger']",
        "[FinancialInsightsApiController::class, 'kpis']",
        "[FinancialInsightsApiController::class, 'siteKpis']",
        "[FinancialInsightsApiController::class, 'clientKpis']",
        "[FinancialInsightsApiController::class, 'insights']",
        "[BudgetForecastApiController::class, 'budgetOverview']",
        "[BudgetForecastApiController::class, 'siteBudget']",
        "[BudgetForecastApiController::class, 'organisationVariance']",
        "[BudgetForecastApiController::class, 'siteVariance']",
        "[BudgetForecastApiController::class, 'siteVarianceTrend']",
        "[BudgetForecastApiController::class, 'organisationForecast']",
        "[BudgetForecastApiController::class, 'siteForecast']",
    ];

    $fullRouteSource = (string) file_get_contents(dirname(__DIR__, 2).'/routes/finance.php');
    $markerPosition = strpos($fullRouteSource, '// ── Financial Insights API (JSON)');
    expect($markerPosition)->not->toBeFalse();

    $routeSource = substr($fullRouteSource, $markerPosition);
    expect($routeSource)->toContain("middleware('permission:finance.dashboard')");

    foreach ($expectedActions as $action) {
        expect($routeSource)->toContain($action);
    }

    expect(substr_count($routeSource, '[FinancialInsightsApiController::class,'))
        ->toBe(8)
        ->and(substr_count($routeSource, '[BudgetForecastApiController::class,'))
        ->toBe(7)
        ->and(substr_count($routeSource, "->whereNumber('site')"))
        ->toBe(5)
        ->and(substr_count($routeSource, "->whereNumber('client')"))
        ->toBe(2);

    preg_match_all('/\\[([A-Za-z]+Controller)::class,/', $routeSource, $controllerMatches);
    $controllers = array_values(array_unique($controllerMatches[1]));
    sort($controllers);

    expect($controllers)->toBe([
        'BudgetForecastApiController',
        'FinancialInsightsApiController',
    ]);
});

it('requires aggregate services to consume resolved IDs instead of legacy organisation scope', function () {
    $sources = collect([
        'app/Domain/Finance/Services/SiteFinancialDashboardService.php',
        'app/Domain/Finance/Services/FinancialKPIService.php',
        'app/Domain/Finance/Services/FinancialInsightsService.php',
        'app/Domain/Finance/Services/BudgetVarianceService.php',
        'app/Domain/Finance/Services/FinancialForecastService.php',
    ])->map(fn (string $path): string => (string) file_get_contents(
        dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path,
    ))->implode("\n");

    expect($sources)
        ->not->toContain('forTenant(')
        ->not->toContain('$tenantId')
        ->not->toContain('organization_id');

    $summarySource = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Domain/Finance/Services/ClientFinancialSummaryService.php',
    );
    expect($summarySource)->not->toContain('withTrashed(');
});
