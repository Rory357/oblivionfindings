<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\BudgetAllocation;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Services\GovernanceAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Budget::class);

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
        $this->authorize('create', Budget::class);

        return Inertia::render('Governance/Budgets/Create');
    }

    public function show(Request $request, Budget $budget)
    {
        $this->authorize('view', $budget);
        $budget->load([
            'lineItems',
            'adjustments.proposedBy',
            'adjustments.approvedBy',
            'adjustments.lineItem',
            'allocations.createdBy:id,name',
            'allocations.budgetLineItem:id,description,category',
            'approvalResolution.votes',
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

        $user = $request->user();

        return Inertia::render('Governance/Budgets/Show', [
            'budget' => $budget,
            'categories' => $categories,
            'canEdit' => ($budget->isDrafting() || $budget->status === 'proposed') && $user->canDo('governance.budgets.create'),
            'canPropose' => $budget->isDrafting() && $user->canDo('governance.budgets.submit'),
            'canApprove' => $budget->isProposed() && $user->canDo('governance.budgets.approve'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Budget::class);

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
        $this->authorize('update', $budget);

        $data = $request->validate([
            'fiscal_year' => ['sometimes', 'string', 'max:20'],
            'title' => ['sometimes', 'string', 'max:255'],
            'total_budget' => ['sometimes', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);

        $budget->update($data);

        return redirect()->route('governance.budgets.show', $budget)->with('success', 'Budget updated.');
    }

    public function propose(Request $request, Budget $budget)
    {
        $this->authorize('propose', $budget);

        DB::transaction(function () use ($request, $budget) {
            $budget->propose($request->user()->id);
            GovernanceAuditService::log('budget.proposed', 'Budget', $budget->id, [
                'fiscal_year' => $budget->fiscal_year,
                'total_budget' => $budget->total_budget,
            ]);
        });

        return redirect()->back()->with('success', 'Budget proposed to board.');
    }

    public function approve(Request $request, Budget $budget)
    {
        $this->authorize('approve', $budget);

        $resolution = $budget->approvalResolution;

        if (! $resolution) {
            return redirect()->back()->with('error', 'No linked resolution found. The budget must be proposed first.');
        }

        if ($resolution->outcome !== 'carried') {
            return redirect()->back()->with('error', 'The board resolution has not been carried yet. Voting must be completed first.');
        }

        if ($budget->status === 'approved') {
            return redirect()->back()->with('error', 'Budget is already approved.');
        }

        DB::transaction(function () use ($budget, $resolution) {
            $budget->approve($resolution->id);
            GovernanceAuditService::log('budget.approved', 'Budget', $budget->id, [
                'resolution_id' => $resolution->id,
                'total_budget' => $budget->total_budget,
            ]);
        });

        return redirect()->back()->with('success', 'Budget approved by board.');
    }

    public function edit(Budget $budget)
    {
        $this->authorize('update', $budget);
        $budget->load('lineItems');

        return Inertia::render('Governance/Budgets/Edit', [
            'budget' => $budget,
        ]);
    }

    // ---- Line Item CRUD ----

    public function storeLineItem(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

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
        $this->authorize('update', $budget);

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
        $this->authorize('update', $budget);

        $lineItem->delete();

        // Recalculate budget total
        $budget->recalculateTotals();

        return redirect()->back()->with('success', 'Line item removed.');
    }

    // ---- Allocations (link annual budget → monthly site buckets) ----

    public function storeAllocation(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

        $data = $request->validate([
            'budget_line_item_id' => ['nullable', 'integer', 'exists:budget_line_items,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'site_budget_line_id' => ['nullable', 'integer'],
            'period_year_month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'category' => ['nullable', 'string', 'max:50'],
            'allocated_amount' => ['required', 'numeric', 'min:0'],
            'forecast_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['budget_id'] = $budget->id;
        $data['created_by'] = $request->user()->id;

        $allocation = BudgetAllocation::create($data);

        GovernanceAuditService::log('budget.allocation_created', 'BudgetAllocation', $allocation->id, [
            'budget_id' => $budget->id,
            'period' => $data['period_year_month'],
            'amount' => $data['allocated_amount'],
        ]);

        return redirect()->back()->with('success', 'Allocation added.');
    }

    public function updateAllocation(Request $request, Budget $budget, BudgetAllocation $allocation)
    {
        $this->authorize('update', $budget);
        abort_if($allocation->budget_id !== $budget->id, 404);

        $data = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:50'],
            'allocated_amount' => ['sometimes', 'numeric', 'min:0'],
            'forecast_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $allocation->update($data);

        return redirect()->back()->with('success', 'Allocation updated.');
    }

    public function destroyAllocation(Request $request, Budget $budget, BudgetAllocation $allocation)
    {
        $this->authorize('update', $budget);
        abort_if($allocation->budget_id !== $budget->id, 404);

        $allocation->delete();

        return redirect()->back()->with('success', 'Allocation removed.');
    }

    // ---- Adjustments ----

    public function requestAdjustment(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

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
        $this->authorize('update', $budget);

        $adjustment->approve($request->user()->id);

        return redirect()->back()->with('success', 'Adjustment approved and applied.');
    }

    public function rejectAdjustment(Request $request, Budget $budget, BudgetAdjustment $adjustment)
    {
        $this->authorize('update', $budget);

        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:1000'],
        ]);

        $adjustment->reject($request->user()->id, $data['review_notes']);

        return redirect()->back()->with('success', 'Adjustment rejected.');
    }

    // ---- Record Actual Spend (bulk update) ----

    public function recordActuals(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

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
