<?php

namespace App\Services\Tasks\Providers;

use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskSearch;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ControlRoomAlertProvider implements AssignableTaskProvider, HasModelClass, TaskProvider
{
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Same bypass list ControlRoomAlertController uses for its site scoping.
     *
     * @var array<int, string>
     */
    private const ALERT_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function sourceKey(): string
    {
        return 'alert';
    }

    public function label(): string
    {
        return 'Control Room Alerts';
    }

    public function modelClass(): string
    {
        return ControlRoomAlert::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors routes/control-room.php: POST /alerts/{alert}/assign|unassign
        // → permission:controlRoom.alerts.assign.
        return $user->canDo('controlRoom.alerts.assign');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        DB::transaction(function () use ($actor, $assigneeId, $id): void {
            $access = app(UserSiteAccessService::class);
            $freshActor = User::query()->whereKey($actor->id)->first();
            if (! $freshActor || ! $this->canAssign($freshActor)) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'You do not have permission to assign this alert.',
                ]);
            }

            // Scope and lock the current database row together so a stale
            // queue item cannot bypass tenant access or terminal-state gates.
            $alert = ControlRoomAlert::query()
                ->whereKey($id)
                ->tap(fn ($query) => $access->applyAlertScope(
                    $query,
                    $freshActor,
                    self::ALERT_BYPASS_PERMISSIONS,
                ))
                ->lockForUpdate()
                ->first();

            if (! $alert) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'Alert not found or outside your site access.',
                ]);
            }

            if (! $alert->isActionable()) {
                throw ValidationException::withMessages([
                    'assignee_id' => "Cannot assign an alert in '{$alert->status}' status.",
                ]);
            }

            $assignee = null;
            if ($assigneeId !== null) {
                $assignee = User::staff()
                    ->whereKey($assigneeId)
                    ->tap(fn ($query) => $access->applyControlRoomAssigneeScope(
                        $query,
                        $freshActor,
                        self::ALERT_BYPASS_PERMISSIONS,
                    ))
                    ->lockForUpdate()
                    ->first();

                if (! $assignee) {
                    throw ValidationException::withMessages([
                        'assignee_id' => 'You are not authorized to assign alerts to that staff member.',
                    ]);
                }
            }

            $at = now();
            $assignmentHistory = $alert->context['assignment_history'] ?? [];
            $assignmentHistory[] = [
                'action' => $assigneeId === null
                    ? 'unassigned'
                    : ($alert->assigned_to_user_id ? 'reassigned' : 'assigned'),
                'from_user_id' => $alert->assigned_to_user_id,
                'from_user_name' => $alert->assigned_to_user_id
                    ? User::query()->whereKey($alert->assigned_to_user_id)->value('name')
                    : null,
                'to_user_id' => $assigneeId,
                'to_user_name' => $assignee?->name,
                'by_user_id' => $freshActor->id,
                'by_user_name' => $freshActor->name,
                'reason' => null,
                'at' => $at->toISOString(),
            ];

            $alert->forceFill([
                'assigned_to_user_id' => $assigneeId,
                'assigned_at' => $assigneeId !== null ? $at : null,
                'assigned_by_user_id' => $assigneeId !== null ? $freshActor->id : null,
                'context' => array_merge($alert->context ?? [], [
                    'assignment_history' => $assignmentHistory,
                ]),
            ])->save();

            AuditLogger::logOrFail(
                $assigneeId === null ? 'controlRoom.alert.unassign' : 'controlRoom.alert.assign',
                $alert,
                [
                    'alert_id' => $alert->id,
                    'assigned_to' => $assigneeId,
                    'assigned_by' => $freshActor->id,
                    'actor_id' => $freshActor->id,
                ],
            );
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function canView(User $user): bool
    {
        return $user->canDo('controlRoom.viewAny')
            || $user->canDo('controlRoom.alerts.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $includeSearchContext = TaskSearch::hasQuery($filters);
        $with = [
            'client:id,first_name,last_name',
            'site:id,name',
            'assignedTo:id,name',
            $includeSearchContext
                ? 'clientIncident:id,client_id,site_id,hs_event_id,control_room_alert_id,investigation_assigned_to,reference_number,source,occurred_at,title,description,immediate_action_taken,immediate_action,witnesses,potential_consequence'
                : 'clientIncident:id,client_id,site_id,hs_event_id,control_room_alert_id,reference_number,source,occurred_at',
            'clientIncident.client:id,first_name,last_name',
            'clientIncident.site:id,name',
            $includeSearchContext
                ? 'clientIncident.hsEvent:id,reference_number,owner_user_id'
                : 'clientIncident.hsEvent:id,reference_number',
        ];

        if ($includeSearchContext) {
            array_push(
                $with,
                'tasks:id,alert_id,title,description,assigned_to_user_id',
                'tasks.assignedTo:id,name',
                'clientIncident.investigator:id,name',
                'clientIncident.followups:id,client_incident_id,assigned_to_user_id',
                'clientIncident.followups.assignedTo:id,name',
                'clientIncident.hsEvent.owner:id,name',
                'clientIncident.hsEvent.investigations:id,hs_event_id,reference_number,lead_investigator_id',
                'clientIncident.hsEvent.investigations.leadInvestigator:id,name',
                'clientIncident.hsEvent.correctiveActions:id,hs_event_id,reference_number,assigned_to_user_id',
                'clientIncident.hsEvent.correctiveActions.assignedTo:id,name',
            );
        }

        $query = ControlRoomAlert::query()
            ->with($with)
            // Same site scoping as the Control Room index (applyAlertScope) —
            // the queue must never show alerts the module itself would hide.
            ->tap(fn ($q) => app(UserSiteAccessService::class)->applyAlertScope($q, $user, self::ALERT_BYPASS_PERMISSIONS))
            ->when(
                $includeSearchContext,
                fn ($q) => $q->whereHas(
                    'clientIncident',
                    fn ($incident) => TaskSearch::applyIncidentJourneyPredicate($incident, $filters),
                ),
            )
            ->orderByDesc('triggered_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if (empty($filters['include_done'])) {
            $query->actionable();
        }

        return $query->get()->map(function (ControlRoomAlert $alert) use ($includeSearchContext) {
            $journey = IncidentJourneyTaskContext::make(
                $alert->clientIncident,
                $alert,
                includeSearchContext: $includeSearchContext,
            );
            $client = $journey['person'] ?? ($alert->client ? [
                'id' => $alert->client->id,
                'name' => trim($alert->client->first_name.' '.$alert->client->last_name),
            ] : null);
            $site = $journey['site'] ?? ($alert->site ? [
                'id' => $alert->site->id,
                'name' => (string) $alert->site->name,
            ] : null);

            $title = ucfirst(str_replace('_', ' ', (string) $alert->alert_type));

            if ($alert->category) {
                $title .= ' — '.str_replace('_', ' ', (string) $alert->category);
            }

            return new TaskItem(
                id: 'alert-'.$alert->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $alert->reference_number,
                title: $title,
                status: (string) $alert->status,
                bucket: match ($alert->status) {
                    'resolved', 'closed', 'dismissed' => TaskItem::BUCKET_DONE,
                    'ack', 'triaging', 'confirmed' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($alert->severity),
                assignee: $alert->assignedTo
                    ? ['id' => $alert->assignedTo->id, 'name' => (string) $alert->assignedTo->name]
                    : null,
                client: $client,
                site: $site,
                dueAt: optional($alert->due_at)->toIso8601String(),
                createdAt: optional($alert->created_at)->toIso8601String(),
                link: "/control-room/alerts/{$alert->id}",
                type: 'Alert',
                description: $alert->notes ? str($alert->notes)->limit(140)->toString() : null,
                journey: $journey,
                sourceContext: str_replace('_', ' ', (string) ($alert->source ?: $alert->alert_type)),
                actionLabel: 'Continue Control Room response',
                displayState: match ($alert->status) {
                    ControlRoomAlert::STATUS_OPEN => 'Awaiting response',
                    ControlRoomAlert::STATUS_ACK => 'Acknowledged',
                    ControlRoomAlert::STATUS_TRIAGING => 'Triage in progress',
                    ControlRoomAlert::STATUS_CONFIRMED => 'Response confirmed',
                    ControlRoomAlert::STATUS_RESOLVED => 'Resolved',
                    ControlRoomAlert::STATUS_CLOSED => 'Closed',
                    ControlRoomAlert::STATUS_DISMISSED => 'Dismissed',
                    default => ucfirst(str_replace('_', ' ', (string) $alert->status)),
                },
                searchTerms: $includeSearchContext
                    ? array_values(array_filter([
                        $alert->assignedTo?->name,
                        ...$alert->tasks
                            ->flatMap(fn ($task) => [$task->title, $task->description])
                            ->filter()
                            ->all(),
                    ]))
                    : [],
            );
        })->all();
    }
}
