<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\BudgetAllocation;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GovernanceNestedMutationService
{
    private const ALLOCATION_SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public const REALLOCATION_UNAVAILABLE = "Moving money between lines isn't available. Record a decrease on one line and an increase on the other.";

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly ExecutiveMeetingAccessService $executiveAccess,
    ) {}

    public function assertAgendaItemBound(
        User $actor,
        GovernanceMeeting $meeting,
        MeetingAgendaItem $item,
    ): void {
        $this->authorizeMeeting($actor, $meeting);
        if ($item->is_confidential) {
            abort_unless($this->executiveAccess->canManageConfidentialAgenda($actor, $meeting), 403);
        }
        $meeting->agendaItems()->whereKey($item->getKey())->firstOrFail();
    }

    public function addAgendaItem(User $actor, GovernanceMeeting $meeting, array $data): MeetingAgendaItem
    {
        $this->authorizeMeeting($actor, $meeting);
        if (! empty($data['is_confidential'])) {
            abort_unless($this->executiveAccess->canManageConfidentialAgenda($actor, $meeting), 403);
        }

        return DB::transaction(function () use ($actor, $meeting, $data): MeetingAgendaItem {
            $lockedMeeting = $this->lockMeeting($actor, (int) $meeting->getKey());
            $maxOrder = (int) ($lockedMeeting->agendaItems()->max('order') ?? 0);

            $item = $lockedMeeting->agendaItems()->create([
                ...$data,
                'order' => $maxOrder + 1,
            ]);

            $this->afterNestedMutation('agenda.created', $lockedMeeting, $item);

            return $item;
        }, 3);
    }

    public function updateAgendaItem(
        User $actor,
        GovernanceMeeting $meeting,
        MeetingAgendaItem $item,
        array $data,
    ): MeetingAgendaItem {
        $this->assertAgendaItemBound($actor, $meeting, $item);

        return DB::transaction(function () use ($actor, $meeting, $item, $data): MeetingAgendaItem {
            $lockedMeeting = $this->lockMeeting($actor, (int) $meeting->getKey());
            $lockedItem = $lockedMeeting->agendaItems()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedItem->update($data);

            if (array_key_exists('order', $data)) {
                $this->reorderAgendaItems($lockedMeeting);
            }

            $this->afterNestedMutation('agenda.updated', $lockedMeeting, $lockedItem);

            return $lockedItem->fresh();
        }, 3);
    }

    public function removeAgendaItem(
        User $actor,
        GovernanceMeeting $meeting,
        MeetingAgendaItem $item,
    ): void {
        $this->assertAgendaItemBound($actor, $meeting, $item);

        DB::transaction(function () use ($actor, $meeting, $item): void {
            $lockedMeeting = $this->lockMeeting($actor, (int) $meeting->getKey());
            $lockedItem = $lockedMeeting->agendaItems()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedItem->delete();
            $this->reorderAgendaItems($lockedMeeting);
            $this->afterNestedMutation('agenda.deleted', $lockedMeeting, $lockedItem);
        }, 3);
    }

    public function assertBudgetLineItemBound(
        User $actor,
        Budget $budget,
        BudgetLineItem|int $lineItem,
    ): void {
        $this->authorizeBudget($actor, $budget, 'update');
        $budget->lineItems()->whereKey($this->modelKey($lineItem))->firstOrFail();
    }

    public function assertBudgetLineItemMutable(
        User $actor,
        Budget $budget,
        BudgetLineItem|int $lineItem,
    ): void {
        $this->authorizeBudgetStructure($actor, $budget);
        $budget->lineItems()->whereKey($this->modelKey($lineItem))->firstOrFail();
    }

    public function assertBudgetStructureMutable(User $actor, Budget $budget): void
    {
        $this->authorizeBudgetStructure($actor, $budget);
    }

    /**
     * @param  array<int, int|string>  $lineItemIds
     */
    public function assertBudgetLineItemsBound(User $actor, Budget $budget, array $lineItemIds): void
    {
        $this->authorizeBudget($actor, $budget, 'update');
        $ids = $this->normalizeIds($lineItemIds);

        if ($ids === []) {
            return;
        }

        abort_unless(
            $budget->lineItems()->whereKey($ids)->count() === count($ids),
            404,
        );
    }

    public function storeBudgetLineItem(User $actor, Budget $budget, array $data): BudgetLineItem
    {
        $this->authorizeBudgetStructure($actor, $budget);

        return DB::transaction(function () use ($actor, $budget, $data): BudgetLineItem {
            $lockedBudget = $this->lockBudgetStructure($actor, (int) $budget->getKey());
            $lineItem = $lockedBudget->lineItems()->create($data);

            $this->recalculateBudgetTotal($lockedBudget);
            $this->afterNestedMutation('budget_line.created', $lockedBudget, $lineItem);

            return $lineItem;
        }, 3);
    }

    public function updateBudgetLineItem(
        User $actor,
        Budget $budget,
        BudgetLineItem $lineItem,
        array $data,
    ): BudgetLineItem {
        $this->assertBudgetLineItemMutable($actor, $budget, $lineItem);

        return DB::transaction(function () use ($actor, $budget, $lineItem, $data): BudgetLineItem {
            $lockedBudget = $this->lockBudgetStructure($actor, (int) $budget->getKey());
            $lockedLine = $lockedBudget->lineItems()
                ->whereKey($lineItem->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedLine->update($data);
            $this->recalculateBudgetTotal($lockedBudget);
            $this->afterNestedMutation('budget_line.updated', $lockedBudget, $lockedLine);

            return $lockedLine->fresh();
        }, 3);
    }

    public function destroyBudgetLineItem(
        User $actor,
        Budget $budget,
        BudgetLineItem $lineItem,
    ): void {
        $this->assertBudgetLineItemMutable($actor, $budget, $lineItem);

        DB::transaction(function () use ($actor, $budget, $lineItem): void {
            $lockedBudget = $this->lockBudgetStructure($actor, (int) $budget->getKey());
            $lockedLine = $lockedBudget->lineItems()
                ->whereKey($lineItem->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedLine->delete();
            $this->recalculateBudgetTotal($lockedBudget);
            $this->afterNestedMutation('budget_line.deleted', $lockedBudget, $lockedLine);
        }, 3);
    }

    public function assertBudgetAdjustmentBound(
        User $actor,
        Budget $budget,
        BudgetAdjustment $adjustment,
    ): void {
        $this->authorizeBudget($actor, $budget, 'approve');
        $budget->adjustments()->whereKey($adjustment->getKey())->firstOrFail();
    }

    /**
     * Request a change to one line of an APPROVED budget. Draft budgets and
     * budgets waiting for the board are edited directly instead.
     *
     * Moving money between two lines is not offered: it would be two linked
     * changes, and a resolution can only be linked to one record — so a board
     * decision could not approve both halves together. People record a
     * decrease on one line and an increase on the other.
     */
    public function requestBudgetAdjustment(User $actor, Budget $budget, array $data): BudgetAdjustment
    {
        $this->authorizeBudget($actor, $budget, 'update');

        if (($data['adjustment_type'] ?? '') === 'reallocate') {
            throw ValidationException::withMessages([
                'adjustment_type' => self::REALLOCATION_UNAVAILABLE,
            ]);
        }

        return DB::transaction(function () use ($actor, $budget, $data): BudgetAdjustment {
            $lockedBudget = $this->lockBudget($actor, (int) $budget->getKey(), 'update');
            $lineItemId = $data['budget_line_item_id'] ?? null;

            if (! $lockedBudget->isApproved()) {
                throw ValidationException::withMessages([
                    'budget' => 'Budget changes are only for approved budgets. While a budget is a draft or waiting for the board, edit its lines instead.',
                ]);
            }

            if (! $lineItemId) {
                throw ValidationException::withMessages([
                    'budget_line_item_id' => 'Choose which budget line this change applies to.',
                ]);
            }

            $lockedLine = $lockedBudget->lineItems()
                ->whereKey((int) $lineItemId)
                ->lockForUpdate()
                ->firstOrFail();
            $needsBoardApproval = $lockedBudget->requiresBoardApproval((float) $data['amount']);

            $approvalResolutionId = isset($data['approval_resolution_id']) && $data['approval_resolution_id'] !== null && $data['approval_resolution_id'] !== ''
                ? (int) $data['approval_resolution_id']
                : null;

            if ($approvalResolutionId !== null) {
                $resolution = Resolution::whereKey($approvalResolutionId)->first();
                if (! $resolution) {
                    throw ValidationException::withMessages([
                        'approval_resolution_id' => 'That resolution no longer exists.',
                    ]);
                }
            }

            if (($data['adjustment_type'] ?? '') === 'decrease' && (float) $data['amount'] > (float) $lockedLine->budget_amount) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        "%s only has %s budgeted, so it can't go down by %s.",
                        $lockedLine->description,
                        GovernanceLabels::money($lockedLine->budget_amount),
                        GovernanceLabels::money($data['amount']),
                    ),
                ]);
            }

            $adjustment = $lockedBudget->adjustments()->create([
                'budget_line_item_id' => $lockedLine->getKey(),
                'adjustment_type' => $data['adjustment_type'],
                'amount' => $data['amount'],
                'reason' => $data['reason'],
                'proposed_by' => $actor->getKey(),
                'proposed_at' => now(),
                'status' => 'submitted',
                'threshold_applies' => $needsBoardApproval,
                'approval_resolution_id' => $approvalResolutionId,
            ]);

            $this->afterNestedMutation('budget_adjustment.requested', $lockedBudget, $adjustment);

            return $adjustment;
        }, 3);
    }

    public function approveBudgetAdjustment(
        User $actor,
        Budget $budget,
        BudgetAdjustment $adjustment,
        ?int $approvalResolutionId = null,
    ): BudgetAdjustment {
        $this->assertBudgetAdjustmentBound($actor, $budget, $adjustment);

        return DB::transaction(function () use ($actor, $budget, $adjustment, $approvalResolutionId): BudgetAdjustment {
            $lockedBudget = $this->lockBudget($actor, (int) $budget->getKey(), 'approve');
            $lockedAdjustment = $lockedBudget->adjustments()
                ->whereKey($adjustment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAdjustment->status === 'approved') {
                return $lockedAdjustment;
            }

            $this->assertSubmittedAdjustment($lockedAdjustment);

            if ($lockedBudget->isProposed()) {
                throw ValidationException::withMessages([
                    'adjustment' => "This budget is waiting for the board's vote, so its figures can't change now. Decide this change after the vote.",
                ]);
            }

            $thresholdApplies = (bool) ($lockedAdjustment->threshold_applies
                ?? $lockedBudget->requiresBoardApproval((float) $lockedAdjustment->amount));

            $resolutionId = $approvalResolutionId ?? $lockedAdjustment->approval_resolution_id;
            if ($thresholdApplies && ! $resolutionId) {
                $this->rejectResolution("This change needs a board decision. Once a resolution approving it has passed, record the board's approval.");
            }

            $resolution = null;
            if ($resolutionId) {

                $resolution = Resolution::whereKey($resolutionId)->lockForUpdate()->first();
                if (! $resolution) {
                    $this->rejectResolution('That resolution no longer exists.');
                }

                if (! in_array($resolution->status, Budget::PASSED_RESOLUTION_STATUSES, true) || $resolution->outcome !== 'carried') {
                    $this->rejectResolution("This resolution can't be used yet — voting must be finished and the result recorded as passed.");
                }

                if (! isset($resolution->cost_impact['amount']) || ! is_numeric($resolution->cost_impact['amount'])) {
                    $this->rejectResolution("The resolution doesn't state the dollar amount approved. Ask the secretary to add it to the resolution's cost section.");
                }

                $resolutionAmount = (float) $resolution->cost_impact['amount'];
                $adjustmentAmount = (float) $lockedAdjustment->amount;
                if (abs($resolutionAmount - $adjustmentAmount) > 0.009) {
                    $this->rejectResolution(sprintf(
                        'The board approved %s but this change is for %s.',
                        GovernanceLabels::money($resolutionAmount, true),
                        GovernanceLabels::money($adjustmentAmount, true),
                    ));
                }

                $alreadyUsed = BudgetAdjustment::query()
                    ->where('approval_resolution_id', $resolution->id)
                    ->where('status', 'approved')
                    ->where('id', '!=', $lockedAdjustment->id)
                    ->exists();

                if ($alreadyUsed) {
                    $this->rejectResolution('This resolution has already been used to approve another budget change.');
                }

                $lockedLine = $lockedBudget->lineItems()
                    ->whereKey((int) $lockedAdjustment->budget_line_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Authority is only the explicit binding created while the paper was
                // a draft: exact adjustment, budget, line, direction, amount and a
                // fingerprint of the terms voted on. Motion wording, titles, reasons,
                // line descriptions and JSON hints never confer authority.
                $this->assertBoundAdjustmentAuthority($actor, $resolution, $lockedBudget, $lockedLine, $lockedAdjustment);
            } else {
                $lockedLine = $lockedBudget->lineItems()
                    ->whereKey((int) $lockedAdjustment->budget_line_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $nextAmount = $this->adjustedLineAmount($lockedLine, $lockedAdjustment);

            $lockedLine->update(['budget_amount' => $nextAmount]);
            $lockedAdjustment->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $actor->getKey(),
                'approval_resolution_id' => $resolution?->id ?? $lockedAdjustment->approval_resolution_id,
            ]);
            $this->recalculateBudgetTotal($lockedBudget);
            $this->afterNestedMutation('budget_adjustment.approved', $lockedBudget, $lockedAdjustment);

            return $lockedAdjustment->fresh();
        }, 3);
    }

    public function rejectBudgetAdjustment(
        User $actor,
        Budget $budget,
        BudgetAdjustment $adjustment,
        string $reviewNotes,
    ): BudgetAdjustment {
        $this->assertBudgetAdjustmentBound($actor, $budget, $adjustment);

        return DB::transaction(function () use ($actor, $budget, $adjustment, $reviewNotes): BudgetAdjustment {
            $lockedBudget = $this->lockBudget($actor, (int) $budget->getKey(), 'approve');
            $lockedAdjustment = $lockedBudget->adjustments()
                ->whereKey($adjustment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedAdjustment->status === 'rejected'
                && (int) $lockedAdjustment->approved_by === (int) $actor->getKey()
                && hash_equals((string) $lockedAdjustment->review_notes, $reviewNotes)
            ) {
                return $lockedAdjustment;
            }

            $this->assertSubmittedAdjustment($lockedAdjustment);
            $lockedAdjustment->update([
                'status' => 'rejected',
                'review_notes' => $reviewNotes,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ]);
            $this->afterNestedMutation('budget_adjustment.rejected', $lockedBudget, $lockedAdjustment);

            return $lockedAdjustment->fresh();
        }, 3);
    }

    /**
     * @param  array<int, array{id: int|string, actual_amount: int|float|string}>  $actuals
     */
    public function recordBudgetActuals(User $actor, Budget $budget, array $actuals): void
    {
        $ids = array_column($actuals, 'id');
        $this->assertBudgetLineItemsBound($actor, $budget, $ids);
        $normalizedIds = $this->normalizeIds($ids);

        if (count($normalizedIds) !== count($ids)) {
            throw ValidationException::withMessages([
                'actuals' => 'Each budget line can only be listed once.',
            ]);
        }

        DB::transaction(function () use ($actor, $budget, $actuals, $normalizedIds): void {
            $lockedBudget = $this->lockBudget($actor, (int) $budget->getKey(), 'update');
            $lockedLines = $lockedBudget->lineItems()
                ->whereKey($normalizedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (BudgetLineItem $line): int => (int) $line->getKey());

            abort_unless($lockedLines->count() === count($normalizedIds), 404);

            foreach ($actuals as $actual) {
                $lockedLines->get((int) $actual['id'])->update([
                    'actual_amount' => $actual['actual_amount'],
                ]);
            }

            // Lets the page say "Actuals last recorded …" rather than showing
            // $0 as if nothing had been spent.
            $lockedBudget->update(['actuals_recorded_at' => now()]);

            $this->afterNestedMutation('budget_actuals.recorded', $lockedBudget);
        }, 3);
    }

    public function assertBudgetAllocationBoundAndAccessible(
        User $actor,
        Budget $budget,
        BudgetAllocation $allocation,
    ): void {
        $this->authorizeBudget($actor, $budget, 'update');
        $boundAllocation = $budget->allocations()->whereKey($allocation->getKey())->firstOrFail();
        $this->assertAllocationSiteAccess($actor, $boundAllocation->site_id);
    }

    public function assertBudgetAllocationSiteAccessible(
        User $actor,
        Budget $budget,
        ?int $siteId,
    ): void {
        $this->authorizeBudget($actor, $budget, 'update');
        $this->assertAllocationSiteAccess($actor, $siteId);
    }

    public function storeBudgetAllocation(User $actor, Budget $budget, array $data): BudgetAllocation
    {
        $this->authorizeBudget($actor, $budget, 'update');
        $this->assertAllocationSiteAccess($actor, $data['site_id'] ?? null);

        return DB::transaction(function () use ($actor, $budget, $data): BudgetAllocation {
            $lockedBudget = $this->lockBudget($actor, (int) $budget->getKey(), 'update');
            $this->assertAllocationSiteAccess($actor, $data['site_id'] ?? null);

            if ($lineItemId = ($data['budget_line_item_id'] ?? null)) {
                $lockedBudget->lineItems()->whereKey((int) $lineItemId)->lockForUpdate()->firstOrFail();
            }

            $allocation = $lockedBudget->allocations()->create([
                ...$data,
                'created_by' => $actor->getKey(),
            ]);

            GovernanceAuditService::log('budget.allocation_created', 'BudgetAllocation', $allocation->id, [
                'budget_id' => $lockedBudget->id,
                'period' => $allocation->period_year_month,
                'amount' => $allocation->allocated_amount,
            ]);
            $this->afterNestedMutation('budget_allocation.created', $lockedBudget, $allocation);

            return $allocation;
        }, 3);
    }

    public function updateBudgetAllocation(
        User $actor,
        Budget $budget,
        BudgetAllocation $allocation,
        array $data,
    ): BudgetAllocation {
        $this->assertBudgetAllocationBoundAndAccessible($actor, $budget, $allocation);

        return DB::transaction(function () use ($actor, $budget, $allocation, $data): BudgetAllocation {
            $lockedBudget = $this->lockBudget($actor, (int) $budget->getKey(), 'update');
            $lockedAllocation = $lockedBudget->allocations()
                ->whereKey($allocation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertAllocationSiteAccess($actor, $lockedAllocation->site_id);
            $nextSiteId = array_key_exists('site_id', $data)
                ? ($data['site_id'] === null ? null : (int) $data['site_id'])
                : $lockedAllocation->site_id;
            $this->assertAllocationSiteAccess($actor, $nextSiteId);

            $lockedAllocation->update($data);
            $this->afterNestedMutation('budget_allocation.updated', $lockedBudget, $lockedAllocation);

            return $lockedAllocation->fresh();
        }, 3);
    }

    public function destroyBudgetAllocation(
        User $actor,
        Budget $budget,
        BudgetAllocation $allocation,
    ): void {
        $this->assertBudgetAllocationBoundAndAccessible($actor, $budget, $allocation);

        DB::transaction(function () use ($actor, $budget, $allocation): void {
            $lockedBudget = $this->lockBudget($actor, (int) $budget->getKey(), 'update');
            $lockedAllocation = $lockedBudget->allocations()
                ->whereKey($allocation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertAllocationSiteAccess($actor, $lockedAllocation->site_id);
            $lockedAllocation->delete();
            $this->afterNestedMutation('budget_allocation.deleted', $lockedBudget, $lockedAllocation);
        }, 3);
    }

    protected function afterNestedMutation(
        string $mutation,
        Model $parent,
        ?Model $child = null,
    ): void {}

    private function lockMeeting(User $actor, int $meetingId): GovernanceMeeting
    {
        $meeting = GovernanceMeeting::query()->whereKey($meetingId)->lockForUpdate()->firstOrFail();
        $this->authorizeMeeting($actor, $meeting);

        return $meeting;
    }

    private function lockBudget(User $actor, int $budgetId, string $ability): Budget
    {
        $budget = Budget::query()->whereKey($budgetId)->lockForUpdate()->firstOrFail();
        $this->authorizeBudget($actor, $budget, $ability);

        return $budget;
    }

    private function lockBudgetStructure(User $actor, int $budgetId): Budget
    {
        $budget = Budget::query()->whereKey($budgetId)->lockForUpdate()->firstOrFail();
        $this->authorizeBudgetStructure($actor, $budget);

        return $budget;
    }

    private function authorizeMeeting(User $actor, GovernanceMeeting $meeting): void
    {
        Gate::forUser($actor)->authorize('update', $meeting);
    }

    private function authorizeBudget(User $actor, Budget $budget, string $ability): void
    {
        Gate::forUser($actor)->authorize($ability, $budget);
    }

    private function authorizeBudgetStructure(User $actor, Budget $budget): void
    {
        $this->authorizeBudget($actor, $budget, 'update');
        abort_unless($budget->isDrafting() || $budget->isProposed(), 403);
    }

    private function reorderAgendaItems(GovernanceMeeting $meeting): void
    {
        $items = $meeting->agendaItems()
            ->orderBy('order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($items as $index => $item) {
            $nextOrder = $index + 1;
            if ((int) $item->order !== $nextOrder) {
                $item->update(['order' => $nextOrder]);
            }
        }
    }

    private function recalculateBudgetTotal(Budget $budget): void
    {
        $budget->update([
            'total_budget' => $budget->lineItems()->sum('budget_amount'),
        ]);
    }

    private function adjustedLineAmount(
        BudgetLineItem $lineItem,
        BudgetAdjustment $adjustment,
    ): string {
        $current = (float) $lineItem->budget_amount;
        $amount = (float) $adjustment->amount;
        $next = match ($adjustment->adjustment_type) {
            'increase' => $current + $amount,
            'decrease' => $current - $amount,
            'reallocate' => throw ValidationException::withMessages([
                'adjustment' => self::REALLOCATION_UNAVAILABLE,
            ]),
            default => throw ValidationException::withMessages([
                'adjustment' => 'Budget changes can only increase or decrease a line.',
            ]),
        };

        if ($next < 0) {
            throw ValidationException::withMessages([
                'adjustment' => sprintf(
                    "This change would take %s below \$0, so it can't be approved.",
                    $lineItem->description ?: 'the budget line',
                ),
            ]);
        }

        return number_format($next, 2, '.', '');
    }

    private function assertBoundAdjustmentAuthority(
        User $actor,
        Resolution $lockedResolution,
        Budget $lockedBudget,
        BudgetLineItem $lockedLine,
        BudgetAdjustment $lockedAdjustment,
    ): void {
        $authority = app(GovernanceResolutionAuthorityService::class);

        $binding = GovernanceResolutionBinding::query()
            ->where('resolution_id', $lockedResolution->getKey())
            ->where('subject_type', GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT)
            ->where('subject_id', $lockedAdjustment->getKey())
            ->lockForUpdate()
            ->first();

        if ($binding) {
            if ((int) $binding->budget_id !== (int) $lockedBudget->getKey()
                || (int) $binding->budget_line_item_id !== (int) $lockedLine->getKey()) {
                $this->rejectResolution('The resolution was linked to a different budget line. Prepare a new resolution for this change.');
            }

            if ((string) $binding->direction !== (string) $lockedAdjustment->adjustment_type) {
                $this->rejectResolution(sprintf(
                    'The resolution approved %s, but this change is now %s. Prepare a new resolution for the change as it is now.',
                    self::directionPhrase((string) $binding->direction),
                    self::directionPhrase((string) $lockedAdjustment->adjustment_type),
                ));
            }

            if (GovernanceResolutionAuthorityService::money($binding->amount) !== GovernanceResolutionAuthorityService::money($lockedAdjustment->amount)) {
                $this->rejectResolution(sprintf(
                    'The board approved %s but this change is for %s.',
                    GovernanceLabels::money($binding->amount, true),
                    GovernanceLabels::money($lockedAdjustment->amount, true),
                ));
            }
        }

        try {
            $authority->verifyAndConsume(
                $lockedResolution,
                GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT,
                (int) $lockedAdjustment->getKey(),
                $authority->budgetAdjustmentTerms($lockedAdjustment),
                (int) $actor->getKey(),
            );
        } catch (\DomainException $exception) {
            $this->rejectResolution($exception->getMessage());
        }
    }

    /** The resolution cannot approve this budget change — say why. */
    private function rejectResolution(string $message): never
    {
        throw ValidationException::withMessages([
            'approval_resolution' => $message,
            'approval_resolution_id' => $message,
        ]);
    }

    /** "an increase" / "a decrease". */
    private static function directionPhrase(string $direction): string
    {
        return match ($direction) {
            'increase' => 'an increase',
            'decrease' => 'a decrease',
            default => 'moving money between lines',
        };
    }

    private function assertSubmittedAdjustment(BudgetAdjustment $adjustment): void
    {
        if ($adjustment->status !== 'submitted') {
            throw ValidationException::withMessages([
                'adjustment' => 'This change has already been approved or declined.',
            ]);
        }
    }

    private function assertAllocationSiteAccess(User $actor, ?int $siteId): void
    {
        if ($siteId === null) {
            // An organisation-wide amount (no site) needs organisation-wide
            // reporting access; everyone else picks one of their sites.
            if (! $actor->canDo('reports.viewAny')) {
                throw ValidationException::withMessages([
                    'site_id' => 'Choose the site this monthly amount is for.',
                ]);
            }

            return;
        }

        abort_unless(
            in_array(
                $siteId,
                $this->siteAccess->accessibleSiteIds(
                    $actor,
                    self::ALLOCATION_SITE_BYPASS_PERMISSIONS,
                ),
                true,
            ),
            404,
        );
    }

    private function modelKey(Model|int $model): int
    {
        return $model instanceof Model ? (int) $model->getKey() : $model;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return Collection::make($ids)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
