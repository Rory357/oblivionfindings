<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Services\OnboardingService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Identity;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\Assets\AssetAssignmentService;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! app(ItProvisioningReadinessService::class)->storageReady()) {
                throw new DomainException('Complete the reviewed provisioning history database update before starting work.');
            }
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
                'category' => $data['type'],
                'action' => in_array($data['type'], ['account', 'access'], true) ? 'grant' : 'change',
                'stage' => 1,
                'item' => $data['item'],
                'assigned_to_user_id' => $assignee?->id,
                'status' => $assignee ? 'in_progress' : 'pending',
                'priority' => $data['priority'],
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'approval_required' => in_array($data['type'], ['account', 'access'], true),
                'approval_status' => in_array($data['type'], ['account', 'access'], true) ? 'pending' : 'not_required',
                'evidence_required' => true,
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
        ?string $reason = null,
    ): bool {
        return DB::transaction(function () use ($request, $actor, $assignee, $via, $reason): bool {
            $request = $this->lock($request);
            $actor = $this->guard($request, $actor);
            if (! $this->access->canAssignAgentForRequest($assignee, $request)) {
                throw new DomainException('The selected agent cannot manage this provisioning request.');
            }
            if (in_array($request->status, ['done', 'cancelled'], true)) {
                throw new DomainException('This request is closed — reopen it before reassigning.');
            }

            $status = $request->status === 'pending'
                ? 'in_progress'
                : $request->status;
            $changed = (int) $request->assigned_to_user_id !== (int) $assignee->id
                || $request->status !== $status;
            if (! $changed) {
                return false;
            }

            $request->forceFill([
                'assigned_to_user_id' => $assignee->id,
                'status' => $status,
            ])->save();
            $this->reconcileWorkflow($request->workflow);
            ItTicketEvent::record($request, 'assigned', $actor->id, array_filter([
                'to' => $assignee->id,
                'via' => $via !== 'single' ? $via : null,
                'reason' => trim((string) $reason) !== '' ? trim($reason) : null,
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
        return $this->decide($request, $actor, 'approved', $decisionNote);
    }

    public function decide(ItProvisioningRequest $request, User $actor, string $decision, ?string $decisionNote): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $decision, $decisionNote): ItProvisioningRequest {
            $request = $this->lock($request);
            $actor = $this->guard($request, $actor);
            if (! $request->approval_required) {
                throw new DomainException('This request does not require approval.');
            }
            if (in_array($request->status, ['done', 'cancelled'], true)) {
                throw new DomainException('This request is already settled.');
            }
            if (! in_array($decision, ['approved', 'rejected'], true) || $request->approval_status !== 'pending') {
                throw new DomainException('This approval is already decided. Request a new review to reconsider it.');
            }
            $responsible = app(ItProvisioningResponsibilityService::class)->approver($request);
            if (! $responsible || (int) $responsible->id !== (int) $actor->id) {
                throw new AuthorizationException('Only the currently responsible approver or eligible absence cover may decide. Configure approval responsibility or renew an expired approval first.');
            }
            if ($decision === 'rejected' && trim((string) $decisionNote) === '') {
                throw new DomainException('Explain why this request is rejected.');
            }

            $request->forceFill([
                'approval_status' => $decision,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ])->save();
            ItTicketEvent::record($request, $decision, $actor->id, array_filter([
                'decision_note' => $decisionNote,
                'approval_requested_at' => $request->approval_requested_at?->toIso8601String(),
            ]));
            AuditLogger::logOrFail('it.provisioning.request.'.$decision, $request, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'workflow_id' => $request->provisioning_workflow_id,
            ]);
            $this->reconcileWorkflow($request->workflow);

            return $request->refresh();
        });
    }

    /** @param array{external_ref?: string|null, notes?: string|null, evidence_summary?: string|null} $data */
    public function fulfil(ItProvisioningRequest $request, User $actor, array $data = []): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $data): ItProvisioningRequest {
            $request = $this->lock($request);
            $actor = $this->guard($request, $actor);
            if ($request->status === 'done') {
                throw new DomainException('This request is already fulfilled.');
            }
            if ($request->status === 'cancelled') {
                throw new DomainException('This request was cancelled — it can no longer be fulfilled.');
            }
            if ($request->status === 'failed') {
                throw new DomainException('Review the failure and retry this task before recording completion.');
            }

            $dependencies = array_map('intval', $request->dependency_request_ids ?? []);
            if ($dependencies !== [] && ItProvisioningRequest::query()
                ->whereIn('id', $dependencies)
                ->where('provisioning_workflow_id', $request->provisioning_workflow_id)
                ->where('status', 'done')->count() !== count(array_unique($dependencies))) {
                throw new DomainException('Complete this request’s dependencies first.');
            }
            if ($request->reversal_of_request_id === null && $dependencies !== []
                && ItProvisioningRequest::query()->where('provisioning_workflow_id', $request->provisioning_workflow_id)
                    ->whereIn('reversal_of_request_id', $dependencies)->exists()) {
                throw new DomainException('A prerequisite has corrective work recorded. Review that work and use a new approved workflow before continuing dependent original tasks.');
            }
            if ($request->approval_required && $request->approval_status !== 'approved') {
                throw new DomainException('This request needs approval before fulfilment.');
            }
            if (! app(ItProvisioningResponsibilityService::class)->worker($request)) {
                throw new DomainException('Choose an eligible task assignee or repair the workflow owner and cover before recording fulfilment.');
            }

            if (! empty($data['canonical_target_type']) || ! empty($data['canonical_target_id'])) {
                $this->bindCanonicalTarget($request, $actor, $data);
            }
            if ($request->canonical_target_type || $request->canonical_target_id) {
                if (! app(ItProvisioningCanonicalTargetService::class)->find($actor, $request, $request->canonical_target_type, $request->canonical_target_id)) {
                    throw new DomainException('The linked canonical record is no longer available for this employee and your current access. Review the original record before recording completion.');
                }
            }

            $evidence = trim((string) ($data['evidence_summary'] ?? $request->evidence_summary ?? ''));
            $manual = ! in_array($request->canonical_target_type, ['asset_assignment', 'device_assignment'], true)
                || ! in_array($request->action, ['recover', 'revoke'], true);
            if (($request->evidence_required || in_array($request->type, ['account', 'access', 'equipment'], true)) && mb_strlen($evidence) < 12) {
                throw new DomainException('Record fulfilment evidence before completing this request.');
            }
            if ($manual && in_array($request->type, ['account', 'access'], true)
                && trim((string) ($data['external_ref'] ?? $request->external_ref)) === '') {
                throw new DomainException('Record the actual account/access reference and describe the manual action and verification. This application does not change the external account.');
            }

            $this->reconcileCanonicalTarget($request, $actor);
            $request->forceFill([
                'status' => 'done',
                'external_ref' => $data['external_ref'] ?? $request->external_ref,
                'evidence_summary' => $evidence !== '' ? $evidence : $request->evidence_summary,
                'fulfilment_mode' => $manual ? 'manual_evidence' : 'canonical_assignment_release',
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
            $actor = $this->guard($request, $actor);
            if (trim($reason) === '') {
                throw new DomainException('Describe the failure and the next corrective action.');
            }
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
            $actor = $this->guard($request, $actor);
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

    public function requestApproval(ItProvisioningRequest $request, User $actor, array $data): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $data): ItProvisioningRequest {
            $request = $this->lock($request);
            $actor = $this->guard($request, $actor);
            if (! $request->approval_required || in_array($request->status, ['done', 'cancelled'], true)) {
                throw new DomainException('Only an open task requiring approval can request a review.');
            }
            if ($request->approval_status === 'approved' || ($request->approval_status === 'pending'
                && $request->approval_requested_at && (! $request->approval_expires_at || $request->approval_expires_at->isFuture()))) {
                throw new DomainException('An active approval already exists. Withdraw it before requesting another review.');
            }
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw new DomainException('Explain the work that needs approval.');
            }
            [$primary, $cover] = app(ItProvisioningResponsibilityService::class)->pair(
                isset($data['primary_approver_user_id']) ? (int) $data['primary_approver_user_id'] : null,
                isset($data['cover_approver_user_id']) ? (int) $data['cover_approver_user_id'] : null,
                $this->access->siteIdFor($request),
                [(int) $actor->id, (int) $request->created_by, (int) $request->employeeProfile?->user_id],
            );
            $deadline = isset($data['approval_expires_at']) ? Carbon::parse($data['approval_expires_at']) : null;
            if (! $deadline || $deadline->lessThanOrEqualTo(now())) {
                throw new DomainException('Choose a future approval deadline.');
            }
            $request->forceFill([
                'approval_status' => 'pending', 'primary_approver_user_id' => $primary->id,
                'cover_approver_user_id' => $cover->id, 'approval_requested_by' => $actor->id,
                'approval_requested_at' => now(), 'approval_expires_at' => $deadline,
                'approved_by_user_id' => null, 'approved_at' => null,
            ])->save();
            $this->record($request, $actor, 'approval_requested', ['reason' => $reason,
                'primary_approver_user_id' => $primary->id, 'cover_approver_user_id' => $cover->id,
                'expires_at' => $deadline->toIso8601String()]);
            $this->reconcileWorkflow($request->workflow);

            return $request->refresh();
        });
    }

    public function withdrawApproval(ItProvisioningRequest $request, User $actor, string $reason): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): ItProvisioningRequest {
            $request = $this->lock($request);
            $actor = $this->guard($request, $actor);
            if (! in_array($request->approval_status, ['pending', 'approved'], true)
                || in_array($request->status, ['done', 'cancelled'], true) || trim($reason) === '') {
                throw new DomainException('An open approval and a withdrawal reason are required.');
            }
            if ($request->workflow && $request->workflow->requests()->where('status', 'done')
                ->whereJsonContains('dependency_request_ids', $request->id)->exists()) {
                throw new DomainException('Dependent work is already complete. Record corrective work instead of invalidating its approval history.');
            }
            $request->forceFill(['approval_status' => 'cancelled'])->save();
            $this->record($request, $actor, 'approval_withdrawn', ['reason' => trim($reason)]);
            $this->reconcileWorkflow($request->workflow);

            return $request->refresh();
        });
    }

    public function retry(ItProvisioningRequest $request, User $actor, string $reason): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): ItProvisioningRequest {
            $request = $this->lock($request);
            $actor = $this->guard($request, $actor);
            if ($request->status !== 'failed' || trim($reason) === '') {
                throw new DomainException('Only a failed task can be retried. Explain the correction before trying again.');
            }
            $request->forceFill(['status' => $request->assigned_to_user_id ? 'in_progress' : 'pending',
                'failed_at' => null, 'failure_reason' => null])->save();
            $this->record($request, $actor, 'retried', ['reason' => trim($reason)]);
            $this->reconcileWorkflow($request->workflow);

            return $request->refresh();
        });
    }

    public function reopen(ItProvisioningRequest $request, User $actor, string $reason): ItProvisioningRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): ItProvisioningRequest {
            $request = $this->lock($request);
            $actor = $this->guard($request, $actor);
            if ($request->status !== 'cancelled' || trim($reason) === '') {
                throw new DomainException('Only an individually cancelled task can be reopened with a reason. Completed actions require explicit reversal work.');
            }
            $request->forceFill(['status' => 'pending', 'approval_status' => $request->approval_required ? 'cancelled' : 'not_required'])->save();
            $this->record($request, $actor, 'reopened', ['reason' => trim($reason)]);
            $this->reconcileWorkflow($request->workflow);

            return $request->refresh();
        });
    }

    private function bindCanonicalTarget(ItProvisioningRequest $request, User $actor, array $data): void
    {
        $type = $data['canonical_target_type'] ?? null;
        $id = (int) ($data['canonical_target_id'] ?? 0);
        if ($request->canonical_target_id && ($type !== $request->canonical_target_type || $id !== (int) $request->canonical_target_id)) {
            throw new DomainException('The original canonical target cannot be replaced. Create corrective work if the assignment changed.');
        }
        $userId = (int) $request->employeeProfile?->user_id;
        $valid = match ($type) {
            'identity' => in_array($request->type, ['account', 'access'], true) && Identity::query()->whereKey($id)->where('user_id', $userId)->exists(),
            'asset_assignment' => $request->type === 'equipment' && AssetAssignment::query()->whereKey($id)
                ->whereIn('assignee_type', ['staff', 'user', User::class])->where('assignee_id', $userId)->exists(),
            'device_assignment' => $request->type === 'equipment' && DeviceAssignment::query()->whereKey($id)
                ->where('assignable_type', DeviceAssignment::TARGET_STAFF)->where('assignable_id', $userId)->exists(),
            default => false,
        };
        if (! $valid || ! app(ItProvisioningCanonicalTargetService::class)->find($actor, $request, $type, $id)) {
            throw new DomainException('Choose an existing canonical account or assignment belonging to this employee.');
        }
        $request->forceFill(['canonical_target_type' => $type, 'canonical_target_id' => $id]);
    }

    private function record(ItProvisioningRequest $request, User $actor, string $event, array $data): void
    {
        ItTicketEvent::record($request, $event, $actor->id, $data);
        AuditLogger::logOrFail('it.provisioning.request.'.$event, $request,
            ['actor_id' => $actor->id, 'workflow_id' => $request->provisioning_workflow_id, ...$data]);
    }

    public function reconcileWorkflow(?ItProvisioningWorkflow $workflow): void
    {
        if (! $workflow) {
            return;
        }
        $workflow->refresh();
        if ($workflow->cancelled_at !== null) {
            $workflow->forceFill(['lock_version' => (int) $workflow->lock_version + 1])->save();

            return;
        }

        $statuses = $workflow->requests()->pluck('status');
        $status = match (true) {
            $statuses->isEmpty() => 'completed',
            $statuses->every(fn (string $value) => $value === 'cancelled') => 'cancelled',
            $statuses->contains('failed'), $statuses->contains('cancelled') => 'partially_failed',
            $statuses->every(fn (string $value) => $value === 'done') => 'completed',
            $statuses->contains('in_progress'), $statuses->contains('done') => 'in_progress',
            default => 'pending',
        };
        $workflow->update(['status' => $status, 'lock_version' => (int) $workflow->lock_version + 1]);
    }

    private function reconcileCanonicalTarget(ItProvisioningRequest $request, User $actor): void
    {
        if (! in_array($request->action, ['recover', 'revoke'], true)) {
            return;
        }
        $beneficiaryId = (int) $request->employeeProfile?->user_id;
        if ($request->canonical_target_type === 'asset_assignment' && $request->canonical_target_id) {
            $original = AssetAssignment::query()->find($request->canonical_target_id);
            $asset = $original ? Asset::query()->whereKey($original->asset_id)->lockForUpdate()->first() : null;
            if (! $asset) {
                throw new DomainException('The original asset is unavailable. Reconcile its canonical record first.');
            }
            Gate::forUser($actor)->authorize('manageAssignments', $asset);
            $assignment = AssetAssignment::query()->whereKey($request->canonical_target_id)->lockForUpdate()->first();
            if (! $assignment || ! in_array($assignment->assignee_type, ['staff', 'user', User::class], true)
                || (int) $assignment->assignee_id !== $beneficiaryId || (int) $assignment->asset_id !== (int) $asset->id) {
                throw new DomainException('The original asset assignment is no longer available for this employee. Reconcile its canonical record first.');
            }
            if ($assignment->released_at === null) {
                app(AssetAssignmentService::class)->release($actor, $asset, $assignment);
            }
        }

        if ($request->canonical_target_type === 'device_assignment' && $request->canonical_target_id) {
            $original = DeviceAssignment::query()->find($request->canonical_target_id);
            $device = $original ? Device::query()->whereKey($original->device_id)->lockForUpdate()->first() : null;
            if (! $device) {
                throw new DomainException('The original device is unavailable. Reconcile its canonical record first.');
            }
            $devices = app(SecurityDevicesAccessService::class);
            $devices->assertCanViewDevice($actor, $device);
            abort_unless($actor->canDo('securityDevices.devices.assign'), 403);
            $assignment = DeviceAssignment::query()->whereKey($request->canonical_target_id)->lockForUpdate()->first();
            if (! $assignment || $assignment->assignable_type !== DeviceAssignment::TARGET_STAFF
                || (int) $assignment->assignable_id !== $beneficiaryId || (int) $assignment->device_id !== (int) $device->id) {
                throw new DomainException('The original device assignment no longer belongs to this employee. Reconcile its canonical record first.');
            }
            if ($assignment->released_at === null) {
                if (DeviceAssignment::query()->where('device_id', $assignment->device_id)->whereNull('released_at')
                    ->whereKeyNot($assignment->id)->exists()) {
                    throw new DomainException('This device has another current assignment. Reconcile it in Devices before completing recovery.');
                }
                $this->deviceAssignments->release($device, $actor->id, function (Device $lockedDevice) use ($actor, $devices): void {
                    $devices->assertCanViewDevice($actor, $lockedDevice);
                    $devices->assertCanManageActiveAssignment($actor, $lockedDevice, true);
                });
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

    private function guard(ItProvisioningRequest $request, User $actor): User
    {
        // A bulk operation or already-open form can hold an older User with
        // loaded roles. Recheck the current actor at the locked write boundary.
        $current = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
        if (! $current || ! $this->access->canManage($current, $request)) {
            throw new AuthorizationException('You are not allowed to manage this provisioning request.');
        }
        if (! app(ItProvisioningReadinessService::class)->storageReady()) {
            throw new DomainException('Complete the reviewed provisioning history database update before changing this work.');
        }
        if ($request->workflow?->cancelled_at !== null && $request->reversal_of_request_id === null) {
            throw new DomainException('This workflow was cancelled. Completed evidence is retained; use its explicit reversal tasks for any required corrective work.');
        }
        $sourceBlocker = app(ItProvisioningHrSourceService::class)->blocker($request);
        if ($sourceBlocker !== null) {
            throw new DomainException($sourceBlocker);
        }

        return $current;
    }

    private function lock(ItProvisioningRequest $request): ItProvisioningRequest
    {
        $request = ItProvisioningRequest::query()->findOrFail($request->id);
        HrEmployeeProfile::query()->whereKey($request->employee_profile_id)->lockForUpdate()->firstOrFail();
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
