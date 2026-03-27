<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\BudgetActualsService;
use App\Domain\Governance\Models\Budget;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BudgetActualsController extends Controller
{
    public function __construct(
        private BudgetActualsService $service,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $budgetId = $request->input('budget_id') ? (int) $request->input('budget_id') : null;

        $budgets = Budget::query()
            ->orderBy('fiscal_year', 'desc')
            ->get(['id', 'fiscal_year', 'title', 'status'])
            ->map(fn (Budget $b) => [
                'id' => $b->id,
                'label' => ($b->title ?: 'Budget') . ' - FY' . $b->fiscal_year,
                'fiscal_year' => $b->fiscal_year,
                'status' => $b->status,
            ]);

        $report = $this->service->getBudgetVsActualsReport($orgId, $budgetId);

        return Inertia::render('finance/reports/BudgetVsActuals', [
            'budgets' => $budgets,
            'selectedBudgetId' => $budgetId ?? ($report['budget']['id'] ?? null),
            'report' => $report,
        ]);
    }

    public function sync(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $result = $this->service->syncActuals($orgId);

        return redirect()->back()->with('success', sprintf(
            'Actuals synced: %d line items updated. Budget: $%s, Actual: $%s, Variance: %s%%.',
            $result['updated'],
            number_format($result['total_budget'], 2),
            number_format($result['total_actual'], 2),
            number_format($result['variance'], 1),
        ));
    }
}
