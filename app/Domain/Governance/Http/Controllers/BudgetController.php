<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\BudgetAllocation;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BudgetController extends Controller
{
    public function __construct(
        private readonly GovernanceNestedMutationService $nestedMutations,
    ) {}

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
        $this->nestedMutations->assertBudgetStructureMutable($request->user(), $budget);

        $data = $request->validate([
            'category' => ['required', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:255'],
            'account_code' => ['nullable', 'string', 'max:50'],
            'budget_amount' => ['required', 'numeric', 'min:0'],
            'forecast_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['forecast_amount'] = $data['forecast_amount'] ?? $data['budget_amount'];
        $data['actual_amount'] = $data['actual_amount'] ?? 0;

        $this->nestedMutations->storeBudgetLineItem($request->user(), $budget, $data);

        return redirect()->back()->with('success', 'Line item added.');
    }

    public function updateLineItem(Request $request, Budget $budget, BudgetLineItem $lineItem)
    {
        $this->nestedMutations->assertBudgetLineItemMutable($request->user(), $budget, $lineItem);

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

        $this->nestedMutations->updateBudgetLineItem($request->user(), $budget, $lineItem, $data);

        return redirect()->back()->with('success', 'Line item updated.');
    }

    public function destroyLineItem(Request $request, Budget $budget, BudgetLineItem $lineItem)
    {
        $this->nestedMutations->destroyBudgetLineItem($request->user(), $budget, $lineItem);

        return redirect()->back()->with('success', 'Line item removed.');
    }

    // ---- Allocations (link annual budget → monthly site buckets) ----

    public function storeAllocation(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

        $lineItemId = $this->validInteger($request->input('budget_line_item_id'));
        if ($lineItemId !== null) {
            $this->nestedMutations->assertBudgetLineItemBound(
                $request->user(),
                $budget,
                $lineItemId,
            );
        }

        $siteId = $this->validInteger($request->input('site_id'));
        if ($request->input('site_id') === null || $siteId !== null) {
            $this->nestedMutations->assertBudgetAllocationSiteAccessible(
                $request->user(),
                $budget,
                $siteId,
            );
        }

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

        $this->nestedMutations->storeBudgetAllocation($request->user(), $budget, $data);

        return redirect()->back()->with('success', 'Allocation added.');
    }

    public function updateAllocation(Request $request, Budget $budget, BudgetAllocation $allocation)
    {
        $this->authorize('update', $budget);
        abort_if($allocation->budget_id !== $budget->id, 404);
        $this->nestedMutations->assertBudgetAllocationBoundAndAccessible(
            $request->user(),
            $budget,
            $allocation,
        );

        if ($request->has('site_id')) {
            $siteId = $this->validInteger($request->input('site_id'));
            if ($request->input('site_id') === null || $siteId !== null) {
                $this->nestedMutations->assertBudgetAllocationSiteAccessible(
                    $request->user(),
                    $budget,
                    $siteId,
                );
            }
        }

        $data = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'category' => ['nullable', 'string', 'max:50'],
            'allocated_amount' => ['sometimes', 'numeric', 'min:0'],
            'forecast_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->nestedMutations->updateBudgetAllocation($request->user(), $budget, $allocation, $data);

        return redirect()->back()->with('success', 'Allocation updated.');
    }

    public function destroyAllocation(Request $request, Budget $budget, BudgetAllocation $allocation)
    {
        $this->authorize('update', $budget);
        abort_if($allocation->budget_id !== $budget->id, 404);

        $this->nestedMutations->destroyBudgetAllocation($request->user(), $budget, $allocation);

        return redirect()->back()->with('success', 'Allocation removed.');
    }

    // ---- Adjustments ----

    public function requestAdjustment(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

        $lineItemId = $this->validInteger($request->input('budget_line_item_id'));
        if ($lineItemId !== null) {
            $this->nestedMutations->assertBudgetLineItemBound(
                $request->user(),
                $budget,
                $lineItemId,
            );
        }

        $data = $request->validate([
            'budget_line_item_id' => ['nullable', 'integer', 'exists:budget_line_items,id'],
            'adjustment_type' => ['required', 'string', 'in:increase,decrease,reallocate'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $adjustment = $this->nestedMutations->requestBudgetAdjustment(
            $request->user(),
            $budget,
            $data,
        );

        return redirect()->back()->with('success', $adjustment->threshold_applies
            ? 'Adjustment submitted. Board approval required (exceeds threshold).'
            : 'Adjustment submitted for review.');
    }

    public function approveAdjustment(Request $request, Budget $budget, BudgetAdjustment $adjustment)
    {
        $this->nestedMutations->approveBudgetAdjustment($request->user(), $budget, $adjustment);

        return redirect()->back()->with('success', 'Adjustment approved and applied.');
    }

    public function rejectAdjustment(Request $request, Budget $budget, BudgetAdjustment $adjustment)
    {
        $this->nestedMutations->assertBudgetAdjustmentBound($request->user(), $budget, $adjustment);

        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:1000'],
        ]);

        $this->nestedMutations->rejectBudgetAdjustment(
            $request->user(),
            $budget,
            $adjustment,
            $data['review_notes'],
        );

        return redirect()->back()->with('success', 'Adjustment rejected.');
    }

    // ---- Record Actual Spend (bulk update) ----

    public function recordActuals(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

        $rawActuals = $request->input('actuals', []);
        if (is_array($rawActuals)) {
            $rawIds = collect($rawActuals)
                ->filter(fn ($actual): bool => is_array($actual) && $this->validInteger($actual['id'] ?? null) !== null)
                ->map(fn (array $actual): int => (int) $actual['id'])
                ->all();
            $this->nestedMutations->assertBudgetLineItemsBound($request->user(), $budget, $rawIds);
        }

        $data = $request->validate([
            'actuals' => ['required', 'array'],
            'actuals.*.id' => ['required', 'integer', 'distinct', 'exists:budget_line_items,id'],
            'actuals.*.actual_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $this->nestedMutations->recordBudgetActuals($request->user(), $budget, $data['actuals']);

        return redirect()->back()->with('success', 'Actual spend recorded.');
    }

    private function validInteger(mixed $value): ?int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return $validated === false ? null : $validated;
    }
}
