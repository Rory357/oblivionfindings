<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Changes canonical workflow dates/responsibility without rewriting the original work. */
final class ItProvisioningWorkflowLifecycleService
{
    public function __construct(
        private readonly ItProvisioningAccessService $access,
        private readonly ItProvisioningResponsibilityService $responsibility,
        private readonly ItProvisioningRequestLifecycleService $requests,
    ) {}

    public function lockContext(ItProvisioningWorkflow $workflow, User $actor): array
    {
        $currentWorkflow = ItProvisioningWorkflow::query()->findOrFail($workflow->id);
        HrEmployeeProfile::query()->whereKey($currentWorkflow->employee_profile_id)->lockForUpdate()->firstOrFail();
        $locked = ItProvisioningWorkflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! $this->access->canManageWorkflow($current, $locked)) {
            throw new AuthorizationException('This workflow is outside your current provisioning responsibility.');
        }
        if (! app(ItProvisioningReadinessService::class)->storageReady()) {
            throw new DomainException('Complete the reviewed provisioning history database update before changing this workflow.');
        }

        return [$locked, $current];
    }

    public function change(ItProvisioningWorkflow $workflow, User $actor, string $operation, array $data): ItProvisioningWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $operation, $data): ItProvisioningWorkflow {
            [$workflow, $actor] = $this->lockContext($workflow, $actor);
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw new DomainException('Explain the change and the work that needs to follow.');
            }
            match ($operation) {
                'assign' => $this->assign($workflow, $actor, $data, $reason),
                'reschedule' => $this->reschedule($workflow, $actor, $data, $reason),
                'cancel' => $this->cancel($workflow, $actor, $data, $reason),
                'reverse' => app(ItProvisioningReversalService::class)->reverse($workflow, $actor, $reason),
                'resume' => $this->resume($workflow, $actor, $reason),
                'request_approval' => $this->requestApproval($workflow, $actor, $data, $reason),
                'approve', 'reject' => $this->decide($workflow, $actor, $operation === 'approve' ? 'approved' : 'rejected', $reason),
                default => throw new DomainException('Unsupported provisioning workflow action.'),
            };

            return $workflow->refresh();
        }, 3);
    }

    /** Whole-workflow verdict for the workspace: which operations the actor may attempt now. */
    public function actions(ItProvisioningWorkflow $workflow, User $actor): array
    {
        if (! app(ItProvisioningReadinessService::class)->storageReady() || ! $this->access->canManageWorkflow($actor, $workflow)) {
            return [];
        }
        $originals = $workflow->requests()->whereNull('reversal_of_request_id')->get(['id', 'status', 'approval_required', 'approval_status', 'approval_requested_at', 'approval_expires_at', 'primary_approver_user_id', 'cover_approver_user_id', 'approval_requested_by', 'created_by', 'employee_profile_id', 'provisioning_workflow_id']);
        $actions = [];
        if ($workflow->cancelled_at !== null) {
            if (! $workflow->requests()->whereNotNull('reversal_of_request_id')->where('status', '!=', 'cancelled')->exists()
                && $this->sourceAllowsResume($workflow)) {
                $actions[] = 'resume';
            }
            if ($originals->contains(fn ($task) => $task->status === 'done')
                && $originals->where('status', 'done')->count() > $workflow->requests()->whereNotNull('reversal_of_request_id')->count()) {
                $actions[] = 'reverse';
            }

            return $actions;
        }
        $actions[] = 'assign';
        if ($workflow->status !== 'completed' && ! in_array($workflow->source_type, ['hr_onboarding', 'hr_offboarding'], true)
            && $originals->contains(fn ($task) => ! in_array($task->status, ['done', 'cancelled'], true))) {
            $actions[] = 'reschedule';
        }
        $actions[] = 'cancel';
        if ($originals->contains(fn ($task) => $task->status === 'done')
            && $originals->where('status', 'done')->count() > $workflow->requests()->whereNotNull('reversal_of_request_id')->count()) {
            $actions[] = 'reverse';
        }
        if ($originals->contains(fn ($task) => $this->needsApprovalRequest($task))) {
            $actions[] = 'request_approval';
        }
        if ($originals->contains(fn ($task) => ($approver = $this->responsibility->approver($task)) && (int) $approver->id === (int) $actor->id)) {
            $actions[] = 'approve';
            $actions[] = 'reject';
        }

        return $actions;
    }

    private function assign(ItProvisioningWorkflow $workflow, User $actor, array $data, string $reason): void
    {
        [$owner, $cover] = $this->responsibility->pair(
            isset($data['owner_user_id']) ? (int) $data['owner_user_id'] : null,
            isset($data['cover_user_id']) ? (int) $data['cover_user_id'] : null,
            $workflow->site_id_snapshot ?? $workflow->employeeProfile?->primary_site_id,
        );
        $before = ['owner_user_id' => $workflow->owner_user_id, 'cover_user_id' => $workflow->cover_user_id];
        $workflow->update(['owner_user_id' => $owner->id, 'cover_user_id' => $cover->id]);
        $this->record($workflow, $actor, 'responsibility_changed', ['before' => $before,
            'owner_user_id' => $owner->id, 'cover_user_id' => $cover->id, 'reason' => $reason]);
    }

    private function reschedule(ItProvisioningWorkflow $workflow, User $actor, array $data, string $reason): void
    {
        if (in_array($workflow->source_type, ['hr_onboarding', 'hr_offboarding'], true)) {
            throw new DomainException('Update the effective date in the canonical HR profile or offboarding checklist. Its unfinished IT work will be updated in the same change.');
        }
        if ($workflow->cancelled_at || $workflow->status === 'completed') {
            throw new DomainException('Only an active workflow can change its effective date. Completed evidence keeps its recorded date.');
        }
        $effective = Carbon::parse($data['effective_date'])->startOfDay();
        $before = $workflow->effective_at;
        if (! $before) {
            throw new DomainException('The original effective date is unavailable. Repair the source before rescheduling.');
        }
        $unfinished = $workflow->requests()->whereNotIn('status', ['done', 'cancelled'])->whereNull('reversal_of_request_id')->lockForUpdate()->get();
        if ($unfinished->isEmpty()) {
            throw new DomainException('There are no unfinished original tasks to reschedule. Completed work and its corrective tasks keep their recorded dates.');
        }
        foreach ($unfinished as $request) {
            $offset = $request->due_offset_days;
            if ($offset === null && $request->due_date) {
                $offset = (int) $before->copy()->startOfDay()->diffInDays($request->due_date->copy()->startOfDay(), false);
            }
            $values = ['due_offset_days' => $offset, 'due_date' => $offset !== null ? $effective->copy()->addDays($offset)->toDateString() : null];
            if ($request->approval_required && in_array($request->approval_status, ['pending', 'approved'], true)) {
                $values['approval_status'] = 'cancelled';
                ItTicketEvent::record($request, 'approval_withdrawn', $actor->id, ['reason' => 'Effective date changed: '.$reason]);
            }
            $oldDate = $request->due_date?->toDateString();
            $request->update($values);
            ItTicketEvent::record($request, 'rescheduled', $actor->id, ['from' => $oldDate, 'to' => $values['due_date'], 'reason' => $reason]);
        }
        $workflow->update(['original_effective_at' => $workflow->original_effective_at ?? $before, 'effective_at' => $effective]);
        $this->record($workflow, $actor, 'rescheduled', ['from' => $before->toIso8601String(), 'to' => $effective->toIso8601String(), 'reason' => $reason]);
    }

    private function cancel(ItProvisioningWorkflow $workflow, User $actor, array $data, string $reason): void
    {
        if ($workflow->cancelled_at) {
            throw new DomainException('This workflow is already cancelled. Review its history and corrective work.');
        }
        foreach ($workflow->requests()->whereNotIn('status', ['done', 'cancelled'])->whereNull('reversal_of_request_id')->lockForUpdate()->get() as $request) {
            $this->requests->cancel($request, $actor, $reason);
        }
        $workflow->refresh()->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
        $this->record($workflow, $actor, 'cancelled', ['reason' => $reason, 'completed_work_retained' => $workflow->requests()->where('status', 'done')->count()]);
        if (! empty($data['create_reversals'])) {
            app(ItProvisioningReversalService::class)->reverse($workflow, $actor, $reason, allowExisting: true);
        }
    }

    /**
     * Explicit rule: a cancelled workflow resumes only while nothing has been
     * reversed. Cancelled originals reopen without approval; completed
     * originals keep their evidence and are never re-run.
     */
    private function resume(ItProvisioningWorkflow $workflow, User $actor, string $reason): void
    {
        if ($workflow->cancelled_at === null) {
            throw new DomainException('This workflow is not cancelled.');
        }
        if ($workflow->requests()->whereNotNull('reversal_of_request_id')->where('status', '!=', 'cancelled')->exists()) {
            throw new DomainException('Corrective work exists for this workflow. Cancel or complete that work first, or launch a new approved workflow.');
        }
        if (! $this->sourceAllowsResume($workflow)) {
            throw new DomainException('The source HR checklist is still cancelled or archived. Resume it in HR first; its IT work resumes in the same change.');
        }
        $reopened = [];
        foreach ($workflow->requests()->where('status', 'cancelled')->whereNull('reversal_of_request_id')->orderBy('id')->lockForUpdate()->get() as $task) {
            $task->update(['status' => 'pending', 'approval_status' => $task->approval_required ? 'cancelled' : 'not_required']);
            ItTicketEvent::record($task, 'reopened', $actor->id, ['reason' => $reason, 'source' => 'workflow_resumed']);
            $reopened[] = (int) $task->id;
        }
        $previousReason = $workflow->cancellation_reason;
        $workflow->update(['cancelled_at' => null, 'cancellation_reason' => null]);
        $this->record($workflow, $actor, 'resumed', ['reason' => $reason, 'reopened_request_ids' => $reopened,
            'previous_cancellation_reason' => $previousReason]);
        $this->requests->reconcileWorkflow($workflow);
    }

    private function requestApproval(ItProvisioningWorkflow $workflow, User $actor, array $data, string $reason): void
    {
        if ($workflow->cancelled_at) {
            throw new DomainException('A cancelled workflow cannot request approval. Resume it first.');
        }
        $deadline = isset($data['approval_expires_at']) ? Carbon::parse($data['approval_expires_at'])
            : (isset($data['approval_expires_on']) ? Carbon::createFromFormat('!Y-m-d', $data['approval_expires_on'], 'Pacific/Auckland')->endOfDay() : null);
        if (! $deadline) {
            throw new DomainException('Choose a future approval deadline.');
        }
        $eligible = $workflow->requests()->whereNull('reversal_of_request_id')->orderBy('stage')->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (ItProvisioningRequest $task) => $this->needsApprovalRequest($task));
        if ($eligible->isEmpty()) {
            throw new DomainException('No open task in this workflow is waiting for an approval request.');
        }
        $requested = [];
        foreach ($eligible as $task) {
            $this->requests->requestApproval($task, $actor, [
                'primary_approver_user_id' => $data['primary_approver_user_id'] ?? null,
                'cover_approver_user_id' => $data['cover_approver_user_id'] ?? null,
                'approval_expires_at' => $deadline->toIso8601String(), 'reason' => $reason, 'routing_basis' => 'workflow',
            ]);
            $requested[] = (int) $task->id;
        }
        $this->record($workflow, $actor, 'approval_requested', ['request_ids' => $requested, 'reason' => $reason,
            'primary_approver_user_id' => (int) ($data['primary_approver_user_id'] ?? 0), 'cover_approver_user_id' => (int) ($data['cover_approver_user_id'] ?? 0),
            'expires_at' => $deadline->toIso8601String()]);
    }

    private function decide(ItProvisioningWorkflow $workflow, User $actor, string $decision, string $reason): void
    {
        if ($workflow->cancelled_at) {
            throw new DomainException('A cancelled workflow has no approvals to decide.');
        }
        $mine = $workflow->requests()->whereNull('reversal_of_request_id')->orderBy('stage')->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (ItProvisioningRequest $task) => ! in_array($task->status, ['done', 'cancelled'], true)
                && ($approver = $this->responsibility->approver($task)) && (int) $approver->id === (int) $actor->id);
        if ($mine->isEmpty()) {
            throw new DomainException('You are not the currently responsible approver for any open task in this workflow.');
        }
        $decided = [];
        foreach ($mine as $task) {
            $this->requests->decide($task, $actor, $decision, $reason);
            $decided[] = (int) $task->id;
        }
        $this->record($workflow, $actor, $decision, ['request_ids' => $decided, 'decision_note' => $reason]);
    }

    private function needsApprovalRequest(ItProvisioningRequest $task): bool
    {
        return $task->approval_required && ! in_array($task->status, ['done', 'cancelled'], true)
            && $task->approval_status !== 'approved'
            && (! $task->approval_requested_at || $task->approval_status !== 'pending'
                || $task->approval_expires_at?->lessThanOrEqualTo(now()));
    }

    private function sourceAllowsResume(ItProvisioningWorkflow $workflow): bool
    {
        if (! in_array($workflow->source_type, ['hr_onboarding', 'hr_offboarding'], true)) {
            return true;
        }
        $model = $workflow->source_type === 'hr_onboarding' ? HrOnboardingChecklist::class : HrOffboardingChecklist::class;
        $source = $model::query()->whereKey($workflow->source_id)->where('employee_profile_id', $workflow->employee_profile_id)->first();

        return $source !== null && ! in_array($source->status, ['cancelled', 'archived'], true);
    }

    private function record(ItProvisioningWorkflow $workflow, User $actor, string $event, array $data): void
    {
        $workflow->events()->create(['type' => $event, 'actor_user_id' => $actor->id, 'payload' => $data]);
        AuditLogger::logOrFail('it.provisioning.workflow.'.$event, $workflow, ['actor_id' => $actor->id, ...$data]);
    }
}
