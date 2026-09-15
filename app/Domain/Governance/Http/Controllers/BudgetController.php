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
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BudgetController extends Controller
{
    /** Budget line categories (labels from the shared Governance label map). */
    private const CATEGORY_KEYS = ['staffing', 'operations', 'fleet', 'compliance', 'capital', 'admin', 'other'];

    public function __construct(
        private readonly GovernanceNestedMutationService $nestedMutations,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Budget::class);

        $currentFinancialYear = GovernanceLabels::financialYear($this->today());

        $budgets = Budget::query()
            ->with([
                'lineItems:id,budget_id,budget_amount,actual_amount',
                'supersedes:id,version_number',
            ])
            ->withCount('lineItems')
            ->orderBy('fiscal_year', 'desc')
            ->orderByDesc('version_number')
            ->get()
            ->map(fn (Budget $budget) => [
                'id' => (int) $budget->id,
                'fiscal_year' => (string) $budget->fiscal_year,
                'financial_year_label' => GovernanceLabels::financialYear((string) $budget->fiscal_year),
                'title' => $budget->title,
                'display_name' => $budget->displayName(),
                'total_budget' => $budget->total_budget,
                'status' => $budget->status,
                'version_number' => (int) $budget->version_number,
                'supersedes_version' => $budget->supersedes?->version_number,
                'approved_by_board_at' => $budget->approved_by_board_at?->toIso8601String(),
                'line_items_count' => (int) $budget->line_items_count,
                'total_allocated' => (float) $budget->lineItems->sum('budget_amount'),
                'total_actual' => (float) $budget->lineItems->sum('actual_amount'),
                'actuals_recorded' => $this->actualsRecorded($budget),
            ]);

        $thisYear = $budgets->filter(fn (array $row) => $row['status'] === 'approved'
            && $row['financial_year_label'] === $currentFinancialYear);

        $canCreate = $request->user()->can('create', Budget::class);

        return Inertia::render('Governance/Budgets/Index', [
            'budgets' => $budgets->values(),
            'summary' => [
                'financial_year' => $currentFinancialYear,
                'total' => $budgets->count(),
                'waiting' => $budgets->whereIn('status', ['proposed', 'under_review'])->count(),
                'drafts' => $budgets->where('status', 'drafting')->count(),
                'approved_this_year' => $thisYear->count(),
                'budgeted_this_year' => (float) $thisYear->sum('total_allocated'),
                'spent_this_year' => (float) $thisYear->where('actuals_recorded', true)->sum('total_actual'),
                'actuals_recorded_this_year' => $thisYear->contains('actuals_recorded', true),
            ],
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

        $user = $request->user();

        $budget->load([
            'lineItems' => fn ($query) => $query->orderBy('id'),
            'adjustments' => fn ($query) => $query->orderByDesc('id'),
            'adjustments.proposedBy:id,name',
            'adjustments.approvedBy:id,name',
            'adjustments.lineItem:id,description,category,budget_amount',
            'adjustments.approvalResolution:id,resolution_reference,title,status,outcome,cost_impact,governance_meeting_id',
            'allocations' => fn ($query) => $query->orderBy('period_year_month'),
            'allocations.createdBy:id,name',
            'allocations.budgetLineItem:id,description,category',
            'proposedBy:id,name',
            'createdBy:id,name',
            'supersedes:id,title,fiscal_year,version_number',
        ]);

        // Explicit authority: only passed resolutions linked (and not yet
        // used) to one of this budget's changes can approve them.
        $adjustmentIds = $budget->adjustments->pluck('id')->all();
        $carriedResolutions = $adjustmentIds === [] ? collect() : Resolution::query()
            ->where('outcome', 'carried')
            ->whereIn('status', Budget::PASSED_RESOLUTION_STATUSES)
            ->whereHas('authorityBindings', fn ($q) => $q
                ->where('subject_type', GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT)
                ->whereIn('subject_id', $adjustmentIds)
                ->whereNull('consumed_at'))
            ->select(['id', 'resolution_reference', 'title', 'outcome', 'cost_impact', 'closed_at', 'governance_meeting_id'])
            ->with('authorityBindings:id,resolution_id,subject_type,subject_id,amount,direction,consumed_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Resolution $resolution) => $user->can('view', $resolution))
            ->values();

        $state = $budget->resolutionState();
        $canCreate = $user->canDo('governance.budgets.create');
        $canSubmit = $user->canDo('governance.budgets.submit');
        $canApproveBudgets = $user->canDo('governance.budgets.approve');
        $resendable = $budget->isProposed() && $this->canResend($state);

        return Inertia::render('Governance/Budgets/Show', [
            'budget' => $this->presentBudget($budget, $user, $carriedResolutions),
            'categories' => $this->categories(),
            'carriedResolutions' => $carriedResolutions,
            'approval' => $this->approvalSummary($budget, $state, $user),
            'changeThreshold' => [
                'percent' => Budget::CHANGE_BOARD_THRESHOLD_PCT,
                'amount' => $budget->boardChangeThresholdAmount(),
                'sentence' => $budget->boardChangeThresholdSentence(),
            ],
            // Edit wizard: the budget structure (and its lines) is only
            // editable before approval.
            'canEdit' => ($budget->isDrafting() || $budget->isProposed()) && $canCreate,
            'canPropose' => $canSubmit && ($budget->isDrafting() || $resendable),
            'canReturnToDrafting' => $canSubmit && $budget->isProposed() && $this->canReturnToDrafting($state),
            'canApprove' => $canApproveBudgets && $budget->isProposed()
                && $state['state'] === Budget::RESOLUTION_PASSED && ! $state['stale'],
            'canRequestChange' => $canCreate && $budget->isApproved(),
            'canDecideChanges' => $canApproveBudgets,
            'canRecordActuals' => $canCreate && $budget->isApproved(),
            'canManageAllocations' => $canCreate,
            'allocationOptions' => $canCreate ? $this->allocationOptions($user) : null,
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
            'approved_on' => ['exclude_unless:board_approved,true', 'required', 'date', 'before_or_equal:today'],
            'approval_reference' => ['exclude_unless:board_approved,true', 'required', 'string', 'max:255'],
            ...$this->lineItemRules(),
        ], [
            ...$this->budgetMessages(),
            'approved_on.required' => 'Enter the date the board approved this budget.',
            'approved_on.date' => 'Enter the date the board approved this budget.',
            'approved_on.before_or_equal' => "The approval date can't be in the future.",
            'approval_reference.required' => 'Enter the minutes reference for the meeting that approved this budget.',
        ]);

        $lineItems = $data['line_items'] ?? [];
        unset($data['line_items']);

        $isApproved = (bool) ($data['board_approved'] ?? false);
        $approvedOn = $data['approved_on'] ?? null;
        $approvalReference = $data['approval_reference'] ?? null;
        unset($data['board_approved'], $data['approved_on'], $data['approval_reference']);
        $data['status'] = 'drafting';
        $data['created_by'] = $request->user()->id;

        $budget = DB::transaction(function () use ($request, $data, $lineItems, $isApproved, $approvedOn, $approvalReference): Budget {
            $data['version_number'] = (int) Budget::query()
                ->where('fiscal_year', $data['fiscal_year'])
                ->max('version_number') + 1;

            $budget = Budget::create($data);

            // Lines are built while the budget is still drafting, through the
            // same guarded mutation path as the budget page (recalculates the
            // total to the sum of the lines).
            foreach ($lineItems as $line) {
                $this->nestedMutations->storeBudgetLineItem($request->user(), $budget, $this->lineItemPayload($line));
            }

            if ($isApproved) {
                $budget->refresh()->update([
                    'status' => 'approved',
                    'approved_by_board_at' => CarbonImmutable::parse((string) $approvedOn, $this->timezone())->startOfDay()->utc(),
                    'external_approval_reference' => $approvalReference,
                ]);
            }

            return $budget;
        });

        $lineCount = count($lineItems);

        return redirect()->route('governance.budgets.show', $budget)
            ->with('success', match (true) {
                $isApproved => 'Budget recorded as approved by the board.',
                $lineCount === 0 => 'Budget created. Add its lines next.',
                default => 'Budget created with '.$lineCount.' line'.($lineCount === 1 ? '' : 's').'.',
            });
    }

    public function update(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

        // Only drafts and budgets waiting for the board are edited directly;
        // an approved budget's figures change only through a budget change.
        $this->nestedMutations->assertBudgetStructureMutable($request->user(), $budget);

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
            ...$this->budgetMessages(),
            'fiscal_year.unique' => 'Another budget already uses this financial year and version number.',
        ]);

        $hasLines = array_key_exists('line_items', $data);
        $lineItems = $data['line_items'] ?? [];
        unset($data['line_items']);

        if ($hasLines) {
            $ids = collect($lineItems)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            $this->nestedMutations->assertBudgetLineItemsBound($request->user(), $budget, $ids);
        }

        DB::transaction(function () use ($request, $budget, $data, $hasLines, $lineItems): void {
            $budget->update($data);

            if ($hasLines) {
                $this->syncLineItems($request, $budget, $lineItems);

                // With no lines left the total is the figure the editor entered,
                // not the zero sum of removed lines.
                if ($lineItems === [] && array_key_exists('total_budget', $data)) {
                    $budget->refresh()->update(['total_budget' => $data['total_budget']]);
                }
            }
        });

        return redirect()->route('governance.budgets.show', $budget)->with('success', 'Budget updated.');
    }

    /** @return array{categories: array<string, string>, financial_years: array<int, array<string, mixed>>} */
    private function formOptions(): array
    {
        $currentYearEnd = $this->financialYearEnd($this->today());

        return [
            'categories' => $this->categories(),
            // Stored as the year the financial year ends (NZ "FY2027" = 2026/27).
            'financial_years' => collect(range($currentYearEnd - 1, $currentYearEnd + 2))
                ->map(fn (int $yearEnd) => [
                    'value' => $yearEnd,
                    'label' => GovernanceLabels::financialYear($yearEnd),
                    'range' => sprintf('1 July %d – 30 June %d', $yearEnd - 1, $yearEnd),
                    'is_current' => $yearEnd === $currentYearEnd,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, string> */
    private function categories(): array
    {
        return collect(self::CATEGORY_KEYS)
            ->mapWithKeys(fn (string $key) => [$key => GovernanceLabels::label('budget_category', $key)])
            ->all();
    }

    /**
     * The wizard's nested budget lines — the same fields and limits as the
     * budget page's line dialogs.
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

    /** @return array<string, string> */
    private function budgetMessages(): array
    {
        return [
            'fiscal_year.required' => 'Choose the financial year this budget covers.',
            'fiscal_year.integer' => 'Choose the financial year this budget covers.',
            'fiscal_year.min' => 'Choose a financial year from the list.',
            'fiscal_year.max' => 'Choose a financial year from the list.',
            'total_budget.required' => 'Enter the total budget.',
            'total_budget.min' => "The total budget can't be negative.",
            'line_items.*.category.required' => 'Choose a category for this line.',
            'line_items.*.description.required' => 'Describe what this line pays for.',
            'line_items.*.budget_amount.required' => 'Enter the amount budgeted for this line.',
            'line_items.*.budget_amount.numeric' => 'Enter the amount as a number.',
            'line_items.*.budget_amount.min' => "Amounts can't be negative.",
            'line_items.*.forecast_amount.min' => "Amounts can't be negative.",
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
     * ones, remove lines the editor deleted. Actual spend is recorded on the
     * budget page and is left untouched here.
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

    /**
     * Send the budget to the board (or send an updated budget again when its
     * resolution can no longer approve it).
     */
    public function propose(Request $request, Budget $budget)
    {
        $this->authorize('propose', $budget);

        $wasProposed = $budget->isProposed();

        try {
            DB::transaction(function () use ($request, $budget) {
                $budget->propose($request->user()->id);
                GovernanceAuditService::log('budget.proposed', 'Budget', $budget->id, [
                    'fiscal_year' => $budget->fiscal_year,
                    'total_budget' => $budget->total_budget,
                ]);
            });
        } catch (ValidationException $exception) {
            return $this->backWithFirstError($exception);
        }

        return redirect()->back()->with('success', $wasProposed
            ? 'The updated budget has been sent to the board. Its resolution now matches these figures.'
            : 'Budget sent to the board. A draft resolution is ready for the secretary to add to a meeting agenda.');
    }

    /**
     * Take a budget waiting for the board back to draft when its resolution
     * did not pass or no longer matches the budget.
     */
    public function returnToDrafting(Request $request, Budget $budget)
    {
        $this->authorize('propose', $budget);

        try {
            $budget->returnToDrafting();
        } catch (ValidationException $exception) {
            return $this->backWithFirstError($exception);
        }

        GovernanceAuditService::log('budget.returned_to_drafting', 'Budget', $budget->id, [
            'fiscal_year' => $budget->fiscal_year,
        ]);

        return redirect()->back()->with('success', 'Budget returned to drafting. Update it, then send it to the board again.');
    }

    public function approve(Request $request, Budget $budget)
    {
        $this->authorize('approve', $budget);

        $resolution = $budget->approvalResolution;

        if ($budget->status === 'approved') {
            return redirect()->back()->with('error', 'This budget is already approved.');
        }

        if (! $resolution) {
            return redirect()->back()->with('error', 'This budget has no resolution yet. Send it to the board first.');
        }

        if ($resolution->outcome !== 'carried') {
            return redirect()->back()->with('error', "The board hasn't passed this budget's resolution yet.");
        }

        try {
            DB::transaction(function () use ($request, $budget, $resolution) {
                // Verifies and uses the resolution's link to this exact
                // budget version and its lines.
                $budget->approve((int) $resolution->id, $request->user()->id);
                GovernanceAuditService::log('budget.approved', 'Budget', $budget->id, [
                    'resolution_id' => $resolution->id,
                    'total_budget' => $budget->total_budget,
                ]);
            });
        } catch (ValidationException $exception) {
            return $this->backWithFirstError($exception);
        }

        return redirect()->back()->with('success', "Board approval recorded. The budget is now approved.");
    }

    /** Legacy deep link: the edit wizard is a dialog on the budget page. */
    public function edit(Budget $budget)
    {
        $this->authorize('update', $budget);

        return redirect()->route('governance.budgets.show', ['budget' => $budget->id, 'edit' => 1]);
    }

    // ---- Budget lines ----

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
        ], $this->lineMessages());

        $data['forecast_amount'] = $data['forecast_amount'] ?? $data['budget_amount'];
        $data['actual_amount'] = $data['actual_amount'] ?? 0;

        $this->nestedMutations->storeBudgetLineItem($request->user(), $budget, $data);

        return redirect()->back()->with('success', 'Budget line added.');
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
        ], $this->lineMessages());

        $this->nestedMutations->updateBudgetLineItem($request->user(), $budget, $lineItem, $data);

        return redirect()->back()->with('success', 'Budget line updated.');
    }

    public function destroyLineItem(Request $request, Budget $budget, BudgetLineItem $lineItem)
    {
        $this->nestedMutations->destroyBudgetLineItem($request->user(), $budget, $lineItem);

        return redirect()->back()->with('success', 'Budget line removed.');
    }

    /** @return array<string, string> */
    private function lineMessages(): array
    {
        return [
            'category.required' => 'Choose a category for this line.',
            'description.required' => 'Describe what this line pays for.',
            'budget_amount.required' => 'Enter the amount budgeted for this line.',
            'budget_amount.numeric' => 'Enter the amount as a number.',
            'budget_amount.min' => "Amounts can't be negative.",
            'forecast_amount.min' => "Amounts can't be negative.",
            'actual_amount.min' => "Amounts can't be negative.",
        ];
    }

    // ---- Monthly split (annual budget → monthly site amounts) ----

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
        ], $this->allocationMessages());

        $this->nestedMutations->storeBudgetAllocation($request->user(), $budget, $data);

        return redirect()->back()->with('success', 'Monthly amount added.');
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
        ], $this->allocationMessages());

        $this->nestedMutations->updateBudgetAllocation($request->user(), $budget, $allocation, $data);

        return redirect()->back()->with('success', 'Monthly amount updated.');
    }

    public function destroyAllocation(Request $request, Budget $budget, BudgetAllocation $allocation)
    {
        $this->authorize('update', $budget);
        abort_if($allocation->budget_id !== $budget->id, 404);

        $this->nestedMutations->destroyBudgetAllocation($request->user(), $budget, $allocation);

        return redirect()->back()->with('success', 'Monthly amount removed.');
    }

    /** @return array<string, string> */
    private function allocationMessages(): array
    {
        return [
            'period_year_month.required' => 'Choose the month.',
            'period_year_month.regex' => 'Choose the month.',
            'allocated_amount.required' => 'Enter the amount for the month.',
            'allocated_amount.numeric' => 'Enter the amount as a number.',
            'allocated_amount.min' => "Amounts can't be negative.",
            'forecast_amount.min' => "Amounts can't be negative.",
            'site_id.exists' => 'That site could not be found.',
            'budget_line_item_id.exists' => 'That budget line could not be found.',
        ];
    }

    // ---- Budget changes ----

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
            'budget_line_item_id' => ['required', 'integer', 'exists:budget_line_items,id'],
            'adjustment_type' => ['required', 'string', 'in:increase,decrease'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:1000'],
            'approval_resolution_id' => ['nullable', 'integer', 'exists:resolutions,id'],
        ], [
            'budget_line_item_id.required' => 'Choose which budget line this change applies to.',
            'budget_line_item_id.exists' => 'Choose which budget line this change applies to.',
            'adjustment_type.required' => 'Choose whether the line goes up or down.',
            'adjustment_type.in' => GovernanceNestedMutationService::REALLOCATION_UNAVAILABLE,
            'amount.required' => 'Enter the amount of the change.',
            'amount.numeric' => 'Enter the amount as a number.',
            'amount.min' => 'Enter an amount of at least $0.01.',
            'reason.required' => 'Say why this change is needed.',
            'reason.max' => 'Keep the reason to 1,000 characters.',
            'approval_resolution_id.exists' => 'That resolution no longer exists.',
        ]);

        $adjustment = $this->nestedMutations->requestBudgetAdjustment(
            $request->user(),
            $budget,
            $data,
        );

        return redirect()->back()->with('success', $adjustment->threshold_applies
            ? 'Budget change sent. It needs a board decision: the secretary adds it to a resolution, and once that passes an approver records the approval here.'
            : 'Budget change sent. An approver can now approve or decline it.');
    }

    public function approveAdjustment(Request $request, Budget $budget, BudgetAdjustment $adjustment)
    {
        $data = $request->validate([
            'approval_resolution_id' => ['nullable', 'integer', 'exists:resolutions,id'],
        ], [
            'approval_resolution_id.exists' => 'That resolution no longer exists.',
        ]);

        $resolutionId = $this->validInteger($data['approval_resolution_id'] ?? null);

        $this->nestedMutations->approveBudgetAdjustment($request->user(), $budget, $adjustment, $resolutionId);

        return redirect()->back()->with('success', 'Budget change approved. The line and the budget total are updated.');
    }

    public function rejectAdjustment(Request $request, Budget $budget, BudgetAdjustment $adjustment)
    {
        $this->nestedMutations->assertBudgetAdjustmentBound($request->user(), $budget, $adjustment);

        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:1000'],
        ], [
            'review_notes.required' => "Say why you're declining this change.",
            'review_notes.max' => 'Keep the reason to 1,000 characters.',
        ]);

        $this->nestedMutations->rejectBudgetAdjustment(
            $request->user(),
            $budget,
            $adjustment,
            $data['review_notes'],
        );

        return redirect()->back()->with('success', 'Budget change declined. The person who asked for it can see your reason.');
    }

    // ---- Record actual spend (bulk update) ----

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
        ], [
            'actuals.required' => 'Enter the actual spend for at least one line.',
            'actuals.*.actual_amount.required' => 'Enter the amount spent on this line (0 if nothing yet).',
            'actuals.*.actual_amount.numeric' => 'Enter the amount as a number.',
            'actuals.*.actual_amount.min' => "Amounts can't be negative.",
            'actuals.*.id.distinct' => 'Each budget line can only be listed once.',
        ]);

        $this->nestedMutations->recordBudgetActuals($request->user(), $budget, $data['actuals']);

        return redirect()->back()->with('success', 'Actual spend recorded.');
    }

    /**
     * Budget page payload: every figure the page shows, with plain labels,
     * and resolution details only where the viewer may see the resolution.
     *
     * @param  \Illuminate\Support\Collection<int, Resolution>  $carriedResolutions
     * @return array<string, mixed>
     */
    private function presentBudget(Budget $budget, User $user, $carriedResolutions): array
    {
        $readyByAdjustment = [];
        foreach ($carriedResolutions as $resolution) {
            foreach ($resolution->authorityBindings as $binding) {
                if ($binding->subject_type === GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT && $binding->consumed_at === null) {
                    $readyByAdjustment[(int) $binding->subject_id] ??= [
                        'id' => (int) $resolution->id,
                        'title' => $resolution->title,
                        'reference' => $resolution->resolution_reference,
                        'amount' => isset($resolution->cost_impact['amount']) && is_numeric($resolution->cost_impact['amount'])
                            ? (float) $resolution->cost_impact['amount']
                            : null,
                    ];
                }
            }
        }

        $siteNames = Site::query()
            ->whereIn('id', $budget->allocations->pluck('site_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        return [
            'id' => (int) $budget->id,
            'fiscal_year' => (string) $budget->fiscal_year,
            'financial_year_label' => GovernanceLabels::financialYear((string) $budget->fiscal_year),
            'title' => $budget->title,
            'display_name' => $budget->displayName(),
            'description' => $budget->description,
            'total_budget' => $budget->total_budget,
            'currency' => $budget->currency ?: 'NZD',
            'status' => $budget->status,
            'version_number' => (int) $budget->version_number,
            'supersedes' => $budget->supersedes ? [
                'id' => (int) $budget->supersedes->id,
                'version_number' => (int) $budget->supersedes->version_number,
            ] : null,
            'created_by' => $budget->createdBy?->name,
            'proposed_by' => $budget->proposedBy?->name,
            'proposed_at' => $budget->proposed_at?->toIso8601String(),
            'approved_by_board_at' => $budget->approved_by_board_at?->toIso8601String(),
            'external_approval_reference' => $budget->external_approval_reference,
            'actuals_recorded' => $this->actualsRecorded($budget),
            'actuals_recorded_at' => $budget->actuals_recorded_at?->toIso8601String(),
            'line_items' => $budget->lineItems->map(fn (BudgetLineItem $line) => [
                'id' => (int) $line->id,
                'category' => $line->category,
                'description' => $line->description,
                'account_code' => $line->account_code,
                'budget_amount' => $line->budget_amount,
                'forecast_amount' => $line->forecast_amount,
                'actual_amount' => $line->actual_amount,
                'notes' => $line->notes,
            ])->values()->all(),
            'changes' => $budget->adjustments->map(function (BudgetAdjustment $adjustment) use ($user, $readyByAdjustment) {
                $resolution = $adjustment->approvalResolution;

                return [
                    'id' => (int) $adjustment->id,
                    'direction' => $adjustment->adjustment_type,
                    'amount' => $adjustment->amount,
                    'reason' => $adjustment->reason,
                    'status' => $adjustment->status,
                    'needs_board' => (bool) $adjustment->threshold_applies,
                    'line' => $adjustment->lineItem ? [
                        'id' => (int) $adjustment->lineItem->id,
                        'description' => $adjustment->lineItem->description,
                        'budget_amount' => $adjustment->lineItem->budget_amount,
                    ] : null,
                    'requested_by' => $adjustment->proposedBy?->name,
                    'requested_at' => $adjustment->proposed_at?->toIso8601String(),
                    'decided_by' => $adjustment->approvedBy?->name,
                    'decided_at' => $adjustment->approved_at?->toIso8601String(),
                    'review_notes' => $adjustment->review_notes,
                    'resolution' => $resolution && $user->can('view', $resolution) ? [
                        'id' => (int) $resolution->id,
                        'title' => $resolution->title,
                        'reference' => $resolution->resolution_reference,
                        'status' => $resolution->status,
                        'outcome' => $resolution->outcome,
                    ] : null,
                    'ready_resolution' => $adjustment->status === 'submitted'
                        ? ($readyByAdjustment[(int) $adjustment->id] ?? null)
                        : null,
                ];
            })->values()->all(),
            'allocations' => $budget->allocations->map(fn (BudgetAllocation $allocation) => [
                'id' => (int) $allocation->id,
                'budget_line_item_id' => $allocation->budget_line_item_id ? (int) $allocation->budget_line_item_id : null,
                'line_description' => $allocation->budgetLineItem?->description,
                'site_id' => $allocation->site_id ? (int) $allocation->site_id : null,
                'site_name' => $allocation->site_id ? ($siteNames[$allocation->site_id] ?? null) : null,
                'period_year_month' => $allocation->period_year_month,
                'category' => $allocation->category,
                'allocated_amount' => $allocation->allocated_amount,
                'forecast_amount' => $allocation->forecast_amount,
                'actual_amount' => $allocation->actual_amount,
                'notes' => $allocation->notes,
            ])->values()->all(),
        ];
    }

    /**
     * Where the board's approval stands, in plain words, from the linked
     * resolution. Resolution titles and meetings are only included when the
     * viewer may see that resolution.
     *
     * @param  array{state: string, stale: bool}  $state
     * @return array<string, mixed>
     */
    private function approvalSummary(Budget $budget, array $state, User $user): array
    {
        $resolution = $budget->approval_resolution_id
            ? Resolution::query()->with('meeting:id,title,scheduled_at')->find($budget->approval_resolution_id)
            : null;
        $visibleResolution = $resolution && $user->can('view', $resolution) ? $resolution : null;
        $meeting = $visibleResolution?->meeting;

        $summary = fn (string $key, string $label, string $detail, string $tone) => [
            'key' => $key,
            'label' => $label,
            'detail' => $detail,
            'tone' => $tone,
            'stale' => $state['stale'],
            'resolution' => $visibleResolution ? [
                'id' => (int) $visibleResolution->id,
                'title' => $visibleResolution->title,
                'reference' => $visibleResolution->resolution_reference,
            ] : null,
        ];

        if ($budget->isApproved()) {
            $date = $budget->approved_by_board_at ? GovernanceLabels::date($budget->approved_by_board_at) : null;

            if ($budget->external_approval_reference) {
                return $summary('approved', 'Approved by the board', sprintf(
                    'Recorded as approved outside this system%s. Minutes reference: %s.',
                    $date ? " on {$date}" : '',
                    $budget->external_approval_reference,
                ), 'success');
            }

            return $summary('approved', 'Approved by the board', $date
                ? "The board's approval was recorded on {$date}."
                : "The board's approval has been recorded.", 'success');
        }

        if ($budget->isDrafting()) {
            return $summary('draft', 'Draft', "Not sent to the board yet. When it's ready, send it to the board and the secretary adds its resolution to a meeting.", 'neutral');
        }

        if (! $budget->isProposed()) {
            return $summary($budget->status, GovernanceLabels::label('budget_status', $budget->status), 'This budget is not waiting for a board decision.', 'neutral');
        }

        $staleNote = "The budget was edited after its resolution was prepared, so the resolution can't approve these figures. Send the updated budget to the board.";

        return match ($state['state']) {
            Budget::RESOLUTION_NONE => $summary('missing', 'No resolution yet', 'This budget is marked as waiting for the board but has no resolution. Send it to the board again to prepare one.', 'warning'),
            Budget::RESOLUTION_UNLINKED => $summary('unlinked', "Resolution can't approve this budget", "This resolution wasn't linked to this budget before the board voted, so it can't approve it. Return the budget to drafting, or send it to the board again to prepare a new resolution.", 'warning'),
            Budget::RESOLUTION_DRAFTED => $state['stale']
                ? $summary('stale', 'Resolution out of date', $staleNote, 'warning')
                : ($meeting
                    ? $summary('on_agenda', "On the agenda for {$meeting->title}", sprintf('The board votes on this budget at the meeting on %s.', GovernanceLabels::date($meeting->scheduled_at)), 'info')
                    : $summary('drafted', 'Resolution drafted — not yet on a meeting agenda', 'The secretary adds the resolution to a meeting agenda, and the board votes on it there.', 'warning')),
            Budget::RESOLUTION_VOTING_OPEN => $summary('voting_open', 'Voting open', $state['stale']
                ? "The board is voting, but the budget was edited after its resolution was prepared, so even if it passes it can't approve these figures."
                : 'The board is voting on this budget now.', $state['stale'] ? 'warning' : 'info'),
            Budget::RESOLUTION_PASSED => $state['stale']
                ? $summary('stale', 'Passed — but the budget has changed', "The board passed the resolution, but the budget was edited afterwards, so it can't approve these figures. Return the budget to drafting or send the updated budget to the board.", 'warning')
                : $summary('passed', 'Passed — ready to record approval', "The board passed this budget's resolution. An approver records the board's approval here.", 'success'),
            Budget::RESOLUTION_NOT_PASSED => $summary('not_passed', 'Not passed', "The board did not pass this budget's resolution. Return it to drafting to rework it, or send it to the board again.", 'critical'),
            default => $summary('used', 'Resolution already used', 'This resolution has already been used. Send the budget to the board again to prepare a new resolution.', 'warning'),
        };
    }

    /** @param  array{state: string, stale: bool}  $state */
    private function canResend(array $state): bool
    {
        return match ($state['state']) {
            Budget::RESOLUTION_VOTING_OPEN => false,
            Budget::RESOLUTION_PASSED, Budget::RESOLUTION_DRAFTED => $state['stale'],
            default => true,
        };
    }

    /** @param  array{state: string, stale: bool}  $state */
    private function canReturnToDrafting(array $state): bool
    {
        return match ($state['state']) {
            Budget::RESOLUTION_VOTING_OPEN, Budget::RESOLUTION_DRAFTED => false,
            Budget::RESOLUTION_PASSED => $state['stale'],
            default => true,
        };
    }

    /** @return array{sites: array<int, array{id: int, name: string}>, can_leave_site_empty: bool} */
    private function allocationOptions(User $user): array
    {
        $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds($user, ['reports.viewAny']);

        return [
            'sites' => $siteIds === [] ? [] : Site::query()
                ->whereIn('id', $siteIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Site $site) => ['id' => (int) $site->id, 'name' => (string) $site->name])
                ->all(),
            'can_leave_site_empty' => $user->canDo('reports.viewAny'),
        ];
    }

    /**
     * Actual spend counts as recorded once someone records it — or, for
     * budgets from before that was tracked, when any line has spend entered.
     */
    private function actualsRecorded(Budget $budget): bool
    {
        if ($budget->actuals_recorded_at !== null) {
            return true;
        }

        return $budget->relationLoaded('lineItems')
            ? $budget->lineItems->contains(fn ($line) => (float) $line->actual_amount !== 0.0)
            : $budget->lineItems()->where('actual_amount', '!=', 0)->exists();
    }

    private function backWithFirstError(ValidationException $exception)
    {
        $message = collect($exception->errors())->flatten()->first() ?? 'That could not be done. Refresh the page and try again.';

        return redirect()->back()
            ->withErrors($exception->errors())
            ->with('error', $message);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        return (string) (config('app.worker_timezone') ?: GovernanceLabels::TIMEZONE);
    }

    private function financialYearEnd(CarbonImmutable $date): int
    {
        return $date->month >= 7 ? $date->year + 1 : $date->year;
    }

    private function validInteger(mixed $value): ?int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return $validated === false ? null : $validated;
    }
}
