<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinCashFlowForecast;
use App\Domain\Finance\Models\FinCashFlowScenario;
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

        $search = trim((string) $request->input('q', ''));
        $status = (string) $request->input('status', '');
        $periodType = (string) $request->input('period_type', '');

        $forecasts = FinCashFlowForecast::forOrganization($orgId)
            ->with('createdBy:id,name')
            ->withCount('scenarios')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when(in_array($status, ['draft', 'final'], true), fn ($query) => $query->where('status', $status))
            ->when(
                in_array($periodType, ['weekly', 'fortnightly', 'monthly'], true),
                fn ($query) => $query->where('period_type', $periodType),
            )
            ->orderByDesc('forecast_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Whole-register totals for the header meters — never the page slice.
        $counts = FinCashFlowForecast::forOrganization($orgId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('finance/CashFlowForecast/Index', [
            'forecasts' => $forecasts,
            'summary' => [
                'total' => (int) $counts->sum(),
                'draft' => (int) ($counts['draft'] ?? 0),
                'final' => (int) ($counts['final'] ?? 0),
                'scenarios' => (int) FinCashFlowScenario::query()
                    ->whereIn(
                        'forecast_id',
                        FinCashFlowForecast::forOrganization($orgId)->select('id'),
                    )
                    ->count(),
            ],
            'filters' => [
                'q' => $search,
                'status' => $status,
                'period_type' => $periodType,
            ],
            // Store shares the route group's finance.reports.view permission —
            // passed for consistency with the other index-modal flows.
            'canManage' => (bool) $request->user()->canDo('finance.reports.view'),
        ]);
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
     * Show a forecast with its periods and scenario comparison.
     */
    public function show(Request $request, FinCashFlowForecast $forecast)
    {
        $forecast->load([
            'scenarios',
            'createdBy:id,name',
        ]);

        // The page charts `forecast_data` (and each scenario's) directly, so
        // there is no second, pre-shaped chart payload to keep in step.
        return Inertia::render('finance/CashFlowForecast/Show', [
            'forecast' => $forecast,
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
}
