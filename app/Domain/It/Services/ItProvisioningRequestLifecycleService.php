<?php

namespace App\Domain\It\Services;

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
use Illuminate\Support\Facades\DB;

final class ItProvisioningRequestLifecycleService
{
    public function __construct(
        private readonly OnboardingService $onboardingService,
        private readonly DeviceAssignmentService $deviceAssignments,
    ) {}

    public function approve(ItProvisioningRequest $request, User $actor, ?string $decisionNote = null): ItProvisioningRequest
    {
        $this->guard($request, $actor);
        if (! $request->approval_required) {
            throw new DomainException('This request does not require approval.');
        }
        if (in_array($request->status, ['done', 'cancelled'], true)) {
            throw new DomainException('This request is already settled.');
        }

        return DB::transaction(function () use ($request, $actor, $decisionNote): ItProvisioningRequest {
            $request->forceFill([
                'approval_status' => 'approved',
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ])->save();
            ItTicketEvent::record($request, 'approved', $actor->id, array_filter([
                'decision_note' => $decisionNote,
            ]));
            AuditLogger::logOrFail('it.provisioning.request.approved', $request, [
                'organization_id' => $request->tenant_id,
                'actor_id' => $actor->id,
                'workflow_id' => $request->provisioning_workflow_id,
            ]);

            return $request->refresh();
        });
    }

    /** @param array{external_ref?: string|null, notes?: string|null, evidence_summary?: string|null} $data */
    public function fulfil(ItProvisioningRequest $request, User $actor, array $data = []): ItProvisioningRequest
    {
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

        return DB::transaction(function () use ($request, $actor, $data, $evidence): ItProvisioningRequest {
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
                'organization_id' => $request->tenant_id,
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
        $this->guard($request, $actor);
        if (in_array($request->status, ['done', 'cancelled'], true)) {
            throw new DomainException('A settled request cannot be marked failed.');
        }

        return DB::transaction(function () use ($request, $actor, $reason): ItProvisioningRequest {
            $request->forceFill([
                'status' => 'failed',
                'failure_reason' => trim($reason),
                'failed_at' => now(),
            ])->save();
            $this->reconcileWorkflow($request->workflow);
            ItTicketEvent::record($request, 'failed', $actor->id, ['reason' => trim($reason)]);
            AuditLogger::logOrFail('it.provisioning.request.failed', $request, [
                'organization_id' => $request->tenant_id,
                'actor_id' => $actor->id,
                'workflow_id' => $request->provisioning_workflow_id,
                'reason' => trim($reason),
            ]);

            return $request->refresh();
        });
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
        if (($actor->organization_id !== null && (int) $actor->organization_id !== (int) $request->tenant_id)
            || ! $actor->canDo('it.manage')) {
            throw new DomainException('You are not allowed to manage this provisioning request.');
        }
    }
}
