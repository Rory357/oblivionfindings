<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Models\HrApprovalInstance;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrLeaveApprovalChain;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Services\ApprovalWorkflowService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Domain\Hr\Services\HrRecruitmentAccessService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $workflowService,
        private readonly HrCurrentStaffService $currentStaff,
        private readonly HrPerformanceAccessService $performanceAccess,
        private readonly HrRecruitmentAccessService $recruitmentAccess,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Chains — manage approval chain configurations */
    /* ------------------------------------------------------------------ */

    public function chains(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.approvals.manage'), 403);

        $chains = HrApprovalChain::query()
            ->with(['steps', 'creator:id,name'])
            ->withCount('instances')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($chain) => [
                'id' => $chain->id,
                'name' => $chain->name,
                'process_type' => $chain->process_type,
                'is_active' => $chain->is_active,
                'steps_count' => $chain->steps->count(),
                'instances_count' => $chain->instances_count,
                'created_by' => $chain->creator?->name ?? 'Unknown',
                'steps' => $chain->steps->map(fn ($step) => [
                    'id' => $step->id,
                    'step_order' => $step->step_order,
                    'approver_type' => $step->approver_type,
                    'approver_role_id' => $step->approver_role_id,
                    'approver_user_id' => $step->approver_user_id,
                    'auto_approve_after_days' => $step->auto_approve_after_days,
                ]),
                'created_at' => $chain->created_at?->toDateString(),
            ]);

        $leaveChains = HrLeaveApprovalChain::query()
            ->with(['user:id,name', 'approver:id,name', 'delegate:id,name'])
            ->orderBy('user_id')
            ->orderBy('approval_level')
            ->get()
            ->map(fn (HrLeaveApprovalChain $chain) => [
                'id' => $chain->id,
                'user_id' => $chain->user_id,
                'user_name' => $chain->user?->name ?? 'Unknown employee',
                'approver_user_id' => $chain->approver_user_id,
                'approver_name' => $chain->approver?->name ?? 'Unknown approver',
                'delegate_user_id' => $chain->delegate_user_id,
                'delegate_name' => $chain->delegate?->name,
                'approval_level' => $chain->approval_level,
                'escalation_after_hours' => $chain->escalation_after_hours,
                'is_active' => $chain->is_active,
            ]);

        $roles = Role::orderBy('name')->get(['id', 'name']);
        $users = $this->currentStaff->currentUsersQuery()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('hr/approvals/chains', [
            'chains' => $chains,
            'leaveChains' => $leaveChains,
            'processTypes' => ['leave', 'expense', 'timesheet', 'document'],
            'roles' => $roles,
            'users' => $users,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Chain — create new approval chain with steps */
    /* ------------------------------------------------------------------ */

    public function storeChain(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.approvals.manage'), 403);
        $currentUserIds = $this->currentStaff->currentUserIds();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('hr_approval_chains', 'name')->where(
                    fn ($query) => $query->where('process_type', $request->string('process_type')->toString()),
                ),
            ],
            'process_type' => ['required', 'string', Rule::in(['leave', 'expense', 'timesheet', 'document'])],
            'is_active' => ['boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.step_order' => ['required', 'integer', 'min:1', 'distinct'],
            'steps.*.approver_type' => ['required', 'string', Rule::in(['manager', 'role', 'user'])],
            'steps.*.approver_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'steps.*.approver_user_id' => ['nullable', 'integer', Rule::in($currentUserIds)],
            'steps.*.auto_approve_after_days' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach ($validated['steps'] as $stepData) {
            $hasRole = ! empty($stepData['approver_role_id']);
            $hasUser = ! empty($stepData['approver_user_id']);
            if (($stepData['approver_type'] === 'role' && (! $hasRole || $hasUser))
                || ($stepData['approver_type'] === 'user' && (! $hasUser || $hasRole))
                || ($stepData['approver_type'] === 'manager' && ($hasRole || $hasUser))) {
                throw ValidationException::withMessages([
                    'steps' => 'Each step must configure only the approver required by its type.',
                ]);
            }
        }

        DB::transaction(function () use ($validated, $user): void {
            $chain = HrApprovalChain::query()->create([
                'name' => $validated['name'],
                'process_type' => $validated['process_type'],
                'is_active' => $validated['is_active'] ?? true,
                'created_by' => $user->id,
            ]);

            foreach (collect($validated['steps'])->sortBy('step_order') as $stepData) {
                $chain->steps()->create([
                    'step_order' => $stepData['step_order'],
                    'approver_type' => $stepData['approver_type'],
                    'approver_role_id' => $stepData['approver_role_id'] ?? null,
                    'approver_user_id' => $stepData['approver_user_id'] ?? null,
                    'auto_approve_after_days' => $stepData['auto_approve_after_days'] ?? null,
                    'created_at' => now(),
                ]);
            }
        });

        return redirect()->route('hr.approvals.chains')->with('success', 'Approval chain created.');
    }

    public function storeLeaveChain(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.approvals.manage'), 403);
        $allowedUserIds = $this->currentStaff->currentUserIds();

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($allowedUserIds)],
            'approver_user_id' => ['required', 'integer', Rule::in($allowedUserIds), 'different:user_id'],
            'delegate_user_id' => ['nullable', 'integer', Rule::in($allowedUserIds), 'different:user_id', 'different:approver_user_id'],
            'approval_level' => [
                'required', 'integer', 'min:1',
                Rule::unique('hr_leave_approval_chains', 'approval_level')->where(fn ($query) => $query
                    ->where('user_id', $request->integer('user_id'))),
            ],
            'escalation_after_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        HrLeaveApprovalChain::query()->create([
            ...$validated,
            'escalation_after_hours' => $validated['escalation_after_hours'] ?? 48,
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return redirect()->back()->with('success', 'Leave approval route added.');
    }

    public function updateLeaveChain(Request $request, HrLeaveApprovalChain $leaveChain)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.approvals.manage'), 403);
        $allowedUserIds = $this->currentStaff->currentUserIds();

        $validated = $request->validate([
            'approver_user_id' => ['required', 'integer', Rule::in($allowedUserIds)],
            'delegate_user_id' => ['nullable', 'integer', Rule::in($allowedUserIds), 'different:approver_user_id'],
            'escalation_after_hours' => ['required', 'integer', 'min:1', 'max:8760'],
        ]);

        if (in_array((int) $validated['approver_user_id'], [(int) $leaveChain->user_id], true)
            || (! empty($validated['delegate_user_id']) && (int) $validated['delegate_user_id'] === (int) $leaveChain->user_id)) {
            throw ValidationException::withMessages([
                'approver_user_id' => 'The employee cannot approve or delegate their own leave route.',
            ]);
        }

        $leaveChain->update([...$validated, 'updated_by' => $actor->id]);

        return redirect()->back()->with('success', 'Leave approval route updated.');
    }

    public function reorderLeaveChains(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.approvals.manage'), 403);
        $allowedUserIds = $this->currentStaff->currentUserIds();

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($allowedUserIds)],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer'],
        ]);
        $routes = HrLeaveApprovalChain::query()
            ->where('user_id', $validated['user_id'])
            ->orderBy('approval_level')
            ->get();
        $expectedIds = $routes->pluck('id')->sort()->values()->all();
        $submittedIds = collect($validated['ordered_ids'])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($expectedIds !== $submittedIds) {
            return redirect()->back()->withErrors(['ordered_ids' => 'Submit every leave approval level exactly once.']);
        }

        DB::transaction(function () use ($routes, $validated, $actor): void {
            foreach ($routes as $offset => $route) {
                $route->updateQuietly(['approval_level' => 100000 + $offset]);
            }
            foreach ($validated['ordered_ids'] as $index => $id) {
                $route = $routes->firstWhere('id', (int) $id);
                $route->update(['approval_level' => $index + 1, 'updated_by' => $actor->id]);
            }
        });

        return redirect()->back()->with('success', 'Leave approval levels reordered.');
    }

    public function setLeaveChainActive(Request $request, HrLeaveApprovalChain $leaveChain)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.approvals.manage'), 403);
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $leaveChain->update(['is_active' => $validated['is_active'], 'updated_by' => $actor->id]);

        return redirect()->back()->with('success', 'Leave approval route status updated.');
    }

    public function destroyLeaveChain(Request $request, HrLeaveApprovalChain $leaveChain)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.approvals.manage'), 403);
        $leaveChain->delete();

        return redirect()->back()->with('success', 'Leave approval route removed.');
    }

    /* ------------------------------------------------------------------ */
    /*  Pending — list pending approvals for current user */
    /* ------------------------------------------------------------------ */

    public function pending(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.approvals.view'), 403);

        $currentApproverInstanceIds = HrApprovalInstance::query()
            ->pending()
            ->with('chain.steps')
            ->get()
            ->filter(fn (HrApprovalInstance $instance) => $this->workflowService->isCurrentApprover($instance, $user))
            ->pluck('id');

        $instances = HrApprovalInstance::query()
            ->whereKey($currentApproverInstanceIds)
            ->pending()
            ->with(['chain.steps', 'initiator:id,name', 'actions'])
            ->orderByDesc('initiated_at')
            ->paginate(20)
            ->withQueryString();

        $instances->through(fn ($instance) => [
            'id' => $instance->id,
            'process_type' => $instance->chain?->process_type ?? 'unknown',
            'chain_name' => $instance->chain?->name ?? 'Unknown',
            'approvable_type' => class_basename($instance->approvable_type),
            'approvable_id' => $instance->approvable_id,
            'item_label' => match ($instance->chain?->process_type) {
                'leave' => 'Leave request',
                'expense' => 'Expense claim',
                'timesheet' => 'Timesheet',
                'document' => 'Document',
                default => 'Approval item',
            }.' #'.$instance->approvable_id,
            'current_step' => $instance->current_step,
            'total_steps' => $instance->chain?->steps?->count() ?? 0,
            'status' => $instance->status,
            'initiated_by' => $instance->initiator?->name ?? 'Unknown',
            'initiated_at' => $instance->initiated_at?->toIso8601String(),
            'actions_count' => $instance->actions->count(),
        ]);

        $nativeApprovals = collect();

        if ($user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage')) {
            $nativeApprovals->push(...$this->performanceAccess
                ->applyHistoricalSubjectScope(HrLeaveRequest::query(), $user, 'user_id')
                ->pending()
                ->with('user:id,name')
                ->latest('submitted_at')
                ->limit(20)
                ->get()
                ->map(fn (HrLeaveRequest $leave) => [
                    'id' => $leave->id,
                    'type' => 'leave',
                    'title' => ucfirst(str_replace('_', ' ', $leave->leave_type)).' leave',
                    'requester' => $leave->user?->name ?? 'Unknown employee',
                    'summary' => "{$leave->hours_requested} hours requested",
                    'status' => $leave->status,
                    'submitted_at' => ($leave->submitted_at ?? $leave->created_at)?->toIso8601String(),
                    'url' => route('hr.leave.show', $leave, false),
                ]));
        }

        if ($user->canDo('hr.expenses.approve')) {
            $nativeApprovals->push(...$this->performanceAccess
                ->applyExpenseClaimScope(HrExpenseClaim::query(), $user)
                ->submitted()
                ->with('user:id,name')
                ->latest('submitted_at')
                ->limit(20)
                ->get()
                ->map(fn (HrExpenseClaim $claim) => [
                    'id' => $claim->id,
                    'type' => 'expense',
                    'title' => $claim->title,
                    'requester' => $claim->user?->name ?? 'Unknown employee',
                    'summary' => "{$claim->currency} {$claim->total_amount}",
                    'status' => $claim->status,
                    'submitted_at' => ($claim->submitted_at ?? $claim->created_at)?->toIso8601String(),
                    'url' => route('hr.compensation.expenses.show', $claim, false),
                ]));
        }

        if ($user->canDo('hr.recruitment.manage')) {
            $nativeApprovals->push(...$this->recruitmentAccess->visibleOffers($user)
                ->where('approval_status', 'pending_approval')
                ->with('application.candidate:id,first_name,last_name')
                ->latest('approval_requested_at')
                ->limit(20)
                ->get()
                ->map(fn (HrOffer $offer) => [
                    'id' => $offer->id,
                    'type' => 'offer',
                    'title' => $offer->position_title,
                    'requester' => $offer->application?->candidate?->full_name ?? 'Unknown candidate',
                    'summary' => 'Offer awaiting approval',
                    'status' => $offer->approval_status,
                    'submitted_at' => ($offer->approval_requested_at ?? $offer->created_at)?->toIso8601String(),
                    'url' => route('hr.recruitment.index', ['tab' => 'offers'], false),
                ]));

            $nativeApprovals->push(...$this->recruitmentAccess->visibleRequisitions($user)
                ->where('requires_approval', true)
                ->where('status', 'pending_approval')
                ->latest('updated_at')
                ->limit(20)
                ->get()
                ->map(fn (HrJobRequisition $requisition) => [
                    'id' => $requisition->id,
                    'type' => 'requisition',
                    'title' => $requisition->title,
                    'requester' => 'Recruitment',
                    'summary' => "{$requisition->openings} opening(s)",
                    'status' => $requisition->status,
                    'submitted_at' => $requisition->updated_at?->toIso8601String(),
                    'url' => route('hr.recruitment.index', ['tab' => 'requisitions'], false),
                ]));
        }

        $nativeApprovals = $nativeApprovals
            ->sortByDesc('submitted_at')
            ->take(50)
            ->values();

        return Inertia::render('hr/approvals/pending', [
            'instances' => $instances,
            'nativeApprovals' => $nativeApprovals,
            'can' => [
                'manage' => $user->canDo('hr.approvals.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Action — approve or reject an approval instance */
    /* ------------------------------------------------------------------ */

    public function action(Request $request, HrApprovalInstance $instance)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.approvals.view'), 403);
        $instance = HrApprovalInstance::query()
            ->with('chain.steps')
            ->findOrFail($instance->getKey());
        abort_unless($this->workflowService->isCurrentApprover($instance, $user), 404);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['approved', 'rejected', 'escalated'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->workflowService->processAction(
                $instance,
                $user,
                $validated['action'],
                $validated['notes'] ?? null,
            );
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $label = match ($validated['action']) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'escalated' => 'escalated',
        };

        return redirect()->back()->with('success', "Approval instance {$label}.");
    }
}
