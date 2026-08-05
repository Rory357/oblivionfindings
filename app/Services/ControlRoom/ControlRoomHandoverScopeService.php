<?php

namespace App\Services\ControlRoom;

use App\Models\AuditLog;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ControlRoomHandoverScopeService
{
    /** @var array<string, string> */
    public const CRITERIA = [
        'created_during_shift' => 'Created during this shift',
        'lifecycle_changed' => 'Operational state changed during this shift',
        'shift_member_ownership' => 'Assigned to or watched by a shift member',
        'sla_breached_or_at_risk' => 'SLA breached or entered the at-risk window',
        'task_due_before_next_shift' => 'New open task is due before the next expected shift',
        'governance_state_changed' => 'Linked incident or H&S decision state changed',
        'pinned_by_outgoing_lead' => 'Pinned by the outgoing lead',
    ];

    /** @var list<string> */
    private const MATERIAL_ALERT_ACTIONS = [
        'controlRoom.alert.acknowledge',
        'controlRoom.alert.triage',
        'controlRoom.alert.confirm',
        'controlRoom.alert.escalate',
        'controlRoom.alert.autoEscalate',
        'controlRoom.alert.snooze',
        'controlRoom.alert.unsnooze',
        'controlRoom.alert.assign',
        'controlRoom.alert.assignToMe',
        'controlRoom.alert.autoAssigned',
        'controlRoom.alert.unassign',
        'controlRoom.alert.updateMeta',
        'controlRoom.alert.addNote',
        'controlRoom.alert.reopenForIncident',
        'controlRoom.watcher.added',
        'controlRoom.watcher.removed',
    ];

    public function __construct(
        private readonly AlertWorklistQuery $worklist,
        private readonly AlertWorklistPresenter $presenter,
        private readonly IncidentJourneyService $incidentJourneys,
    ) {}

    /**
     * @return array{
     *     criteria_at: string,
     *     next_expected_shift_at: string,
     *     criteria: list<array{key: string, label: string}>,
     *     required_alerts: list<array<string, mixed>>,
     *     carry_forward_alert_ids: list<int>,
     *     carry_forward: array<string, mixed>
     * }
     */
    public function build(Shift $shift, User $viewer): array
    {
        // The shared row presenter now emits record-level actions. Prime the
        // permission graph before worklist/site checks so every capability
        // decision in this uncapped scope reuses the same authorization data.
        $viewer->loadMissing([
            'permissionOverrides',
            'roles.permissions',
        ]);

        $criteriaAt = now();
        $nextExpectedShiftAt = $shift->expectedNextShiftAt($criteriaAt);
        $alerts = $this->visibleActiveAlerts($viewer);
        $materiallyChangedIds = $this->materiallyChangedAlertIds(
            $alerts->modelKeys(),
            $shift->starts_at,
            $criteriaAt,
        );
        $shiftMemberIds = $shift->memberUserIds();
        $canManageAlerts = $viewer->canDo('controlRoom.alerts.manage');

        $required = collect();
        $carryForward = collect();
        $governanceCandidates = $alerts->filter(
            fn (ControlRoomAlert $alert): bool => $this->hasGovernanceClaim($alert),
        );
        $governanceCandidates->loadMissing([
            'site:id,name',
            'client:id,site_id',
            'client.site:id,name',
            'asset.client.site',
            'fleetSignal.asset.client.site',
            'device',
        ]);
        $governanceByAlert = $this->incidentJourneys
            ->governanceRecordsForAlerts($governanceCandidates);

        foreach ($alerts as $alert) {
            $governance = $governanceByAlert->get((int) $alert->id);
            if ($governance !== null) {
                $alert->setRelation('clientIncident', $governance['incident']);
                $alert->setRelation('hsEvent', $governance['hs_event']);
            }

            $reasons = $this->reasonsFor(
                $alert,
                $shift,
                $criteriaAt,
                $nextExpectedShiftAt,
                $shiftMemberIds,
                $materiallyChangedIds,
            );

            if ($reasons === []) {
                $carryForward->push($alert);

                continue;
            }

            $presented = $this->presentAlert($alert, $viewer, $canManageAlerts);
            $presented['handover_reasons'] = $reasons;
            $required->push($presented);
        }

        return [
            'criteria_at' => $criteriaAt->toIso8601String(),
            'next_expected_shift_at' => $nextExpectedShiftAt->toIso8601String(),
            'criteria' => collect(self::CRITERIA)
                ->map(fn (string $label, string $key): array => [
                    'key' => $key,
                    'label' => $label,
                ])
                ->values()
                ->all(),
            'required_alerts' => $required->values()->all(),
            'carry_forward_alert_ids' => $carryForward
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'carry_forward' => $this->carryForwardSummary($carryForward),
        ];
    }

    /**
     * Return the exact active-alert visibility universe used by handover scope.
     *
     * @param  list<int>|null  $candidateIds
     * @return Collection<int, int>
     */
    public function visibleActiveAlertIds(User $viewer, ?array $candidateIds = null): Collection
    {
        $query = $this->worklist->forUser($viewer, ['lens' => 'all_active']);
        if ($candidateIds !== null) {
            $query->whereIn('control_room_alerts.id', $candidateIds);
        }

        return $query
            ->pluck('control_room_alerts.id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Return records the viewer can currently access regardless of lifecycle
     * state. Prepared-snapshot acceptance uses this only to recheck canonical
     * Site authorization for the frozen required IDs.
     *
     * @param  list<int>  $candidateIds
     * @return Collection<int, int>
     */
    public function visibleAlertIds(User $viewer, array $candidateIds): Collection
    {
        return $this->worklist
            ->forUser($viewer, [
                'lens' => 'all_records',
                'ids' => $candidateIds,
            ])
            ->pluck('control_room_alerts.id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /** @return EloquentCollection<int, ControlRoomAlert> */
    private function visibleActiveAlerts(User $viewer): EloquentCollection
    {
        $active = $this->worklist
            ->forUser($viewer, ['lens' => 'all_active'])
            ->get();

        /** @var EloquentCollection<int, ControlRoomAlert> $alerts */
        $alerts = new EloquentCollection(
            $active
                ->unique(fn (ControlRoomAlert $alert): int => (int) $alert->id)
                ->sortBy(function (ControlRoomAlert $alert): string {
                    $severityRank = match ($alert->severity) {
                        'critical' => 0,
                        'high' => 1,
                        'medium' => 2,
                        default => 3,
                    };

                    return sprintf(
                        '%d-%020d-%020d',
                        $severityRank,
                        $alert->triggered_at?->getTimestamp() ?? 0,
                        $alert->id,
                    );
                })
                ->values()
                ->all(),
        );
        $alerts->each(function (ControlRoomAlert $alert): void {
            $alert->sla?->setRelation('alert', $alert);
        });

        $alerts->load([
            'tasks' => fn ($query) => $query
                ->whereNotIn('status', ['completed', 'cancelled', 'transferred'])
                ->orderBy('due_at')
                ->orderBy('id'),
            'watchers:id,alert_id,user_id,added_by_user_id,created_at,updated_at',
            'operatorNotes:id,alert_id,shift_id,type,purpose,content,is_pinned,requires_followup,followup_at,user_id,created_at,updated_at',
        ]);

        return $alerts;
    }

    private function hasGovernanceClaim(ControlRoomAlert $alert): bool
    {
        return $alert->clientIncident !== null
            || $alert->hsEvent !== null
            || is_numeric(data_get($alert->context, 'incident_id'));
    }

    /**
     * @param  list<int>  $alertIds
     * @return Collection<int, int>
     */
    private function materiallyChangedAlertIds(
        array $alertIds,
        CarbonInterface $shiftStart,
        CarbonInterface $criteriaAt,
    ): Collection {
        if ($alertIds === []) {
            return collect();
        }

        return AuditLog::query()
            ->where('auditable_type', ControlRoomAlert::class)
            ->whereIn('auditable_id', $alertIds)
            ->whereIn('action', self::MATERIAL_ALERT_ACTIONS)
            ->whereBetween('created_at', [$shiftStart, $criteriaAt])
            ->pluck('auditable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param  list<int>  $shiftMemberIds
     * @param  Collection<int, int>  $materiallyChangedIds
     * @return list<array{key: string, label: string}>
     */
    private function reasonsFor(
        ControlRoomAlert $alert,
        Shift $shift,
        CarbonInterface $criteriaAt,
        CarbonInterface $nextExpectedShiftAt,
        array $shiftMemberIds,
        Collection $materiallyChangedIds,
    ): array {
        $reasons = [];
        $add = function (string $key) use (&$reasons): void {
            $reasons[$key] = [
                'key' => $key,
                'label' => self::CRITERIA[$key],
            ];
        };

        if ($this->within($alert->created_at, $shift->starts_at, $criteriaAt)) {
            $add('created_during_shift');
        }

        if ($materiallyChangedIds->contains((int) $alert->id)
            || $this->within($alert->updated_at, $shift->starts_at, $criteriaAt)
            || $this->within($alert->acknowledged_at, $shift->starts_at, $criteriaAt)
            || $this->within($alert->escalated_at, $shift->starts_at, $criteriaAt)
            || $this->activityChangedDuringShift($alert, $shift->starts_at, $criteriaAt)
        ) {
            $add('lifecycle_changed');
        }

        $assignedToShiftMember = in_array(
            (int) $alert->assigned_to_user_id,
            $shiftMemberIds,
            true,
        );
        $watchedByShiftMember = $alert->watchers->contains(
            fn ($watcher): bool => in_array((int) $watcher->user_id, $shiftMemberIds, true),
        );
        if ($assignedToShiftMember || $watchedByShiftMember) {
            $add('shift_member_ownership');
        }

        if ($this->slaRequiresHandover($alert, $shift->starts_at, $criteriaAt)) {
            $add('sla_breached_or_at_risk');
        }

        if ($alert->tasks->contains(
            fn ($task): bool => $task->due_at !== null
                && $task->due_at->lte($nextExpectedShiftAt)
                && $this->within($task->created_at, $shift->starts_at, $criteriaAt),
        )) {
            $add('task_due_before_next_shift');
        }

        if ($this->governanceChangedDuringShift($alert, $shift->starts_at, $criteriaAt)) {
            $add('governance_state_changed');
        }

        if ($alert->operatorNotes->contains(
            fn ($note): bool => $note->is_pinned
                && (int) $note->user_id === (int) $shift->shift_lead_user_id,
        )) {
            $add('pinned_by_outgoing_lead');
        }

        return array_values($reasons);
    }

    private function activityChangedDuringShift(
        ControlRoomAlert $alert,
        CarbonInterface $shiftStart,
        CarbonInterface $criteriaAt,
    ): bool {
        return collect(data_get($alert->context, 'activity_log', []))
            ->contains(function ($entry) use ($shiftStart, $criteriaAt): bool {
                if (! is_array($entry)) {
                    return false;
                }

                $transition = (string) ($entry['transition'] ?? '');
                if (! in_array($transition, [
                    'acknowledge',
                    'triage',
                    'confirm',
                    'escalate',
                    'snooze',
                    'unsnooze',
                    'assignment',
                    'metadata',
                    'incident_reopen',
                ], true)) {
                    return false;
                }

                return $this->within($entry['created_at'] ?? null, $shiftStart, $criteriaAt);
            });
    }

    private function slaRequiresHandover(
        ControlRoomAlert $alert,
        CarbonInterface $shiftStart,
        CarbonInterface $criteriaAt,
    ): bool {
        $sla = $alert->sla;
        if ($sla === null) {
            return false;
        }

        if (in_array($sla->getStatus(), ['breached', 'at_risk'], true)) {
            return true;
        }

        if ($this->within($sla->first_breach_at, $shiftStart, $criteriaAt)) {
            return true;
        }

        foreach ([
            ['deadline' => 'acknowledge_deadline', 'completed' => 'acknowledged_at'],
            ['deadline' => 'response_deadline', 'completed' => 'responded_at'],
            ['deadline' => 'resolution_deadline', 'completed' => 'resolved_at'],
        ] as $milestone) {
            $deadline = $sla->{$milestone['deadline']};
            if ($deadline === null) {
                continue;
            }

            $atRiskAt = $deadline->copy()->subMinutes(5);
            $completedAt = $sla->{$milestone['completed']};
            if ($this->within($atRiskAt, $shiftStart, $criteriaAt)
                && ($completedAt === null || $completedAt->gte($atRiskAt))
            ) {
                return true;
            }
        }

        return false;
    }

    private function governanceChangedDuringShift(
        ControlRoomAlert $alert,
        CarbonInterface $shiftStart,
        CarbonInterface $criteriaAt,
    ): bool {
        $incident = $alert->clientIncident;
        if ($incident !== null) {
            foreach ([
                $incident->created_at,
                $incident->submitted_at,
                $incident->reviewed_at,
                $incident->investigation_started_at,
                $incident->investigation_completed_at,
                $incident->reopened_at,
                $incident->closed_at,
            ] as $timestamp) {
                if ($this->within($timestamp, $shiftStart, $criteriaAt)) {
                    return true;
                }
            }
        }

        $event = $alert->hsEvent;
        if ($event === null) {
            return false;
        }

        foreach ([
            $event->created_at,
            $event->accepted_at,
            $event->worksafe_decided_at,
            $event->worksafe_notified_at,
            $event->worksafe_acknowledged_at,
            $event->closed_at,
        ] as $timestamp) {
            if ($this->within($timestamp, $shiftStart, $criteriaAt)) {
                return true;
            }
        }

        return $event->correctiveActions->contains(
            fn ($action): bool => $this->within($action->completed_at, $shiftStart, $criteriaAt)
                || $this->within($action->verified_at, $shiftStart, $criteriaAt)
                || $this->within($action->closed_at, $shiftStart, $criteriaAt),
        );
    }

    /** @return array<string, mixed> */
    private function presentAlert(
        ControlRoomAlert $alert,
        User $viewer,
        bool $canManageAlerts,
    ): array {
        $presented = $this->presenter->present($alert, $viewer, $canManageAlerts);
        $presented['tasks'] = $alert->tasks
            ->map(fn ($task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $task->due_at?->toIso8601String(),
                'href' => "/control-room/alerts/{$alert->id}",
            ])
            ->values()
            ->all();

        return $presented;
    }

    /**
     * @param  Collection<int, ControlRoomAlert>  $alerts
     * @return array<string, mixed>
     */
    private function carryForwardSummary(Collection $alerts): array
    {
        $severityCounts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];
        foreach ($alerts as $alert) {
            $severity = (string) $alert->severity;
            $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;
        }

        $byQueue = $alerts
            ->groupBy(fn (ControlRoomAlert $alert): string => $alert->queue_id === null
                ? 'unassigned'
                : (string) $alert->queue_id)
            ->map(function (Collection $queueAlerts, string $key): array {
                $first = $queueAlerts->first();

                return [
                    'id' => $key === 'unassigned' ? null : (int) $key,
                    'name' => $first?->queue?->name ?? 'Unassigned',
                    'total' => $queueAlerts->count(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        $oldest = $alerts
            ->map(fn (ControlRoomAlert $alert) => $alert->created_at)
            ->filter()
            ->sortBy(fn (CarbonInterface $timestamp): int => $timestamp->getTimestamp())
            ->first();
        $signatureRows = $alerts
            ->sortBy('id')
            ->map(fn (ControlRoomAlert $alert): array => [
                'id' => (int) $alert->id,
                'severity' => (string) $alert->severity,
                'queue_id' => $alert->queue_id === null ? null : (int) $alert->queue_id,
                'updated_at' => $alert->updated_at?->toIso8601String(),
                'sla_status' => $alert->sla?->getStatus(),
            ])
            ->values()
            ->all();

        return [
            'total' => $alerts->count(),
            'by_severity' => $severityCounts,
            'by_queue' => $byQueue,
            'oldest_created_at' => $oldest?->toIso8601String(),
            'breached_count' => $alerts->filter(
                fn (ControlRoomAlert $alert): bool => $alert->sla?->getStatus() === 'breached',
            )->count(),
            'href' => '/control-room/alerts?lens=active&handover=carry-forward',
            'signature' => hash(
                'sha256',
                json_encode($signatureRows, JSON_THROW_ON_ERROR),
            ),
        ];
    }

    private function within(
        mixed $value,
        CarbonInterface $start,
        CarbonInterface $end,
    ): bool {
        if ($value === null || $value === '') {
            return false;
        }

        try {
            $timestamp = $value instanceof CarbonInterface
                ? $value
                : Carbon::parse((string) $value);
        } catch (\Throwable) {
            return false;
        }

        return $timestamp->betweenIncluded($start, $end);
    }
}
