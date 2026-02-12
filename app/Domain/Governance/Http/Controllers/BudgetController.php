<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\BudgetAdjustment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        $budgets = Budget::query()
            ->withCount('lineItems')
            ->orderBy('fiscal_year', 'desc')
            ->get()
            ->map(function ($budget) {
                $budget->total_allocated = $budget->lineItems->sum('budget_amount');
                $budget->total_actual = $budget->lineItems->sum('actual_amount');
                return $budget;
            });

        return Inertia::render('Governance/Budgets/Index', [
            'budgets' => $budgets,
        ]);
    }

    public function create()
    {
        return Inertia::render('Governance/Budgets/Create');
    }

    public function show(Request $request, Budget $budget)
    {
        $budget->load([
            'lineItems',
            'adjustments.proposedBy',
            'adjustments.approvedBy',
            'adjustments.lineItem',
            'approvalResolution',
            'proposedBy',
            'createdBy',
        ]);

        $categories = [
            'staffing' => 'Staffing',
            'operations' => 'Operations',
            'fleet' => 'Fleet',
            'compliance' => 'Compliance',
            'capital' => 'Capital',
            'admin' => 'Administration',
            'other' => 'Other',
        ];

        return Inertia::render('Governance/Budgets/Show', [
            'budget' => $budget,
            'categories' => $categories,
            'canEdit' => $budget->isDrafting() || $budget->status === 'proposed',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'title' => ['nullable', 'string', 'max:255'],
            'total_budget' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'board_approved' => ['boolean'],
        ]);

        $isApproved = $data['board_approved'] ?? false;
        $data['status'] = $isApproved ? 'approved' : 'drafting';
        $data['created_by'] = $request->user()->id;
        if ($isApproved) {
            $data['approved_by_board_at'] = now();
        }

        $budget = Budget::create($data);

        return redirect()->route('governance.budgets.show', $budget)
            ->with('success', 'Budget created. Add line items to build your budget.');
    }

    public function update(Request $request, Budget $budget)
    {
        $data = $request->validate([
            'fiscal_year' => ['sometimes', 'string', 'max:20'],
            'title' => ['sometimes', 'string', 'max:255'],
            'total_budget' => ['sometimes', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:drafting,proposed,under_review,approved,rejected'],
        ]);

        $budget->update($data);

        return redirect()->back()->with('success', 'Budget updated.');
    }

    public function propose(Request $request, Budget $budget)
    {
        $budget->propose($request->user()->id);

        return redirect()->back()->with('success', 'Budget proposed to board.');
    }

    public function edit(Budget $budget)
    {
        $budget->load('lineItems');

        return Inertia::render('Governance/Budgets/Edit', [
            'budget' => $budget,
        ]);
    }

    // ---- Line Item CRUD ----

    public function storeLineItem(Request $request, Budget $budget)
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:255'],
            'account_code' => ['nullable', 'string', 'max:50'],
            'budget_amount' => ['required', 'numeric', 'min:0'],
            'forecast_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['budget_id'] = $budget->id;
        $data['forecast_amount'] = $data['forecast_amount'] ?? $data['budget_amount'];
        $data['actual_amount'] = $data['actual_amount'] ?? 0;

        BudgetLineItem::create($data);

        // Recalculate budget total
        $budget->recalculateTotals();

        return redirect()->back()->with('success', 'Line item added.');
    }

    public function updateLineItem(Request $request, Budget $budget, BudgetLineItem $lineItem)
    {
        $data = $request->validate([
            'category' => ['sometimes', 'string', 'max:50'],
            'description' => ['sometimes', 'string', 'max:255'],
            'account_code' => ['nullable', 'string', 'max:50'],
            'budget_amount' => ['sometimes', 'numeric', 'min:0'],
            'forecast_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_amount' => ['nullable', 'numeric', 'min:0'],
            'variance_explanation' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $lineItem->update($data);

        // Recalculate budget total
        $budget->recalculateTotals();

        return redirect()->back()->with('success', 'Line item updated.');
    }

    public function destroyLineItem(Request $request, Budget $budget, BudgetLineItem $lineItem)
    {
        $lineItem->delete();

        // Recalculate budget total
        $budget->recalculateTotals();

        return redirect()->back()->with('success', 'Line item removed.');
    }

    // ---- Adjustments ----

    public function requestAdjustment(Request $request, Budget $budget)
    {
        $data = $request->validate([
            'budget_line_item_id' => ['nullable', 'exists:budget_line_items,id'],
            'adjustment_type' => ['required', 'string', 'in:increase,decrease,reallocate'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $needsBoardApproval = $budget->requiresBoardApproval($data['amount']);

        $adjustment = $budget->adjustments()->create([
            'budget_line_item_id' => $data['budget_line_item_id'] ?? null,
            'adjustment_type' => $data['adjustment_type'],
            'amount' => $data['amount'],
            'reason' => $data['reason'],
            'proposed_by' => $request->user()->id,
            'proposed_at' => now(),
            'status' => 'submitted',
            'threshold_applies' => $needsBoardApproval,
        ]);

        return redirect()->back()->with('success', $needsBoardApproval
            ? 'Adjustment submitted. Board approval required (exceeds threshold).'
            : 'Adjustment submitted for review.');
    }

    public function approveAdjustment(Request $request, Budget $budget, BudgetAdjustment $adjustment)
    {
        $adjustment->approve($request->user()->id);

        return redirect()->back()->with('success', 'Adjustment approved and applied.');
    }

    public function rejectAdjustment(Request $request, Budget $budget, BudgetAdjustment $adjustment)
    {
        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:1000'],
        ]);

        $adjustment->reject($request->user()->id, $data['review_notes']);

        return redirect()->back()->with('success', 'Adjustment rejected.');
    }

    // ---- Record Actual Spend (bulk update) ----

    public function recordActuals(Request $request, Budget $budget)
    {
        $data = $request->validate([
            'actuals' => ['required', 'array'],
            'actuals.*.id' => ['required', 'exists:budget_line_items,id'],
            'actuals.*.actual_amount' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($data['actuals'] as $actual) {
            BudgetLineItem::where('id', $actual['id'])
                ->where('budget_id', $budget->id)
                ->update(['actual_amount' => $actual['actual_amount']]);
        }

        return redirect()->back()->with('success', 'Actual spend recorded.');
    }
}
