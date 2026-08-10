<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Str;

final class DeviceCommandBatchPresenter
{
    public function __construct(
        private readonly DeviceCommandBatchAccessService $access,
        private readonly CommandCapabilityRegistry $capabilities,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, DeviceCommandBatch $batch): array
    {
        $batch = $this->access->assertCanView($viewer, $batch);
        $batch->load([
            'requestedBy:id,name',
            'targets' => fn ($targets) => $targets->orderBy('position'),
            'targets.device:id,device_uid,name,domain,category,subcategory,provider,status,health_status',
            'targets.site:id,name',
            'targets.command.requestedBy:id,name',
            'targets.command.approvedBy:id,name',
            'targets.command.attempts',
            'targets.command.reconciliations',
        ]);
        try {
            $definition = $this->capabilities->definition($batch->capability);
            $label = $definition->label;
            $impact = $definition->impact;
            $expectedResult = $definition->expectedResult;
        } catch (DomainException) {
            $label = Str::headline($batch->capability);
            $impact = 'The original capability policy is no longer available.';
            $expectedResult = 'Review each retained child command before any further action.';
        }

        $targets = $batch->targets->map(function ($target): array {
            $command = $target->command;
            $latestAttempt = $command?->attempts->sortByDesc('attempt_number')->first();
            $latestReconciliation = $command?->reconciliations->sortByDesc('observed_at')->first();

            return [
                'id' => (int) $target->id,
                'position' => (int) $target->position,
                'inclusionStatus' => $target->inclusion_status,
                'safeExclusionCode' => $target->safe_exclusion_code,
                'safeExclusionReason' => $target->safe_exclusion_reason,
                'device' => [
                    'id' => (int) $target->device->id,
                    'uid' => $target->device->device_uid,
                    'name' => $target->device->name,
                    'category' => $target->device->category,
                    'subcategory' => $target->device->subcategory,
                    'provider' => $target->device->provider,
                    'status' => $target->device->status?->value ?? (string) $target->device->status,
                    'health' => $target->device->health_status?->value ?? (string) $target->device->health_status,
                    'href' => "/security-devices/devices/{$target->device->id}?section=management",
                ],
                'site' => $target->site ? [
                    'id' => (int) $target->site->id,
                    'name' => $target->site->name,
                    'href' => "/security-devices/sites/{$target->site->id}",
                ] : null,
                'command' => $command ? [
                    'id' => (int) $command->id,
                    'uuid' => $command->command_uuid,
                    'status' => $command->status->value,
                    'requestedBy' => $command->requestedBy?->name,
                    'approvedBy' => $command->approvedBy?->name,
                    'expiresAt' => $command->expires_at?->toISOString(),
                    'safeFailureReason' => $command->safe_failure_reason,
                    'expectedState' => $command->expected_state ?? [],
                    'nextAction' => $this->nextAction($command),
                    'latestAttempt' => $latestAttempt ? [
                        'number' => (int) $latestAttempt->attempt_number,
                        'status' => $latestAttempt->status->value,
                        'runtime' => $latestAttempt->runtime,
                        'safeResult' => $latestAttempt->safe_result_summary ?? [],
                        'safeFailureReason' => $latestAttempt->safe_failure_reason,
                        'completedAt' => $latestAttempt->completed_at?->toISOString(),
                    ] : null,
                    'latestReconciliation' => $latestReconciliation ? [
                        'outcome' => $latestReconciliation->outcome->value,
                        'safeEvidenceSummary' => $latestReconciliation->safe_evidence_summary,
                        'observedAt' => $latestReconciliation->observed_at?->toISOString(),
                    ] : null,
                ] : null,
            ];
        })->values();

        $statuses = $targets->pluck('command.status')->filter();
        $terminal = $statuses->filter(
            fn (string $status): bool => CommandStatus::from($status)->isTerminal(),
        )->count();
        $reconciled = $statuses
            ->filter(fn (string $status): bool => $status === CommandStatus::Reconciled->value)
            ->count();
        $failures = $statuses->filter(
            fn (string $status): bool => CommandStatus::from($status)->isTerminal()
                && $status !== CommandStatus::Reconciled->value,
        )->count();
        $summary = [
            'selected' => (int) $batch->target_count,
            'included' => (int) $batch->included_count,
            'excluded' => (int) $batch->excluded_count,
            'sites' => (int) $batch->site_count,
            'awaitingApproval' => $statuses
                ->filter(fn (string $status): bool => $status === CommandStatus::AwaitingApproval->value)
                ->count(),
            'ready' => $statuses
                ->filter(fn (string $status): bool => $status === CommandStatus::Ready->value)
                ->count(),
            'queuedOrRunning' => $statuses->filter(fn (string $status): bool => in_array($status, [
                CommandStatus::Queued->value,
                CommandStatus::Dispatching->value,
                CommandStatus::Accepted->value,
                CommandStatus::Running->value,
                CommandStatus::Succeeded->value,
                CommandStatus::Reconciling->value,
                CommandStatus::Uncertain->value,
            ], true))->count(),
            'terminal' => $terminal,
            'reconciled' => $reconciled,
            'failedOrBlocked' => $failures,
        ];

        return [
            'id' => (int) $batch->id,
            'uuid' => $batch->batch_uuid,
            'workspace' => $batch->workspace,
            'workspaceHref' => "/security-devices/{$batch->workspace}?tab=management",
            'capability' => $batch->capability,
            'label' => $label,
            'risk' => $batch->risk->value,
            'confirmationMode' => $batch->confirmation_mode->value,
            'impact' => $impact,
            'expectedResult' => $expectedResult,
            'reason' => $batch->reason,
            'safeParameters' => $batch->safe_parameter_summary ?? [],
            'requestedBy' => $batch->requestedBy?->name,
            'requestedAt' => $batch->created_at?->toISOString(),
            'impactAcknowledgedAt' => $batch->impact_acknowledged_at?->toISOString(),
            'status' => $this->overallStatus($summary),
            'summary' => $summary,
            'targets' => $targets->all(),
            'canApprove' => $summary['awaitingApproval'] > 0
                && (int) $batch->requested_by_user_id !== (int) $viewer->id
                && $viewer->canDo('securityDevices.commands.approve'),
            'canDispatch' => $summary['ready'] > 0
                && ((int) $batch->requested_by_user_id === (int) $viewer->id
                    || $viewer->canDo('securityDevices.commands.admin')),
            'exportHref' => "/security-devices/command-batches/{$batch->id}/export",
            'partialSemantics' => 'Each included Device has an independent signed request, approval, execution attempt, and reconciliation result. A failure or exclusion never converts another target into success.',
        ];
    }

    /** @param array<string, int> $summary */
    private function overallStatus(array $summary): string
    {
        if ($summary['terminal'] === $summary['included']) {
            if ($summary['reconciled'] === $summary['included'] && $summary['excluded'] === 0) {
                return 'completed';
            }
            if ($summary['reconciled'] > 0) {
                return 'partially_completed';
            }

            return 'completed_with_failures';
        }
        if ($summary['queuedOrRunning'] > 0) {
            return 'executing';
        }
        if ($summary['awaitingApproval'] > 0) {
            return 'awaiting_approval';
        }
        if ($summary['ready'] > 0) {
            return 'ready';
        }

        return 'review_required';
    }

    private function nextAction(DeviceCommandRequest $command): string
    {
        return match ($command->status) {
            CommandStatus::AwaitingStepUp => 'The requester must refresh identity confirmation before this child can continue.',
            CommandStatus::AwaitingApproval => 'An independent reviewer must decide this child request.',
            CommandStatus::AwaitingChange => 'The linked IT Change must become current and eligible.',
            CommandStatus::Ready => 'This child is ready for governed queue dispatch.',
            CommandStatus::Queued,
            CommandStatus::Dispatching,
            CommandStatus::Accepted,
            CommandStatus::Running => 'Execution is in progress. Do not repeat the action.',
            CommandStatus::Succeeded,
            CommandStatus::Reconciling => 'Wait for fresh-state reconciliation.',
            CommandStatus::Uncertain => 'Confirm actual Device state before considering any retry.',
            CommandStatus::Reconciled => 'The expected Device state was freshly verified.',
            CommandStatus::Mismatch => 'The observed state did not match; investigate this target independently.',
            CommandStatus::Failed,
            CommandStatus::Rejected,
            CommandStatus::Expired,
            CommandStatus::Cancelled,
            CommandStatus::Blocked => 'This child is terminal and was not recorded as successful.',
            CommandStatus::Requested => 'Review this child command before it expires.',
        };
    }
}
