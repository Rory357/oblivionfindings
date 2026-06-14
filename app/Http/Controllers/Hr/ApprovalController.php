<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Models\HrApprovalInstance;
use App\Domain\Hr\Services\ApprovalWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ApprovalController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly ApprovalWorkflowService $workflowService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Chains — manage approval chain configurations                      */
    /* ------------------------------------------------------------------ */

    public function chains(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.approvals.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $chains = HrApprovalChain::forTenant($tenantId)
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

        $roles = \App\Models\Role::orderBy('name')->get(['id', 'name']);
        $users = \App\Models\User::orderBy('name')->get(['id', 'name']);

        return Inertia::render('hr/approvals/chains', [
            'chains' => $chains,
            'processTypes' => ['leave', 'expense', 'timesheet', 'document'],
            'roles' => $roles,
            'users' => $users,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Chain — create new approval chain with steps                  */
    /* ------------------------------------------------------------------ */

    public function storeChain(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.approvals.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'process_type' => ['required', 'string', Rule::in(['leave', 'expense', 'timesheet', 'document'])],
            'is_active' => ['boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.step_order' => ['required', 'integer', 'min:1'],
            'steps.*.approver_type' => ['required', 'string', Rule::in(['manager', 'role', 'user'])],
            'steps.*.approver_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'steps.*.approver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'steps.*.auto_approve_after_days' => ['nullable', 'integer', 'min:1'],
        ]);

        $chain = HrApprovalChain::create([
            'tenant_id' => $this->resolveHrTenantIdForUser($user),
            'name' => $validated['name'],
            'process_type' => $validated['process_type'],
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $user->id,
        ]);

        foreach ($validated['steps'] as $stepData) {
            $chain->steps()->create([
                'step_order' => $stepData['step_order'],
                'approver_type' => $stepData['approver_type'],
                'approver_role_id' => $stepData['approver_role_id'] ?? null,
                'approver_user_id' => $stepData['approver_user_id'] ?? null,
                'auto_approve_after_days' => $stepData['auto_approve_after_days'] ?? null,
                'created_at' => now(),
            ]);
        }

        return redirect()->route('hr.approvals.chains')->with('success', 'Approval chain created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Pending — list pending approvals for current user                   */
    /* ------------------------------------------------------------------ */

    public function pending(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.approvals.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $instances = HrApprovalInstance::forTenant($tenantId)
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
            'current_step' => $instance->current_step,
            'total_steps' => $instance->chain?->steps?->count() ?? 0,
            'status' => $instance->status,
            'initiated_by' => $instance->initiator?->name ?? 'Unknown',
            'initiated_at' => $instance->initiated_at?->toDateTimeString(),
            'actions_count' => $instance->actions->count(),
        ]);

        return Inertia::render('hr/approvals/pending', [
            'instances' => $instances,
            'can' => [
                'manage' => $user->canDo('hr.approvals.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Action — approve or reject an approval instance                     */
    /* ------------------------------------------------------------------ */

    public function action(Request $request, HrApprovalInstance $instance)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.approvals.view'), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $instance->tenant_id);

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
