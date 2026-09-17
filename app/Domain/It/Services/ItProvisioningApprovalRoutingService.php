<?php

namespace App\Domain\It\Services;

use App\Models\ItProvisioningRequest;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Routes a freshly gated task to an eligible approver pair without an agent
 * choosing approvers by hand. Configured catalogue defaults win; the active
 * service desk fallback queue is the second choice. A routing gap is recorded,
 * never thrown: the task simply keeps the existing "choose an approver" verdict.
 */
final class ItProvisioningApprovalRoutingService
{
    public const DEFAULT_WINDOW_DAYS = 5;

    public function __construct(
        private readonly ItProvisioningResponsibilityService $responsibility,
        private readonly ItProvisioningAccessService $access,
        private readonly ItProvisioningNotifier $notifier,
    ) {}

    /**
     * Call inside the transaction that created or gated the task.
     *
     * @return 'requested'|'unavailable'|'skipped'
     */
    public function route(
        ItProvisioningRequest $task,
        User $actor,
        string $reason,
        ?int $primary = null,
        ?int $cover = null,
        ?int $windowDays = null,
        string $source = 'catalogue',
    ): string {
        $task = ItProvisioningRequest::query()->lockForUpdate()->findOrFail($task->id);
        if (! $task->approval_required || $task->approval_requested_at !== null
            || $task->approval_status !== 'pending' || in_array($task->status, ['done', 'cancelled'], true)) {
            return 'skipped';
        }
        $siteId = $this->access->siteIdFor($task);
        $excluded = array_values(array_filter(array_unique([
            (int) $actor->id, (int) $task->created_by, (int) $task->employeeProfile?->user_id,
        ])));
        $candidates = [];
        if ($primary && $cover) {
            $candidates[] = [$primary, $cover, 'catalogue_default'];
        }
        $fallback = $this->responsibility->fallback($siteId);
        if ($fallback['owner'] && $fallback['cover']) {
            $candidates[] = [(int) $fallback['owner']->id, (int) $fallback['cover']->id, 'service_desk_fallback'];
        }
        foreach ($candidates as [$primaryId, $coverId, $basis]) {
            try {
                [$owner, $backup] = $this->responsibility->pair($primaryId, $coverId, $siteId, $excluded);
            } catch (DomainException) {
                continue;
            }
            $deadline = Carbon::now('Pacific/Auckland')
                ->addDays($windowDays ?? self::DEFAULT_WINDOW_DAYS)->endOfDay()->utc();
            $task->forceFill([
                'approval_status' => 'pending', 'primary_approver_user_id' => $owner->id,
                'cover_approver_user_id' => $backup->id, 'approval_requested_by' => $actor->id,
                'approval_requested_at' => now(), 'approval_expires_at' => $deadline,
                'approved_by_user_id' => null, 'approved_at' => null,
            ])->save();
            $payload = ['reason' => $reason, 'primary_approver_user_id' => $owner->id,
                'cover_approver_user_id' => $backup->id, 'expires_at' => $deadline->toIso8601String(),
                'routing_basis' => $basis, 'source' => $source];
            ItTicketEvent::record($task, 'approval_requested', $actor->id, $payload);
            AuditLogger::logOrFail('it.provisioning.request.approval_requested', $task,
                ['actor_id' => $actor->id, 'workflow_id' => $task->provisioning_workflow_id, ...$payload]);
            $this->notifier->approvalRequested($task, [$owner, $backup]);

            return 'requested';
        }
        ItTicketEvent::record($task, 'approval_routing_unavailable', $actor->id, ['source' => $source]);
        AuditLogger::logOrFail('it.provisioning.request.approval_routing_unavailable', $task,
            ['actor_id' => $actor->id, 'workflow_id' => $task->provisioning_workflow_id, 'source' => $source]);

        return 'unavailable';
    }
}
