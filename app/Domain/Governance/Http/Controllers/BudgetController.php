<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\BudgetAllocation;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BudgetController extends Controller
{
    /**
     * Budget categories offered for line items.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'staffing' => 'Staffing',
        'operations' => 'Operations',
        'fleet' => 'Fleet',
        'compliance' => 'Compliance',
        'capital' => 'Capital',
        'admin' => 'Administration',
        'other' => 'Other',
    ];

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

        $canCreate = $request->user()->can('create', Budget::class);

        return Inertia::render('Governance/Budgets/Index', [
            'budgets' => $budgets,
            'canCreate' => $canCreate,
            // New-budget wizard options, only for viewers who may create.
            'formOptions' => $canCreate ? $this->formOptions() : null,
        ]);
    }

    /**
     * Legacy deep link: the new-budget wizard is a dialog on the index.
     * Authorise exactly as the retired page did, then open it there.
     */
    public function create()
    {
        $this->authorize('create', Budget::class);

        return redirect()->route('governance.budgets.index', ['create' => 1]);
    }

    public function show(Request $request, Budget $budget)
    {
        $this->authorize('view', $budget);
        $budget->load([
            'lineItems',
            'adjustments.proposedBy',
            'adjustments.approvedBy',
            'adjustments.lineItem',
            'adjustments.approvalResolution:id,resolution_reference,title,status,outcome,cost_impact',
            'allocations.createdBy:id,name',
            'allocations.budgetLineItem:id,description,category',
            'approvalResolution.votes',
            'proposedBy',
            'createdBy',
        ]);

        $categories = self::CATEGORIES;

        // Explicit authority: only carried resolutions bound (and not yet
        // used) to one of this budget's adjustments can apply to it.
        $adjustmentIds = $budget->adjustments->pluck('id')->all();
        $carriedResolutions = $adjustmentIds === [] ? collect() : Resolution::query()
            ->where('outcome', 'carried')
            ->whereIn('status', ['closed', 'implemented', 'archived'])
            ->whereHas('authorityBindings', fn ($q) => $q
                ->where('subject_type', GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT)
                ->whereIn('subject_id', $adjustmentIds)
                ->whereNull('consumed_at'))
            ->select(['id', 'resolution_reference', 'title', 'outcome', 'cost_impact', 'closed_at'])
            ->with('authorityBindings:id,resolution_id,subject_type,subject_id,amount,direction,consumed_at')
            ->orderByDesc('id')
            ->get();

        $user = $request->user();

        return Inertia::render('Governance/Budgets/Show', [
            'budget' => $budget,
            'categories' => $categories,
            'carriedResolutions' => $carriedResolutions,
            // Edit wizard: the same audience as the retired edit button — the
            // budget structure (and its lines) is only editable before approval.
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
            ...$this->lineItemRules(),
        ]);

        $lineItems = $data['line_items'] ?? [];
        unset($data['line_items']);

        $isApproved = $data['board_approved'] ?? false;
        unset($data['board_approved']);
        $data['status'] = 'drafting';
        $data['created_by'] = $request->user()->id;

        $budget = DB::transaction(function () use ($request, $data, $lineItems, $isApproved): Budget {
            $data['version_number'] = (int) Budget::query()
                ->where('fiscal_year', $data['fiscal_year'])
                ->max('version_number') + 1;

            $budget = Budget::create($data);

            // Lines are built while the budget is still drafting, through the
            // same guarded mutation path as the budget page (recalculates the
            // envelope to the sum of the lines).
            foreach ($lineItems as $line) {
                $this->nestedMutations->storeBudgetLineItem($request->user(), $budget, $this->lineItemPayload($line));
            }

            if ($isApproved) {
                $budget->refresh()->update([
                    'status' => 'approved',
                    'approved_by_board_at' => now(),
                ]);
            }

            return $budget;
        });

        return redirect()->route('governance.budgets.show', $budget)
            ->with('success', $lineItems === []
                ? 'Budget created. Add line items to build your budget.'
                : 'Budget created with '.count($lineItems).' line item'.(count($lineItems) === 1 ? '' : 's').'.');
    }

    public function update(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

        $data = $request->validate([
            'fiscal_year' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('budgets', 'fiscal_year')
                    ->where('version_number', $budget->version_number)
                    ->ignore($budget->id),
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'total_budget' => ['sometimes', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            ...$this->lineItemRules(),
        ], [
            'fiscal_year.unique' => 'Another budget already uses this fiscal year and version number.',
        ]);

        $hasLines = array_key_exists('line_items', $data);
        $lineItems = $data['line_items'] ?? [];
        unset($data['line_items']);

        if ($hasLines) {
            $this->nestedMutations->assertBudgetStructureMutable($request->user(), $budget);
            $ids = collect($lineItems)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            $this->nestedMutations->assertBudgetLineItemsBound($request->user(), $budget, $ids);
        }

        DB::transaction(function () use ($request, $budget, $data, $hasLines, $lineItems): void {
            $budget->update($data);

            if ($hasLines) {
                $this->syncLineItems($request, $budget, $lineItems);

                // With no lines left the envelope is the figure the editor entered,
                // not the zero sum of removed lines.
                if ($lineItems === [] && array_key_exists('total_budget', $data)) {
                    $budget->refresh()->update(['total_budget' => $data['total_budget']]);
                }
            }
        });

        return redirect()->route('governance.budgets.show', $budget)->with('success', 'Budget updated.');
    }

    /** @return array{categories: array<string, string>} */
    private function formOptions(): array
    {
        return ['categories' => self::CATEGORIES];
    }

    /**
     * The wizard's nested budget lines — the same fields and limits as the
     * budget page's line item dialogs.
     *
     * @return array<string, array<int, mixed>>
     */
    private function lineItemRules(): array
    {
        return [
            'line_items' => ['sometimes', 'array', 'max:200'],
            'line_items.*.id' => ['nullable', 'integer', 'distinct'],
            'line_items.*.category' => ['required', 'string', 'max:50'],
            'line_items.*.description' => ['required', 'string', 'max:255'],
            'line_items.*.account_code' => ['nullable', 'string', 'max:50'],
            'line_items.*.budget_amount' => ['required', 'numeric', 'min:0'],
            'line_items.*.forecast_amount' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function lineItemPayload(array $line): array
    {
        return [
            'category' => $line['category'],
            'description' => $line['description'],
            'account_code' => $line['account_code'] ?? null,
            'budget_amount' => $line['budget_amount'],
            'forecast_amount' => $line['forecast_amount'] ?? $line['budget_amount'],
            'notes' => $line['notes'] ?? null,
        ];
    }

    /**
     * Apply the wizard's line list: keep and update listed lines, add new
     * ones, remove lines the editor deleted. Actual spend and variance notes
     * are recorded on the budget page and are left untouched here.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function syncLineItems(Request $request, Budget $budget, array $lineItems): void
    {
        $existing = $budget->lineItems()->get()->keyBy('id');
        $keptIds = [];

        foreach ($lineItems as $line) {
            $payload = $this->lineItemPayload($line);
            $id = isset($line['id']) ? (int) $line['id'] : null;

            if ($id === null) {
                $this->nestedMutations->storeBudgetLineItem($request->user(), $budget, $payload);

                continue;
            }

            $keptIds[] = $id;
            $current = $existing->get($id);
            if (! $current) {
                continue;
            }

            $changed = (string) $current->category !== (string) $payload['category']
                || (string) $current->description !== (string) $payload['description']
                || (string) ($current->account_code ?? '') !== (string) ($payload['account_code'] ?? '')
                || (string) ($current->notes ?? '') !== (string) ($payload['notes'] ?? '')
                || round((float) $current->budget_amount, 2) !== round((float) $payload['budget_amount'], 2)
                || round((float) ($current->forecast_amount ?? 0), 2) !== round((float) $payload['forecast_amount'], 2);

            if ($changed) {
                $this->nestedMutations->updateBudgetLineItem($request->user(), $budget, $current, $payload);
            }
        }

        foreach ($existing as $id => $line) {
            if (! in_array((int) $id, $keptIds, true)) {
                $this->nestedMutations->destroyBudgetLineItem($request->user(), $budget, $line);
            }
        }
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

        try {
            DB::transaction(function () use ($request, $budget, $resolution) {
                // Verifies and consumes the resolution's explicit binding to
                // this exact budget version and its budgeted lines.
                $budget->approve((int) $resolution->id, $request->user()->id);
                GovernanceAuditService::log('budget.approved', 'Budget', $budget->id, [
                    'resolution_id' => $resolution->id,
                    'total_budget' => $budget->total_budget,
                ]);
            });
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'The budget could not be approved.';

            return redirect()->back()
                ->withErrors($exception->errors())
                ->with('error', $message);
        }

        return redirect()->back()->with('success', 'Budget approved by board.');
    }

    /** Legacy deep link: the edit wizard is a dialog on the budget page. */
    public function edit(Budget $budget)
    {
        $this->authorize('update', $budget);

        return redirect()->route('governance.budgets.show', ['budget' => $budget->id, 'edit' => 1]);
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
            'approval_resolution_id' => ['nullable', 'integer', 'exists:resolutions,id'],
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
        $data = $request->validate([
            'approval_resolution_id' => ['nullable', 'integer', 'exists:resolutions,id'],
        ]);

        $resolutionId = $this->validInteger($data['approval_resolution_id'] ?? null);

        $this->nestedMutations->approveBudgetAdjustment($request->user(), $budget, $adjustment, $resolutionId);

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
