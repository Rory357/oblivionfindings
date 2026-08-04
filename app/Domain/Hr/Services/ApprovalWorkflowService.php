<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrApprovalAction;
use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Models\HrApprovalChainStep;
use App\Domain\Hr\Models\HrApprovalInstance;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ApprovalWorkflowService
{
    public function __construct(
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /**
     * Initiate an approval workflow for a given approvable model.
     *
     * Looks up the active approval chain for the process type,
     * then creates an approval instance starting at step 1.
     *
     * @param  Model  $approvable  The model requiring approval (leave request, expense, etc.)
     * @param  string  $processType  One of: 'leave', 'expense', 'timesheet', 'document'
     * @param  User  $initiator  The user who initiated the approval
     *
     * @throws \LogicException If no active approval chain exists for the process type
     */
    public function initiateApproval(Model $approvable, string $processType, User $initiator): HrApprovalInstance
    {
        $chain = $this->getChainForProcess($processType);

        if (! $chain) {
            throw new \LogicException("No active approval chain found for process type '{$processType}'.");
        }

        return DB::transaction(function () use ($approvable, $chain, $initiator) {
            return HrApprovalInstance::create([
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
     * @param  string  $action  One of: 'approved', 'rejected', 'escalated'
     *
     * @throws \LogicException If instance is not pending
     */
    public function processAction(
        HrApprovalInstance $instance,
        User $approver,
        string $action,
        ?string $notes = null
    ): HrApprovalInstance {
        return DB::transaction(function () use ($instance, $approver, $action, $notes) {
            $instance = HrApprovalInstance::query()
                ->with(['chain.steps'])
                ->lockForUpdate()
                ->findOrFail($instance->getKey());

            $this->assertCurrentApprover($instance, $approver);

            if ($instance->status !== 'pending') {
                throw new \LogicException("Cannot action a '{$instance->status}' approval instance.");
            }

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
            'user' => $step->approver_user_id ? $this->currentStaffUser((int) $step->approver_user_id) : null,
            'role' => $this->getUserForRoleStep($step),
            'manager' => $this->getManagerForInitiator($instance),
            default => null,
        };
    }

    public function isCurrentApprover(HrApprovalInstance $instance, User $user): bool
    {
        if (! $this->currentStaffUser((int) $user->id)) {
            return false;
        }

        return (int) ($this->getCurrentApprover($instance)?->id ?? 0) === (int) $user->id;
    }

    public function assertCurrentApprover(HrApprovalInstance $instance, User $user): void
    {
        if ($this->isCurrentApprover($instance, $user)) {
            return;
        }

        throw (new ModelNotFoundException)->setModel(HrApprovalInstance::class, [$instance->getKey()]);
    }

    /**
     * Get the active approval chain for a given process type.
     */
    public function getChainForProcess(string $processType): ?HrApprovalChain
    {
        return HrApprovalChain::query()
            ->where('process_type', $processType)
            ->where('is_active', true)
            ->with('steps')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Get a user who holds the role configured on a step.
     */
    protected function getUserForRoleStep(HrApprovalChainStep $step): ?User
    {
        if (! $step->approver_role_id) {
            return null;
        }

        return $this->currentStaff->currentUsersQuery()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $step->approver_role_id))
            ->orderBy('users.id')
            ->first();
    }

    /**
     * Get the manager of the user who initiated the approval.
     */
    protected function getManagerForInitiator(HrApprovalInstance $instance): ?User
    {
        $profileQuery = HrEmployeeProfile::query()->where('user_id', $instance->initiated_by);
        $this->applyCurrentProfileScope($profileQuery);
        $managerId = $profileQuery->value('manager_user_id');

        if (! $managerId) {
            return null;
        }

        return $this->currentStaffUser((int) $managerId);
    }

    private function currentStaffUser(int $userId): ?User
    {
        return $this->currentStaff->currentUsersQuery()
            ->whereKey($userId)
            ->first();
    }

    private function applyCurrentProfileScope(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn (Builder $dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
    }
}
