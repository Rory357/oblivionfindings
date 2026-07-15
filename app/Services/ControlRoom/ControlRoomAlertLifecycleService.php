<?php

namespace App\Services\ControlRoom;

use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single write boundary for an operational alert's lifecycle.
 *
 * Alert state, actor/timestamps, SLA milestones, task gates, operator notes,
 * and the audit entry are changed together. Human transitions remain strict;
 * integrations that intentionally complete skipped clocks use the separate
 * automated-resolution method.
 */
class ControlRoomAlertLifecycleService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly HsCorrectiveActionService $correctiveActions,
        private readonly UserSiteAccessService $siteAccess,
        private readonly ControlRoomAlertProvenanceService $provenance,
    ) {}

    public function acknowledge(
        ControlRoomAlert $alert,
        User $actor,
        ?string $operatorNote = null,
        ?int $requiredAssigneeUserId = null,
    ): ControlRoomAlert {
        $operatorNote = $this->normalizeOptionalNote($operatorNote);

        return DB::transaction(function () use ($alert, $actor, $operatorNote, $requiredAssigneeUserId): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            $this->assertRequiredAssignee($locked, $requiredAssigneeUserId);
            $this->assertStatus($locked, [ControlRoomAlert::STATUS_OPEN], 'acknowledge');
            $at = now();
            $context = $locked->context ?? [];
            if ($operatorNote !== null) {
                $context = $this->appendActivity($context, $actor, $operatorNote, 'acknowledge', $at);
            }

            $locked->forceFill([
                'status' => ControlRoomAlert::STATUS_ACK,
                'acknowledged_at' => $at,
                'acknowledged_by_user_id' => $actor->id,
                'context' => $context,
            ])->save();
            $locked->sla?->recordAcknowledge($at);

            AuditLogger::logOrFail('controlRoom.alert.acknowledge', $locked, [
                'actor_id' => $actor->id,
                'alert_id' => $locked->id,
                'from_status' => ControlRoomAlert::STATUS_OPEN,
                'to_status' => ControlRoomAlert::STATUS_ACK,
                'operator_note' => $operatorNote,
                'transitioned_at' => $at->toIso8601String(),
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Frontline-only snooze boundary. The assignee is checked after the alert
     * lock is acquired so a stale My Day card cannot silence work that has
     * already been handed to somebody else.
     */
    public function snoozeForAssignee(
        ControlRoomAlert $alert,
        User $actor,
        CarbonInterface $until,
        string $window,
    ): ControlRoomAlert {
        return DB::transaction(function () use ($alert, $actor, $until, $window): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            $this->assertRequiredAssignee($locked, $actor->id);

            if ($locked->isTerminal()) {
                throw new InvalidArgumentException('Resolved, closed, or dismissed alerts can\'t be snoozed.');
            }
            if (strtolower((string) $locked->severity) === 'critical') {
                throw new InvalidArgumentException('Critical alerts can\'t be snoozed. Open or acknowledge it.');
            }
            if (! $until->isFuture()) {
                throw new InvalidArgumentException('Choose a snooze time in the future.');
            }

            $locked->forceFill([
                'snoozed_until' => $until,
                'snoozed_by_user_id' => $actor->id,
            ])->save();

            AuditLogger::logOrFail('controlRoom.alert.snooze', $locked, [
                'actor_id' => $actor->id,
                'alert_id' => $locked->id,
                'snoozed_by' => $actor->id,
                'snoozed_until' => $until->toIso8601String(),
                'window' => $window,
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function startTriage(
        ControlRoomAlert $alert,
        User $actor,
        ?string $operatorNote = null,
    ): ControlRoomAlert {
        $operatorNote = $this->normalizeOptionalNote($operatorNote);

        return DB::transaction(function () use ($alert, $actor, $operatorNote): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            $this->assertStatus($locked, [ControlRoomAlert::STATUS_ACK], 'start triage');
            $at = now();
            $context = $locked->context ?? [];
            if ($operatorNote !== null) {
                $context = $this->appendActivity($context, $actor, $operatorNote, 'triage', $at);
            }

            $locked->forceFill([
                'status' => ControlRoomAlert::STATUS_TRIAGING,
                'context' => $context,
            ])->save();
            $locked->sla?->recordResponse($at);

            AuditLogger::logOrFail('controlRoom.alert.triage', $locked, [
                'actor_id' => $actor->id,
                'alert_id' => $locked->id,
                'from_status' => ControlRoomAlert::STATUS_ACK,
                'to_status' => ControlRoomAlert::STATUS_TRIAGING,
                'operator_note' => $operatorNote,
                'transitioned_at' => $at->toIso8601String(),
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function confirmSensor(ControlRoomAlert $alert, User $actor): ControlRoomAlert
    {
        return DB::transaction(function () use ($alert, $actor): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            $this->assertSensorSource($locked);
            $fromStatus = (string) $locked->status;
            $this->assertStatus($locked, [
                ControlRoomAlert::STATUS_OPEN,
                ControlRoomAlert::STATUS_ACK,
                ControlRoomAlert::STATUS_TRIAGING,
            ], 'confirm sensor detection');
            $at = now();
            $context = $locked->context ?? [];
            $context['confirmed_by'] = $actor->name;
            $context['confirmed_by_user_id'] = $actor->id;
            $context['confirmed_at'] = $at->toIso8601String();

            $locked->forceFill([
                'status' => ControlRoomAlert::STATUS_CONFIRMED,
                'context' => $context,
                'acknowledged_at' => $locked->acknowledged_at ?? $at,
                'acknowledged_by_user_id' => $locked->acknowledged_by_user_id ?? $actor->id,
            ])->save();

            $locked->sla?->recordAcknowledge($at);
            $locked->sla?->recordResponse($at);

            AuditLogger::logOrFail('controlRoom.alert.confirm', $locked, [
                'actor_id' => $actor->id,
                'alert_id' => $locked->id,
                'incident_id' => data_get($locked->context, 'incident_id'),
                'from_status' => $fromStatus,
                'to_status' => ControlRoomAlert::STATUS_CONFIRMED,
                'transitioned_at' => $at->toIso8601String(),
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function dismissSensor(ControlRoomAlert $alert, User $actor, string $reason): ControlRoomAlert
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A dismissal reason is required.');
        }

        return DB::transaction(function () use ($alert, $actor, $reason): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            $this->assertSensorSource($locked);
            $fromStatus = (string) $locked->status;
            $context = $locked->context ?? [];
            $hasJourneyClaim = filled(data_get($context, 'incident_id'))
                || filled(data_get($context, 'normalized_data.incident_id'))
                || $locked->clientIncident()->exists()
                || $locked->hsEvent()->exists();
            if ($hasJourneyClaim) {
                throw new InvalidArgumentException(
                    "Alert {$locked->id} cannot be dismissed because it owns an incident journey.",
                );
            }
            $this->assertStatus($locked, [
                ControlRoomAlert::STATUS_OPEN,
                ControlRoomAlert::STATUS_ACK,
                ControlRoomAlert::STATUS_TRIAGING,
            ], 'dismiss sensor detection');

            $at = now();
            $context['dismissed_reason'] = $reason;
            $context['dismissed_by'] = $actor->name;
            $context['dismissed_by_user_id'] = $actor->id;
            $context['dismissed_at'] = $at->toIso8601String();

            $locked->forceFill([
                'status' => ControlRoomAlert::STATUS_DISMISSED,
                'resolution_code' => 'false_positive',
                'context' => $context,
                'resolved_at' => $at,
                'resolved_by_user_id' => $actor->id,
                'snoozed_until' => null,
                'snoozed_by_user_id' => null,
            ])->save();

            foreach ($locked->signals()->lockForUpdate()->get() as $signal) {
                $signal->markSuppressed("false_positive: {$reason}");
            }
            $locked->sla?->endAsDismissed($at);

            AuditLogger::logOrFail('controlRoom.alert.dismiss', $locked, [
                'actor_id' => $actor->id,
                'alert_id' => $locked->id,
                'reason' => $reason,
                'from_status' => $fromStatus,
                'to_status' => ControlRoomAlert::STATUS_DISMISSED,
                'transitioned_at' => $at->toIso8601String(),
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function resolve(
        ControlRoomAlert $alert,
        User $actor,
        string $notes,
        string $code,
    ): ControlRoomAlert {
        $notes = trim($notes);
        $code = trim($code);
        if ($notes === '') {
            throw new InvalidArgumentException('Resolution notes are required.');
        }
        if ($code === '') {
            throw new InvalidArgumentException('A resolution code is required.');
        }

        return DB::transaction(function () use ($alert, $actor, $notes, $code): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            $fromStatus = (string) $locked->status;
            $this->assertStatus($locked, [
                ControlRoomAlert::STATUS_TRIAGING,
                ControlRoomAlert::STATUS_CONFIRMED,
            ], 'resolve');
            $this->assertNoActiveTasks($locked);
            $at = now();
            $context = $this->appendActivity(
                $locked->context ?? [],
                $actor,
                $notes,
                'resolution',
                $at,
            );

            $locked->forceFill([
                'status' => ControlRoomAlert::STATUS_RESOLVED,
                'resolved_at' => $at,
                'resolved_by_user_id' => $actor->id,
                'resolution_code' => $code,
                // Keep the immutable source payload when one exists. Legacy
                // alerts without source notes still get a useful summary for
                // older presenters while the full lifecycle note is retained
                // in context.activity_log.
                'notes' => $locked->notes ?? $notes,
                'context' => $context,
                'snoozed_until' => null,
                'snoozed_by_user_id' => null,
            ])->save();
            $locked->sla?->recordResolution($at);

            AuditLogger::logOrFail('controlRoom.alert.resolve', $locked, [
                'actor_id' => $actor->id,
                'alert_id' => $locked->id,
                'from_status' => $fromStatus,
                'to_status' => ControlRoomAlert::STATUS_RESOLVED,
                'resolution_code' => $code,
                'resolution_notes' => $notes,
                'transitioned_at' => $at->toIso8601String(),
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function close(ControlRoomAlert $alert, User $actor, ?string $notes = null): ControlRoomAlert
    {
        $notes = filled($notes) ? trim((string) $notes) : null;

        return DB::transaction(function () use ($alert, $actor, $notes): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            $this->assertStatus($locked, [ControlRoomAlert::STATUS_RESOLVED], 'close');
            $at = now();
            $context = $locked->context ?? [];
            if ($notes !== null) {
                $context = $this->appendActivity($context, $actor, $notes, 'closure', $at);
            }

            $locked->forceFill([
                'status' => ControlRoomAlert::STATUS_CLOSED,
                'closed_at' => $at,
                'closed_by_user_id' => $actor->id,
                'context' => $context,
            ])->save();

            AuditLogger::logOrFail('controlRoom.alert.close', $locked, [
                'actor_id' => $actor->id,
                'alert_id' => $locked->id,
                'from_status' => ControlRoomAlert::STATUS_RESOLVED,
                'to_status' => ControlRoomAlert::STATUS_CLOSED,
                'closure_notes' => $notes,
                'transitioned_at' => $at->toIso8601String(),
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function reopenForIncident(
        ControlRoomAlert $alert,
        ClientIncident $incident,
        User $actor,
        string $reason,
    ): ControlRoomAlert {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason for reopening the operational response is required.');
        }

        return DB::transaction(function () use ($alert, $incident, $actor, $reason): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            $lockedIncident = ClientIncident::query()
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->siteAccess->assertCanAccessAlert($actor, $locked, ['reports.viewAny']);
            $this->siteAccess->assertCanAccessClientIncident(
                $actor,
                $lockedIncident,
                ['healthSafety.viewAllSites', 'reports.viewAny'],
            );
            if ((int) $lockedIncident->control_room_alert_id !== (int) $locked->id) {
                throw new InvalidArgumentException('The incident is not linked to this operational alert.');
            }
            $lockedIncident->loadMissing([
                'client:id,site_id,organization_id',
                'shift.client:id,site_id,organization_id',
            ]);
            $incidentSiteId = $lockedIncident->site_id
                ?: $lockedIncident->client?->site_id
                ?: $lockedIncident->shift?->site_id
                ?: $lockedIncident->shift?->client?->site_id;
            try {
                $this->provenance->assertIncidentTuple(
                    $locked,
                    (int) $lockedIncident->client_id,
                    $incidentSiteId ? (int) $incidentSiteId : null,
                );
            } catch (DomainException $exception) {
                throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
            }
            if ($lockedIncident->status !== 'reviewed'
                || $lockedIncident->reopened_at === null
                || $lockedIncident->reopened_by === null
                || trim((string) $lockedIncident->reopened_reason) === '') {
                throw new InvalidArgumentException(
                    'The linked incident must be reopened for review before the operational response can restart.',
                );
            }

            $fromStatus = (string) $locked->status;
            $this->assertStatus($locked, [
                ControlRoomAlert::STATUS_RESOLVED,
                ControlRoomAlert::STATUS_CLOSED,
            ], 'reopen for incident');
            $at = now();
            $context = $locked->context ?? [];
            $attention = $context['journey_attention'] ?? null;
            if (! is_array($attention)
                || ($attention['type'] ?? null) !== 'incident_reopened'
                || (int) ($attention['incident_id'] ?? 0) !== (int) $lockedIncident->id
                || ($attention['requires_operational_reopen'] ?? null) !== true) {
                throw new InvalidArgumentException(
                    'The alert does not have a matching incident-reopen handover awaiting operational action.',
                );
            }

            $definition = SlaDefinition::findForAlert(
                (string) $locked->alert_type,
                (string) $locked->severity,
                (string) $locked->source,
            );
            if ($definition === null) {
                throw new InvalidArgumentException(
                    'This alert has no active matching SLA definition. Configure its SLA before reopening the operational response.',
                );
            }

            $history = $context['operational_reopen_history'] ?? [];
            $history[] = [
                'incident_id' => $lockedIncident->id,
                'reason' => $reason,
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'reopened_at' => $at->toIso8601String(),
                'from_status' => $fromStatus,
                'terminal_state' => [
                    'resolved_at' => $locked->resolved_at?->toIso8601String(),
                    'resolved_by_user_id' => $locked->resolved_by_user_id,
                    'closed_at' => $locked->closed_at?->toIso8601String(),
                    'closed_by_user_id' => $locked->closed_by_user_id,
                    'resolution_code' => $locked->resolution_code,
                ],
            ];
            $context['operational_reopen_history'] = $history;
            unset($context['journey_attention']);
            $context = $this->appendActivity($context, $actor, $reason, 'incident_reopen', $at);

            $locked->forceFill([
                'status' => ControlRoomAlert::STATUS_TRIAGING,
                'resolved_at' => null,
                'resolved_by_user_id' => null,
                'closed_at' => null,
                'closed_by_user_id' => null,
                'resolution_code' => null,
                'snoozed_until' => null,
                'snoozed_by_user_id' => null,
                'context' => $context,
            ])->save();

            $sla = $locked->sla()->lockForUpdate()->first();
            if ($sla === null) {
                $sla = AlertSla::createFromDefinition($locked, $definition, $at);
            } else {
                $sla->restartForReopen($at, $definition);
            }
            $sla->recordAcknowledge($at);
            $sla->recordResponse($at);

            AuditLogger::logOrFail('controlRoom.alert.reopenForIncident', $locked, [
                'actor_id' => $actor->id,
                'alert_id' => $locked->id,
                'incident_id' => $lockedIncident->id,
                'reason' => $reason,
                'from_status' => $fromStatus,
                'to_status' => ControlRoomAlert::STATUS_TRIAGING,
                'sla_cycle_number' => $sla->cycle_number,
                'sla_definition_id' => $sla->sla_definition_id,
                'transitioned_at' => $at->toIso8601String(),
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Resolve an integration/system alert while explicitly completing any
     * skipped SLA milestones. This is intentionally separate from human resolve.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function resolveAutomatically(
        ControlRoomAlert $alert,
        string $notes,
        string $code,
        string $source,
        array $metadata = [],
    ): ControlRoomAlert {
        $notes = trim($notes);
        if ($notes === '') {
            throw new InvalidArgumentException('Automated resolution notes are required.');
        }

        return DB::transaction(function () use ($alert, $notes, $code, $source, $metadata): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            if (in_array($locked->status, [ControlRoomAlert::STATUS_RESOLVED, ControlRoomAlert::STATUS_CLOSED], true)) {
                return $locked;
            }
            if (! $locked->isActionable()) {
                throw new InvalidArgumentException("Alert {$locked->id} is not actionable.");
            }
            $this->assertNoActiveTasks($locked);

            $at = now();
            $resolvedByUserId = is_numeric($metadata['resolved_by_user_id'] ?? null)
                ? (int) $metadata['resolved_by_user_id']
                : null;
            unset($metadata['resolved_by_user_id']);
            $resolution = array_merge([
                'resolved_at' => $at->toIso8601String(),
                'reason' => $notes,
                'source' => $source,
                'actor' => $resolvedByUserId ? 'workflow_user' : 'system',
            ], $metadata);
            $context = $locked->context ?? [];
            $history = $context['resolution_history'] ?? [];
            $history[] = $resolution;
            $context['resolution'] = $resolution;
            $context['resolution_history'] = $history;

            $locked->forceFill([
                'status' => ControlRoomAlert::STATUS_RESOLVED,
                'acknowledged_at' => $locked->acknowledged_at ?? $at,
                'acknowledged_by_user_id' => $locked->acknowledged_by_user_id ?? $resolvedByUserId,
                'resolved_at' => $at,
                'resolved_by_user_id' => $resolvedByUserId,
                'resolution_code' => $code,
                'notes' => $locked->notes ?? $notes,
                'context' => $context,
                'snoozed_until' => null,
                'snoozed_by_user_id' => null,
            ])->save();

            $locked->sla?->recordAcknowledge($at);
            $locked->sla?->recordResponse($at);
            $locked->sla?->recordResolution($at);

            AuditLogger::logOrFail('controlRoom.alert.resolve', $locked, [
                'actor_id' => $resolvedByUserId,
                'alert_id' => $locked->id,
                'resolution_source' => $source,
                'resolution_code' => $code,
                'automated' => true,
                'transitioned_at' => $at->toIso8601String(),
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function appendOperatorNote(
        ControlRoomAlert $alert,
        User $actor,
        string $content,
        string $transition,
    ): ControlRoomAlert {
        $content = trim($content);
        if ($content === '') {
            return $alert;
        }

        return DB::transaction(function () use ($alert, $actor, $content, $transition): ControlRoomAlert {
            $locked = $this->lockAlert($alert);
            $locked->forceFill([
                'context' => $this->appendActivity($locked->context ?? [], $actor, $content, $transition, now()),
            ])->save();

            AuditLogger::logOrFail('controlRoom.alert.addNote', $locked, [
                'actor_id' => $actor->id,
                'alert_id' => $locked->id,
                'transition' => $transition,
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function cancelTask(AlertTask $task, User $actor, string $reason): AlertTask
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A cancellation reason is required.');
        }

        return DB::transaction(function () use ($task, $actor, $reason): AlertTask {
            $alert = $this->lockAlertForTask($task);
            $locked = $this->lockTaskForAlert($task, $alert);
            $this->assertTaskMutationAllowed($alert);
            if ($locked->status === AlertTask::STATUS_CANCELLED) {
                return $locked;
            }
            if (in_array($locked->status, AlertTask::TERMINAL_STATUSES, true)) {
                throw new InvalidArgumentException('A completed or transferred task cannot be cancelled.');
            }
            $oldStatus = (string) $locked->status;
            $locked->forceFill([
                'status' => AlertTask::STATUS_CANCELLED,
                'completed_at' => null,
            ])->save();

            AuditLogger::logOrFail('controlRoom.task.statusChanged', $alert, [
                'actor_id' => $actor->id,
                'task_id' => $locked->id,
                'old_status' => $oldStatus,
                'new_status' => AlertTask::STATUS_CANCELLED,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function transferTaskToHealthSafety(AlertTask $task, User $actor): HsCorrectiveAction
    {
        return DB::transaction(function () use ($task, $actor): HsCorrectiveAction {
            $alert = $this->lockAlertForTask($task);
            $locked = $this->lockTaskForAlert($task, $alert);

            if ($locked->status === AlertTask::STATUS_TRANSFERRED
                || $locked->transferred_to_hs_corrective_action_id !== null
                || $locked->transferred_at !== null
                || $locked->transferred_by_user_id !== null) {
                $event = $this->lockCanonicalHealthSafetyEvent($alert);
                $this->assertHealthSafetyTransferBoundary($alert, $event, $actor);

                return $this->canonicalTransferredCorrectiveAction($locked, $event);
            }

            $this->assertTaskMutationAllowed($alert);
            $event = $this->lockCanonicalHealthSafetyEvent($alert);
            $owner = $this->assertHealthSafetyTransferBoundary($alert, $event, $actor);
            if (in_array($locked->status, AlertTask::TERMINAL_STATUSES, true)) {
                throw new InvalidArgumentException('Only an active operational task can be transferred.');
            }
            if (! $event->isOpen()) {
                throw new InvalidArgumentException('The linked H&S event is closed; this task cannot be transferred.');
            }

            $action = $this->correctiveActions->createStandalone($event, [
                'title' => $locked->title,
                'description' => $locked->description,
                'action_type' => HsCorrectiveAction::TYPE_CORRECTIVE,
                'priority' => $locked->priority ?: HsCorrectiveAction::PRIORITY_MEDIUM,
                'assigned_to_user_id' => $owner->id,
                'assigned_by_user_id' => $actor->id,
                'due_date' => $locked->due_at?->toDateString(),
                'created_by' => $actor->id,
            ]);
            $at = now();
            $locked->forceFill([
                'status' => AlertTask::STATUS_TRANSFERRED,
                'completed_at' => null,
                'transferred_to_hs_corrective_action_id' => $action->id,
                'transferred_at' => $at,
                'transferred_by_user_id' => $actor->id,
            ])->save();

            AuditLogger::logOrFail('controlRoom.task.transferredToHealthSafety', $alert, [
                'actor_id' => $actor->id,
                'task_id' => $locked->id,
                'hs_event_id' => $event->id,
                'hs_corrective_action_id' => $action->id,
                'transferred_at' => $at->toIso8601String(),
            ]);

            return $action;
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function assertTaskMutationAllowed(ControlRoomAlert $alert): void
    {
        if (! $alert->isTerminal()) {
            return;
        }

        throw new InvalidArgumentException(
            'Operational tasks are historical and read-only once their alert is resolved, closed, or dismissed.',
        );
    }

    private function lockAlertForTask(AlertTask $task): ControlRoomAlert
    {
        return ControlRoomAlert::query()
            ->whereKey($task->alert_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockTaskForAlert(AlertTask $task, ControlRoomAlert $alert): AlertTask
    {
        return AlertTask::query()
            ->whereKey($task->id)
            ->where('alert_id', $alert->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockCanonicalHealthSafetyEvent(ControlRoomAlert $alert): HsEvent
    {
        $events = HsEvent::query()
            ->where('control_room_alert_id', $alert->id)
            ->lockForUpdate()
            ->limit(2)
            ->get();

        if ($events->count() !== 1) {
            throw new InvalidArgumentException('This alert does not have one canonical H&S event for the transfer.');
        }

        /** @var HsEvent $event */
        $event = $events->first();

        return $event;
    }

    private function assertHealthSafetyHandoverAccepted(HsEvent $event): void
    {
        if ($event->handover_status !== HsEvent::HANDOVER_ACCEPTED) {
            throw new InvalidArgumentException(
                'Health & Safety must accept the canonical event handover before work can be transferred.',
            );
        }
    }

    private function assertHealthSafetyTransferBoundary(
        ControlRoomAlert $alert,
        HsEvent $event,
        User $actor,
    ): User {
        $this->siteAccess->assertCanAccessAlert($actor, $alert, ['reports.viewAny']);

        try {
            $this->provenance->assertHealthSafetyEventTuple($alert, $event);
        } catch (DomainException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $this->siteAccess->assertCanAccessHsEvent($actor, $event, ['healthSafety.viewAllSites']);

        $this->assertHealthSafetyHandoverAccepted($event);
        if ($event->owner_user_id === null
            || $event->accepted_by_user_id === null
            || $event->accepted_at === null) {
            throw new InvalidArgumentException(
                'The accepted H&S handover is incomplete; choose an approved H&S owner before transferring work.',
            );
        }

        $ownerQuery = User::query()
            ->staff()
            ->whereKey($event->owner_user_id)
            ->whereNotNull('approved_at');
        $this->siteAccess->applyStaffScope(
            $ownerQuery,
            $actor,
            ['healthSafety.viewAllSites'],
        );
        if ($event->site_id !== null) {
            $ownerQuery->whereHas('hrEmployeeProfile', function ($profileQuery) use ($event): void {
                $profileQuery->where(function ($siteQuery) use ($event): void {
                    $siteQuery->where('primary_site_id', $event->site_id)
                        ->orWhereJsonContains('secondary_site_ids', $event->site_id);
                });
            });
        }

        $owner = $ownerQuery->first();
        if (! $owner || ! $owner->canDo('hazards.manage')) {
            throw new InvalidArgumentException(
                'The accepted H&S owner is no longer eligible for this site; accept the handover again with an approved owner.',
            );
        }

        return $owner;
    }

    private function canonicalTransferredCorrectiveAction(
        AlertTask $task,
        HsEvent $event,
    ): HsCorrectiveAction {
        if ($task->status !== AlertTask::STATUS_TRANSFERRED
            || $task->transferred_to_hs_corrective_action_id === null
            || $task->transferred_at === null
            || $task->transferred_by_user_id === null) {
            throw new InvalidArgumentException(
                "Task '{$task->title}' is marked transferred but its H&S transfer record is incomplete.",
            );
        }

        $action = HsCorrectiveAction::query()
            ->whereKey($task->transferred_to_hs_corrective_action_id)
            ->where('hs_event_id', $event->id)
            ->lockForUpdate()
            ->first();

        if (! $action) {
            throw new InvalidArgumentException(
                "Task '{$task->title}' does not have an active corrective action on the canonical H&S event.",
            );
        }

        return $action;
    }

    private function lockAlert(ControlRoomAlert $alert): ControlRoomAlert
    {
        return ControlRoomAlert::query()
            ->whereKey($alert->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertRequiredAssignee(
        ControlRoomAlert $alert,
        ?int $requiredAssigneeUserId,
    ): void {
        if ($requiredAssigneeUserId === null) {
            return;
        }

        if ((int) $alert->assigned_to_user_id !== $requiredAssigneeUserId) {
            throw new InvalidArgumentException(
                'This alert is no longer assigned to you. Refresh My Day to see the current handover.',
            );
        }
    }

    /** @param list<string> $statuses */
    private function assertStatus(ControlRoomAlert $alert, array $statuses, string $action): void
    {
        if (! in_array($alert->status, $statuses, true)) {
            throw new InvalidArgumentException(
                "Cannot {$action} an alert in '{$alert->status}' status.",
            );
        }
    }

    private function assertSensorSource(ControlRoomAlert $alert): void
    {
        if (! $this->isDetectionAlert($alert)) {
            throw new InvalidArgumentException("Alert {$alert->id} is not a sensor alert.");
        }
    }

    public function isDetectionAlert(ControlRoomAlert $alert): bool
    {
        if ($alert->source === 'sensor') {
            return true;
        }

        $detectionCategories = [
            SignalType::CATEGORY_PEOPLE_SAFETY,
            SignalType::CATEGORY_MEDICAL_WELLBEING,
            SignalType::CATEGORY_HOME_FACILITY,
            SignalType::CATEGORY_FLEET,
            SignalType::CATEGORY_ASSETS,
            SignalType::CATEGORY_SECURITY,
        ];

        return $alert->signals()
            ->whereHas('signalType', fn ($query) => $query->whereIn('category', $detectionCategories))
            ->exists();
    }

    private function assertNoActiveTasks(ControlRoomAlert $alert): void
    {
        $tasks = $alert->tasks()
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id',
                'title',
                'status',
                'transferred_to_hs_corrective_action_id',
                'transferred_at',
                'transferred_by_user_id',
            ]);
        $activeTasks = $tasks->whereNotIn('status', AlertTask::TERMINAL_STATUSES);

        if ($activeTasks->isNotEmpty()) {
            $summary = $activeTasks
                ->take(3)
                ->map(fn (AlertTask $task): string => "{$task->title} ({$task->status})")
                ->implode(', ');

            throw new InvalidArgumentException(
                'Complete, cancel with a reason, or transfer all operational tasks before resolving this alert: '.$summary,
            );
        }

        $transferredTasks = $tasks->where('status', AlertTask::STATUS_TRANSFERRED);
        if ($transferredTasks->isEmpty()) {
            return;
        }

        $event = $this->lockCanonicalHealthSafetyEvent($alert);
        $this->assertHealthSafetyHandoverAccepted($event);
        foreach ($transferredTasks as $transferredTask) {
            $this->canonicalTransferredCorrectiveAction($transferredTask, $event);
        }
    }

    private function normalizeOptionalNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : $note;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function appendActivity(
        array $context,
        User $actor,
        string $content,
        string $transition,
        \DateTimeInterface $at,
    ): array {
        $activity = $context['activity_log'] ?? [];
        $activity[] = [
            'type' => 'lifecycle_note',
            'transition' => $transition,
            'content' => $content,
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'created_at' => $at->format(DATE_ATOM),
        ];
        $context['activity_log'] = $activity;

        return $context;
    }
}
