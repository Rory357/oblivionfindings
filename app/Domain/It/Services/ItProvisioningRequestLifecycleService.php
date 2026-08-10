<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Services\OnboardingService;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Models\AssetAssignment;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ItProvisioningRequestLifecycleService
{
    public function __construct(
        private readonly OnboardingService $onboardingService,
        private readonly DeviceAssignmentService $deviceAssignments,
        private readonly ItProvisioningAccessService $access,
    ) {}

    /**
     * @param  array{type: string, item: string, priority: string, due_date?: string|null, notes?: string|null}  $data
     */
    public function createManual(
        User $actor,
        HrEmployeeProfile $profile,
        ?User $assignee,
        array $data,
    ): ItProvisioningRequest {
        return DB::transaction(function () use ($actor, $profile, $assignee, $data): ItProvisioningRequest {
            $profile = HrEmployeeProfile::query()
                ->lockForUpdate()
                ->findOrFail($profile->getKey());
            if ($actor->approved_at === null
                || ! $actor->canDo('it.manage')
                || ! $this->access->canSelectProfile($actor, $profile)) {
                throw new AuthorizationException('The selected employee profile is not available to you.');
            }

            if ($assignee !== null) {
                $assignee = User::query()
                    ->whereNotNull('approved_at')
                    ->lockForUpdate()
                    ->findOrFail($assignee->getKey());
                if (! $this->access->canAssignAgentForProfile($assignee, $profile)) {
                    throw new AuthorizationException('The selected agent cannot manage this employee’s provisioning.');
                }
            }

            $request = ItProvisioningRequest::query()->create([
                'employee_profile_id' => $profile->id,
                'type' => $data['type'],
                'item' => $data['item'],
                'assigned_to_user_id' => $assignee?->id,
                'status' => $assignee ? 'in_progress' : 'pending',
                'priority' => $data['priority'],
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);
            ItTicketEvent::record($request, 'created', $actor->id, array_filter([
                'type' => $request->type,
                'assigned_to_user_id' => $assignee?->id,
                'source' => 'manual',
            ], fn (mixed $value): bool => $value !== null));
            AuditLogger::logOrFail('it.provisioning.request.created', $request, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'site_id' => $profile->primary_site_id,
                'type' => $request->type,
                'assignee_id' => $assignee?->id,
                'source' => 'manual',
            ]);

            return $request->refresh();
        });
    }

    public function assign(
        ItProvisioningRequest $request,
        User $actor,
        User $assignee,
        string $via = 'single',
    ): bool {
        return DB::transaction(function () use ($request, $actor, $assignee, $via): bool {
            $request = $this->lock($request);
            $this->guard($request, $actor);
            if (! $this->access->canAssignAgentForRequest($assignee, $request)) {
                throw new DomainException('The selected agent cannot manage this provisioning request.');
            }
            if (in_array($request->status, ['done', 'cancelled'], true)) {
                throw new DomainException('This request is closed — reopen it before reassigning.');
            }

            $status = in_array($request->status, ['pending', 'failed'], true)
                ? 'in_progress'
                : $request->status;
            $changed = (int) $request->assigned_to_user_id !== (int) $assignee->id
                || $request->status !== $status
                || $request->failure_reason !== null
                || $request->failed_at !== null;
            if (! $changed) {
                return false;
            }

            $request->forceFill([
                'assigned_to_user_id' => $assignee->id,
                'status' => $status,
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();
            $this->reconcileWorkflow($request->workflow);
            ItTicketEvent::record($request, 'assigned', $actor->id, array_filter([
                'to' => $assignee->id,
                'via' => $via !== 'single' ? $via : null,
            ], fn (mixed $value): bool => $value !== null));
            AuditLogger::logOrFail('it.provisioning.request.assigned', $request, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'workflow_id' => $request->provisioning_workflow_id,
                'assignee_id' => $assignee->id,
                'via' => $via,
            ]);

            return true;
        });
    }

    public function approve(ItProvisioningRequest $request, User $actor, ?string $decisionNote = null): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $decisionNote): ItProvisioningRequest {
            $request = $this->lock($request);
            $this->guard($request, $actor);
            if (! $request->approval_required) {
                throw new DomainException('This request does not require approval.');
            }
            if (in_array($request->status, ['done', 'cancelled'], true)) {
                throw new DomainException('This request is already settled.');
            }

            $request->forceFill([
                'approval_status' => 'approved',
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ])->save();
            ItTicketEvent::record($request, 'approved', $actor->id, array_filter([
                'decision_note' => $decisionNote,
            ]));
            AuditLogger::logOrFail('it.provisioning.request.approved', $request, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'workflow_id' => $request->provisioning_workflow_id,
            ]);

            return $request->refresh();
        });
    }

    /** @param array{external_ref?: string|null, notes?: string|null, evidence_summary?: string|null} $data */
    public function fulfil(ItProvisioningRequest $request, User $actor, array $data = []): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $data): ItProvisioningRequest {
            $request = $this->lock($request);
            $this->guard($request, $actor);
            if ($request->status === 'done') {
                throw new DomainException('This request is already fulfilled.');
            }
            if ($request->status === 'cancelled') {
                throw new DomainException('This request was cancelled — it can no longer be fulfilled.');
            }

            $dependencies = array_map('intval', $request->dependency_request_ids ?? []);
            if ($dependencies !== [] && ItProvisioningRequest::query()
                ->whereIn('id', $dependencies)
                ->where('status', '!=', 'done')
                ->exists()) {
                throw new DomainException('Complete this request’s dependencies first.');
            }
            if ($request->approval_required && $request->approval_status !== 'approved') {
                throw new DomainException('This request needs approval before fulfilment.');
            }

            $evidence = trim((string) ($data['evidence_summary'] ?? $request->evidence_summary ?? ''));
            if ($request->evidence_required && $evidence === '') {
                throw new DomainException('Record fulfilment evidence before completing this request.');
            }

            $this->reconcileCanonicalTarget($request, $actor);
            $request->forceFill([
                'status' => 'done',
                'external_ref' => $data['external_ref'] ?? $request->external_ref,
                'notes' => $data['notes'] ?? $request->notes,
                'evidence_summary' => $evidence !== '' ? $evidence : $request->evidence_summary,
                'failure_reason' => null,
                'failed_at' => null,
                'fulfilled_at' => now(),
                'fulfilled_by' => $actor->id,
            ])->save();

            $this->completeSourceTasksWhenReady($request, $actor);
            $this->reconcileWorkflow($request->workflow);
            ItTicketEvent::record($request, 'fulfilled', $actor->id, array_filter([
                'external_ref' => $data['external_ref'] ?? null,
                'evidence_recorded' => $evidence !== '',
                'action' => $request->action,
            ], fn ($value) => $value !== null));
            AuditLogger::logOrFail('it.provisioning.request.fulfilled', $request, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'workflow_id' => $request->provisioning_workflow_id,
                'action' => $request->action,
                'canonical_target_type' => $request->canonical_target_type,
                'canonical_target_id' => $request->canonical_target_id,
            ]);

            return $request->refresh();
        });
    }

    public function fail(ItProvisioningRequest $request, User $actor, string $reason): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): ItProvisioningRequest {
            $request = $this->lock($request);
            $this->guard($request, $actor);
            if (in_array($request->status, ['done', 'cancelled'], true)) {
                throw new DomainException('A settled request cannot be marked failed.');
            }

            $request->forceFill([
                'status' => 'failed',
                'failure_reason' => trim($reason),
                'failed_at' => now(),
            ])->save();
            $this->reconcileWorkflow($request->workflow);
            ItTicketEvent::record($request, 'failed', $actor->id, ['reason' => trim($reason)]);
            AuditLogger::logOrFail('it.provisioning.request.failed', $request, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'workflow_id' => $request->provisioning_workflow_id,
                'reason' => trim($reason),
            ]);

            return $request->refresh();
        });
    }

    public function cancel(ItProvisioningRequest $request, User $actor, string $reason): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): ItProvisioningRequest {
            $request = $this->lock($request);
            $this->guard($request, $actor);
            if ($request->status === 'done') {
                throw new DomainException('A fulfilled request cannot be cancelled.');
            }
            if ($request->status === 'cancelled') {
                throw new DomainException('This request is already cancelled.');
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw new DomainException('Record why this provisioning request is being cancelled.');
            }
            $request->forceFill(['status' => 'cancelled'])->save();
            $this->annotateOpenOnboardingTask($request, $reason);
            $this->reconcileWorkflow($request->workflow);
            ItTicketEvent::record($request, 'cancelled', $actor->id, [
                'reason' => $reason,
            ]);
            AuditLogger::logOrFail('it.provisioning.request.cancelled', $request, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'workflow_id' => $request->provisioning_workflow_id,
                'reason' => $reason,
                'onboarding_task_id' => $request->onboarding_task_id,
            ]);

            return $request->refresh();
        });
    }

    private function annotateOpenOnboardingTask(ItProvisioningRequest $request, string $reason): void
    {
        if (! $request->onboarding_task_id) {
            return;
        }

        $task = HrOnboardingTask::query()
            ->lockForUpdate()
            ->find($request->onboarding_task_id);
        if (! $task) {
            throw new DomainException('The linked onboarding task is unavailable. Repair the source link before cancelling.');
        }
        HrOnboardingChecklist::query()
            ->lockForUpdate()
            ->findOrFail($task->checklist_id);
        if ($task->status === 'completed') {
            return;
        }

        $note = "IT request cancelled: {$reason} — resolve this task manually.";
        $existing = trim((string) $task->notes);
        $task->update([
            'notes' => $existing === '' ? $note : $existing."\n".$note,
        ]);
    }

    public function reconcileWorkflow(?ItProvisioningWorkflow $workflow): void
    {
        if (! $workflow) {
            return;
        }

        $statuses = $workflow->requests()->pluck('status');
        $status = match (true) {
            $statuses->isEmpty() => 'completed',
            $statuses->contains('failed'), $statuses->contains('cancelled') => 'partially_failed',
            $statuses->every(fn (string $value) => $value === 'done') => 'completed',
            $statuses->contains('in_progress'), $statuses->contains('done') => 'in_progress',
            default => 'pending',
        };
        if ($workflow->status !== $status) {
            $workflow->update(['status' => $status]);
        }
    }

    private function reconcileCanonicalTarget(ItProvisioningRequest $request, User $actor): void
    {
        if ($request->canonical_target_type === 'asset_assignment' && $request->canonical_target_id) {
            $assignment = AssetAssignment::query()->lockForUpdate()->find($request->canonical_target_id);
            if ($assignment && $assignment->released_at === null) {
                $assignment->update(['released_at' => now()]);
            }
        }

        if ($request->canonical_target_type === 'device_assignment' && $request->canonical_target_id) {
            $assignment = DeviceAssignment::query()
                ->with('device')
                ->lockForUpdate()
                ->find($request->canonical_target_id);
            if ($assignment && $assignment->released_at === null && $assignment->device) {
                $this->deviceAssignments->release($assignment->device, $actor->id);
            }
        }
    }

    private function completeSourceTasksWhenReady(ItProvisioningRequest $request, User $actor): void
    {
        if ($request->onboarding_task_id && ! ItProvisioningRequest::query()
            ->where('provisioning_workflow_id', $request->provisioning_workflow_id)
            ->where('onboarding_task_id', $request->onboarding_task_id)
            ->where('status', '!=', 'done')
            ->exists()) {
            $task = $request->onboardingTask;
            if ($task && $task->status !== 'completed') {
                $this->onboardingService->completeTask($task, $actor->id, array_filter([
                    'notes' => $request->evidence_summary,
                    'signed_off_by' => $task->sign_off_required ? $actor->id : null,
                ]));
            }
        }

        if ($request->offboarding_task_id && ! ItProvisioningRequest::query()
            ->where('provisioning_workflow_id', $request->provisioning_workflow_id)
            ->where('offboarding_task_id', $request->offboarding_task_id)
            ->where('status', '!=', 'done')
            ->exists()) {
            $task = $request->offboardingTask;
            if ($task && $task->status !== 'completed') {
                $this->onboardingService->completeOffboardingTask($task, $actor->id, array_filter([
                    'notes' => $request->evidence_summary,
                    'signed_off_by' => $task->sign_off_required ? $actor->id : null,
                ]));
            }
        }
    }

    private function guard(ItProvisioningRequest $request, User $actor): void
    {
        if (! $this->access->canManage($actor, $request)) {
            throw new DomainException('You are not allowed to manage this provisioning request.');
        }
    }

    private function lock(ItProvisioningRequest $request): ItProvisioningRequest
    {
        // Every child lifecycle takes the parent workflow lock first. Sibling
        // requests therefore cannot publish contradictory parent progress
        // while each child is being assigned, cancelled, failed or fulfilled.
        if ((int) $request->provisioning_workflow_id > 0) {
            ItProvisioningWorkflow::query()
                ->lockForUpdate()
                ->findOrFail($request->provisioning_workflow_id);
        }

        return ItProvisioningRequest::query()
            ->lockForUpdate()
            ->findOrFail($request->getKey());
    }
}
