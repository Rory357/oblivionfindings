<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrApprovalAction;
use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Models\HrApprovalChainStep;
use App\Domain\Hr\Models\HrApprovalInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ApprovalWorkflowService
{
    /**
     * Initiate an approval workflow for a given approvable model.
     *
     * Looks up the active approval chain for the process type and tenant,
     * then creates an approval instance starting at step 1.
     *
     * @param  Model   $approvable   The model requiring approval (leave request, expense, etc.)
     * @param  string  $processType  One of: 'leave', 'expense', 'timesheet', 'document'
     * @param  User    $initiator    The user who initiated the approval
     * @return HrApprovalInstance
     *
     * @throws \LogicException If no active approval chain exists for the process type
     */
    public function initiateApproval(Model $approvable, string $processType, User $initiator): HrApprovalInstance
    {
        $chain = $this->getChainForProcess($processType, $initiator->tenant_id);

        if (! $chain) {
            throw new \LogicException("No active approval chain found for process type '{$processType}'.");
        }

        return DB::transaction(function () use ($approvable, $chain, $initiator) {
            return HrApprovalInstance::create([
                'tenant_id' => $initiator->tenant_id,
                'approval_chain_id' => $chain->id,
                'approvable_type' => get_class($approvable),
                'approvable_id' => $approvable->getKey(),
                'current_step' => 1,
                'status' => 'pending',
                'initiated_by' => $initiator->id,
                'initiated_at' => now(),
            ]);
        });
    }

    /**
     * Process an approval or rejection action on an instance.
     *
     * Records the action, then advances the workflow:
     * - If approved and more steps remain, advance to the next step.
     * - If approved and this was the final step, mark instance as approved.
     * - If rejected, mark instance as rejected immediately.
     *
     * @param  HrApprovalInstance  $instance
     * @param  User                $approver
     * @param  string              $action   One of: 'approved', 'rejected', 'escalated'
     * @param  string|null         $notes
     * @return HrApprovalInstance
     *
     * @throws \LogicException If instance is not pending
     */
    public function processAction(
        HrApprovalInstance $instance,
        User $approver,
        string $action,
        ?string $notes = null
    ): HrApprovalInstance {
        if ($instance->status !== 'pending') {
            throw new \LogicException("Cannot action a '{$instance->status}' approval instance.");
        }

        return DB::transaction(function () use ($instance, $approver, $action, $notes) {
            // Record the action
            HrApprovalAction::create([
                'approval_instance_id' => $instance->id,
                'step_order' => $instance->current_step,
                'action' => $action,
                'actioned_by' => $approver->id,
                'notes' => $notes,
                'actioned_at' => now(),
            ]);

            if ($action === 'rejected') {
                $instance->update([
                    'status' => 'rejected',
                    'completed_at' => now(),
                ]);

                return $instance->fresh();
            }

            if ($action === 'escalated') {
                $instance->update([
                    'status' => 'escalated',
                ]);

                return $instance->fresh();
            }

            // Action is 'approved' — check if there are more steps
            $chain = $instance->chain()->with('steps')->first();
            $totalSteps = $chain ? $chain->steps->count() : 0;

            if ($instance->current_step >= $totalSteps) {
                // Final step — mark as fully approved
                $instance->update([
                    'status' => 'approved',
                    'completed_at' => now(),
                ]);
            } else {
                // Advance to next step
                $instance->update([
                    'current_step' => $instance->current_step + 1,
                ]);
            }

            return $instance->fresh();
        });
    }

    /**
     * Determine the current approver for an approval instance.
     *
     * Based on the current step's approver_type:
     * - 'user': returns the specific user configured on the step
     * - 'role': returns the first user with the configured role
     * - 'manager': returns the initiator's manager (if available)
     *
     * @param  HrApprovalInstance  $instance
     * @return User|null
     */
    public function getCurrentApprover(HrApprovalInstance $instance): ?User
    {
        $instance->loadMissing(['chain.steps']);

        $step = $instance->chain?->steps
            ->firstWhere('step_order', $instance->current_step);

        if (! $step) {
            return null;
        }

        return match ($step->approver_type) {
            'user' => $step->approver_user_id ? User::find($step->approver_user_id) : null,
            'role' => $this->getUserForRoleStep($step, $instance->tenant_id),
            'manager' => $this->getManagerForInitiator($instance),
            default => null,
        };
    }

    /**
     * Get the active approval chain for a given process type and tenant.
     *
     * @param  string    $processType
     * @param  int|null  $tenantId
     * @return HrApprovalChain|null
     */
    public function getChainForProcess(string $processType, ?int $tenantId): ?HrApprovalChain
    {
        return HrApprovalChain::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('process_type', $processType)
            ->where('is_active', true)
            ->with('steps')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Get a user who holds the role configured on a step.
     */
    protected function getUserForRoleStep(HrApprovalChainStep $step, ?int $tenantId): ?User
    {
        if (! $step->approver_role_id) {
            return null;
        }

        return User::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $step->approver_role_id))
            ->first();
    }

    /**
     * Get the manager of the user who initiated the approval.
     */
    protected function getManagerForInitiator(HrApprovalInstance $instance): ?User
    {
        $initiator = $instance->initiator ?? User::find($instance->initiated_by);

        if (! $initiator) {
            return null;
        }

        // Try to find a manager via the employee profile
        $profile = \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $initiator->id)->first();

        if ($profile && $profile->manager_user_id) {
            return User::find($profile->manager_user_id);
        }

        return null;
    }
}
