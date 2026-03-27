<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinCashFlowForecast;
use App\Domain\Finance\Services\CashFlowForecastService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashFlowForecastController extends Controller
{
    public function __construct(
        protected CashFlowForecastService $forecastService,
    ) {}

    /**
     * List all cash flow forecasts.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $forecasts = FinCashFlowForecast::forOrganization($orgId)
            ->with('createdBy:id,name')
            ->withCount('scenarios')
            ->orderByDesc('forecast_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('finance/CashFlowForecast/Index', [
            'forecasts' => $forecasts,
        ]);
    }

    /**
     * Show the form to create a new forecast.
     */
    public function create(Request $request)
    {
        return Inertia::render('finance/CashFlowForecast/Create');
    }

    /**
     * Generate and store a new forecast.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'period_type' => ['required', 'in:weekly,fortnightly,monthly'],
        ]);

        $orgId = $request->user()->organization_id;

        $forecast = $this->forecastService->generateForecast(
            $orgId,
            $validated['period_start'],
            $validated['period_end'],
            $validated['period_type'],
        );

        return redirect()->route('finance.cash-flow-forecast.show', $forecast)
            ->with('success', 'Cash flow forecast generated successfully.');
    }

    /**
     * Show a forecast with chart data and scenario comparison.
     */
    public function show(Request $request, FinCashFlowForecast $forecast)
    {
        $forecast->load([
            'scenarios',
            'createdBy:id,name',
        ]);

        // Prepare chart data from forecast periods
        $chartData = $this->buildChartData($forecast);

        return Inertia::render('finance/CashFlowForecast/Show', [
            'forecast' => $forecast,
            'chartData' => $chartData,
        ]);
    }

    /**
     * Delete a draft forecast.
     */
    public function destroy(Request $request, FinCashFlowForecast $forecast)
    {
        if ($forecast->status !== 'draft') {
            return redirect()->back()
                ->withErrors(['status' => 'Only draft forecasts can be deleted.']);
        }

        $forecast->delete();

        return redirect()->route('finance.cash-flow-forecast.index')
            ->with('success', 'Forecast deleted.');
    }

    /**
     * Build chart-ready data from forecast and its scenarios.
     */
    private function buildChartData(FinCashFlowForecast $forecast): array
    {
        $periods = $forecast->forecast_data ?? [];
        $labels = array_map(fn ($p) => $p['period_label'] ?? '', $periods);

        $datasets = [
            [
                'label' => 'Inflows',
                'data' => array_map(fn ($p) => (float) ($p['inflows']['total'] ?? 0), $periods),
                'type' => 'bar',
            ],
            [
                'label' => 'Outflows',
                'data' => array_map(fn ($p) => (float) ($p['outflows']['total'] ?? 0), $periods),
                'type' => 'bar',
            ],
            [
                'label' => 'Net Cash Flow',
                'data' => array_map(fn ($p) => (float) ($p['net_cash_flow'] ?? 0), $periods),
                'type' => 'bar',
            ],
            [
                'label' => 'Closing Balance',
                'data' => array_map(fn ($p) => (float) ($p['closing_balance'] ?? 0), $periods),
                'type' => 'line',
            ],
        ];

        // Add scenario closing balances
        foreach ($forecast->scenarios as $scenario) {
            $scenarioData = $scenario->forecast_data ?? [];
            $datasets[] = [
                'label' => $scenario->name . ' (Balance)',
                'data' => array_map(fn ($p) => (float) ($p['closing_balance'] ?? 0), $scenarioData),
                'type' => 'line',
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }
}
