<?php

namespace App\Domain\It\Services;

use App\Models\ItProvisioningRequest;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/** A current verdict over canonical work; this never creates a second task. */
final class ItProvisioningReadinessService
{
    private ?bool $storageReady = null;

    public function __construct(
        private readonly ItProvisioningAccessService $access,
        private readonly ItProvisioningResponsibilityService $responsibility,
    ) {}

    public function storageReady(): bool
    {
        return $this->storageReady ??= Schema::hasColumns('it_provisioning_requests', ['lock_version', 'approval_requested_at', 'fulfilment_mode', 'reversal_of_request_id'])
            && Schema::hasColumns('it_provisioning_workflows', ['lock_version', 'owner_user_id', 'cover_user_id', 'cancelled_at'])
            && Schema::hasColumns('it_provisioning_templates', ['published_version_id', 'published_at'])
            && Schema::hasColumn('it_catalog_items', 'provisioning_template_version_id');
    }

    /** Call only after applying the canonical request access boundary. */
    public function forRequest(ItProvisioningRequest $request, User $actor): array
    {
        $request->loadMissing(['workflow', 'employeeProfile', 'assignee:id,name']);
        $storage = $this->storageReady();
        $managed = $storage && $this->access->canManage($actor, $request);
        $open = ! in_array($request->status, ['done', 'cancelled'], true);
        $workflowCancelled = $request->workflow?->cancelled_at !== null && $request->reversal_of_request_id === null;
        $blockers = [];
        if (! $storage) {
            $blockers[] = 'Provisioning history setup is incomplete. Ask an administrator to complete the reviewed database update.';
        }
        if ($workflowCancelled) {
            $blockers[] = 'The workflow is cancelled. Review its recorded work and any explicit corrective tasks.';
        }
        $sourceBlocker = $storage && $request->status !== 'done' ? app(ItProvisioningHrSourceService::class)->blocker($request) : null;
        if ($sourceBlocker !== null) {
            $blockers[] = $sourceBlocker;
        }
        if ($request->status === 'failed') {
            $blockers[] = 'Review the failure and explicitly retry this task.';
        }
        $dependencies = array_values(array_unique(array_map('intval', $request->dependency_request_ids ?? [])));
        if ($dependencies !== [] && ItProvisioningRequest::query()->whereKey($dependencies)
            ->where('provisioning_workflow_id', $request->provisioning_workflow_id)->where('status', 'done')->count() !== count($dependencies)) {
            $blockers[] = 'Complete every prerequisite in this workflow before recording fulfilment.';
        }
        if ($storage && $request->reversal_of_request_id === null && $dependencies !== []
            && ItProvisioningRequest::query()->where('provisioning_workflow_id', $request->provisioning_workflow_id)
                ->whereIn('reversal_of_request_id', $dependencies)->exists()) {
            $blockers[] = 'A prerequisite has corrective work recorded. Review a new approved workflow before continuing these original instructions.';
        }
        $approver = $storage ? $this->responsibility->approver($request) : null;
        if ($open && $request->approval_required && $request->approval_status !== 'approved') {
            $blockers[] = match (true) {
                $request->approval_status === 'rejected' => 'Approval was rejected. Review the decision before requesting another review.',
                $request->approval_status === 'expired',
                $request->approval_expires_at?->lessThanOrEqualTo(now()) => 'The approval deadline passed. Request a new review.',
                ! $request->approval_requested_at => 'Choose a distinct eligible approver and cover, then request approval.',
                $approver === null => 'Approval has no currently available eligible person. Review its owner and cover.',
                default => 'Waiting for '.$approver->name.' to decide.',
            };
        }
        $worker = $storage ? $this->responsibility->worker($request) : null;
        if ($open && $worker === null) {
            $blockers[] = 'Choose an eligible task assignee or repair the workflow owner and cover.';
        }
        $canAct = $managed && ! $workflowCancelled && $sourceBlocker === null;
        $actions = [];
        if ($canAct && $open) {
            $actions = ['assign', 'cancel'];
            if ($request->status === 'failed') {
                $actions[] = 'retry';
            } else {
                $actions[] = 'fail';
                if ($blockers === []) {
                    $actions[] = 'fulfil';
                }
            }
            if ($request->approval_required) {
                if ($request->approval_status === 'approved' || ($request->approval_status === 'pending' && $request->approval_requested_at)) {
                    $actions[] = 'withdraw_approval';
                }
                if ($request->approval_status !== 'approved' && (! $request->approval_requested_at
                    || $request->approval_status !== 'pending' || $request->approval_expires_at?->lessThanOrEqualTo(now()))) {
                    $actions[] = 'request_approval';
                }
                if ($approver && (int) $approver->id === (int) $actor->id) {
                    $actions[] = 'approve';
                    $actions[] = 'reject';
                }
            }
        } elseif ($canAct && $request->status === 'cancelled') {
            $actions[] = 'reopen';
        } elseif ($managed && $request->status === 'done' && $request->reversal_of_request_id === null && $request->workflow
            && ! ItProvisioningRequest::query()->where('reversal_of_request_id', $request->id)->exists()) {
            // Completed work is never edited; corrective work is an explicit new task.
            $actions[] = 'reverse';
        }

        return [
            'storage_ready' => $storage, 'actions' => $actions, 'blockers' => $blockers,
            'next_action' => ! $open ? ($request->status === 'done' ? 'Fulfilment recorded' : 'Cancelled')
                : ($blockers[0] ?? 'Complete the work and record verification evidence.'),
            'worker' => $worker ? ['id' => (int) $worker->id, 'name' => $worker->name] : null,
            'approver' => $approver ? ['id' => (int) $approver->id, 'name' => $approver->name] : null,
            'manual_evidence' => ! in_array($request->canonical_target_type, ['asset_assignment', 'device_assignment'], true)
                || ! in_array($request->action, ['recover', 'revoke'], true),
        ];
    }
}
