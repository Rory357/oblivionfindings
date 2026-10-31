<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Services\Tasks\TaskSearch;
use App\Services\UserSiteAccessService;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Support\HealthSafety\QueryPaging;

class HsCorrectiveActionProvider implements ContextualHealthSafetyTaskProvider, HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'corrective_action';
    }

    public function label(): string
    {
        return 'Corrective Actions';
    }

    public function modelClass(): string
    {
        return HsCorrectiveAction::class;
    }

    // Accountable ownership needs a reason and a current revision. The record
    // page owns that handover; generic queue assignment must not bypass it.
    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        try {
            return HealthSafetyReadContext::within($user, array_unique([
                ...FirstAidAccessService::PERMISSIONS,
                ...RestraintAccessService::PERMISSIONS,
                ...InjuryAccessService::PERMISSIONS,
            ]), fn (HealthSafetyReadContext $context): array => $this->authorizedTasksInContext($user, $filters, $context));
        } catch (HttpException $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                throw $denial;
            }

            return [];
        }
    }

    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $context->consumeSourceViewer(FirstAidAccessService::PERMISSIONS,
            fn (User $current, CurrentAuthorizationReads $reads) => $this->authorizedTasksWithinSourceAuthority($viewer, $filters, $context, $current, $reads));
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $context->consumeSourceViewer(FirstAidAccessService::PERMISSIONS,
            fn (User $current, CurrentAuthorizationReads $reads) => $this->authorizedTasksWithinSourceAuthority($viewer, [], $context, $current, $reads, true));
    }

    private function authorizedTasksWithinSourceAuthority(User $user, array $filters, ?HealthSafetyReadContext $context = null, ?User $current = null, ?CurrentAuthorizationReads $reads = null, bool $navigation = false): array
    {
        $current ??= $user;
        $includeSearchContext = TaskSearch::hasQuery($filters);
        $with = [
            'assignedTo:id,name',
            'sourceControlRoomTask:id,alert_id,title,description',
            'sourceControlRoomTask.alert' => fn ($relation) => app(ControlRoomAlertAccessService::class)->applyReadableScope($relation->getQuery(), $user),
            'sourceControlRoomTask.alert.asset',
            'sourceControlRoomTask.alert.fleetSignal',
            'sourceControlRoomTask.alert.device',
            'hsEvent.client:id,first_name,last_name',
            'hsEvent.site:id,name',
            $includeSearchContext
                ? 'hsEvent.controlRoomAlert:id,reference_number,assigned_to_user_id'
                : 'hsEvent.controlRoomAlert:id,reference_number',
            $includeSearchContext
                ? 'hsEvent.clientIncident:id,client_id,site_id,hs_event_id,investigation_assigned_to,reference_number,source,occurred_at,title,description,immediate_action_taken,immediate_action,witnesses,potential_consequence'
                : 'hsEvent.clientIncident:id,client_id,site_id,hs_event_id,reference_number,source,occurred_at',
            'hsEvent.clientIncident.client:id,first_name,last_name',
            'hsEvent.clientIncident.site:id,name',
        ];

        if ($includeSearchContext) {
            array_push(
                $with,
                'hsInvestigation:id,reference_number',
                'hsEvent.owner:id,name',
                'hsEvent.controlRoomAlert.assignedTo:id,name',
                'hsEvent.controlRoomAlert.tasks:id,alert_id,title,description,assigned_to_user_id',
                'hsEvent.controlRoomAlert.tasks.assignedTo:id,name',
                'hsEvent.investigations:id,hs_event_id,reference_number,lead_investigator_id',
                'hsEvent.investigations.leadInvestigator:id,name',
                'hsEvent.correctiveActions:id,hs_event_id,reference_number,assigned_to_user_id',
                'hsEvent.correctiveActions.assignedTo:id,name',
                'hsEvent.clientIncident.investigator:id,name',
                'hsEvent.clientIncident.followups:id,client_incident_id,assigned_to_user_id',
                'hsEvent.clientIncident.followups.assignedTo:id,name',
            );
        }

        $query = HsCorrectiveAction::query()
            ->with($navigation ? [] : $with)
            ->when(
                $includeSearchContext,
                fn ($q) => $q->where(fn ($match) => $match
                    ->whereHas('hsEvent.clientIncident', fn ($incident) => TaskSearch::applyIncidentJourneyPredicate($incident, $filters))
                    ->orWhere(fn ($own) => TaskSearch::applyRecordPredicate($own, $filters,
                        ['reference_number', 'title', 'description', 'status', 'priority'],
                        [
                            'assignedTo' => ['name'],
                            'hsEvent' => ['reference_number'],
                            'hsEvent.client' => ['first_name', 'last_name'],
                            'hsEvent.site' => ['name'],
                            'hsInvestigation' => ['reference_number'],
                            'sourceControlRoomTask' => ['title', 'description'],
                        ],
                        // B1.1: search by the same words the rows show.
                        \App\Support\HealthSafety\HsLabels::LABELS['action_status'],
                    ))),
            )
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('created_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if (empty($filters['include_done'])) {
            $query->where('status', '!=', HsCorrectiveAction::STATUS_CLOSED);
        }

        if ($reads !== null) {
            $query = $reads->query($query);
        }

        return app(TaskProviderAuthorization::class)->siteScoped(
            $current,
            $this->canView($current),
            $query,
            function ($scoped, User $actor) use ($context) {
                // Candidate discovery is independent; the final relation retains its outer-row correlation.
                $childIds = (clone $scoped)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))
                    ->select($scoped->qualifyColumn('hs_event_id'));
                if ($context === null) {
                    throw new LogicException('H&S task source discovery requires its active prepared callback.');
                }
                $context->assertViewer($actor);
                $candidates = $context->applyEventSiteScope(
                    HsEvent::query()->whereIn('hs_events.id', $childIds),
                    UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
                );

                return $scoped->whereHas('hsEvent', function ($events) use ($actor, $context, $candidates) {
                    return app(HsEventAccessService::class)->applyReadableScope($events, $actor, $context, $candidates);
                });
            },
            function (HsCorrectiveAction $action) use ($includeSearchContext, $user, $context, $current, $navigation) {
                if ($navigation) {
                    // Preserve the canonical query, ordering and cap before badge eligibility.
                    return new TaskItem(id: 'corrective_action-'.$action->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                        ref: null, title: '', status: (string) $action->status, bucket: match ($action->status) {
                            HsCorrectiveAction::STATUS_CLOSED => TaskItem::BUCKET_DONE, HsCorrectiveAction::STATUS_IN_PROGRESS, HsCorrectiveAction::STATUS_COMPLETED, HsCorrectiveAction::STATUS_VERIFIED => TaskItem::BUCKET_IN_PROGRESS, default => TaskItem::BUCKET_OPEN
                        },
                        severity: 'info', assignee: (int) $action->assigned_to_user_id === (int) $current->id ? ['id' => $current->id] : null,
                        dueAt: optional($action->due_date)->toIso8601String());
                }

                $event = $action->hsEvent;
                $journey = IncidentJourneyTaskContext::make(
                    $event?->clientIncident,
                    $user,
                    $event?->controlRoomAlert,
                    $event,
                    $includeSearchContext,
                    $context,
                );
                $client = $journey['person'] ?? ($event?->client ? [
                    'id' => $event->client->id,
                    'name' => trim($event->client->first_name.' '.$event->client->last_name),
                ] : null);
                $site = $journey['site'] ?? ($event?->site ? [
                    'id' => $event->site->id,
                    'name' => $event->site->name,
                ] : null);
                // Eager loading applies the source reader once for the batch. A
                // copied task description remains source-owned in every queue view.
                $sourceTask = $action->sourceControlRoomTask;
                $canReadSourceTask = $sourceTask?->alert
                    && $event
                    && (int) $sourceTask->alert_id === (int) $event->control_room_alert_id;
                if ($canReadSourceTask) {
                    try {
                        app(ControlRoomAlertProvenanceService::class)->assertHealthSafetyEventTuple($sourceTask->alert, $event);
                    } catch (\DomainException) {
                        $canReadSourceTask = false;
                    }
                }
                $description = $action->source_control_room_task_id !== null && ! $canReadSourceTask
                    ? null
                    : $action->description;

                return new TaskItem(
                    id: 'corrective_action-'.$action->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $action->reference_number,
                    title: $action->title ?: 'Corrective action',
                    status: (string) $action->status,
                    bucket: match ($action->status) {
                        HsCorrectiveAction::STATUS_CLOSED => TaskItem::BUCKET_DONE,
                        HsCorrectiveAction::STATUS_IN_PROGRESS,
                        HsCorrectiveAction::STATUS_COMPLETED,
                        HsCorrectiveAction::STATUS_VERIFIED => TaskItem::BUCKET_IN_PROGRESS,
                        default => TaskItem::BUCKET_OPEN,
                    },
                    severity: TaskItem::normaliseSeverity($action->priority),
                    assignee: $action->assignedTo
                        ? ['id' => $action->assignedTo->id, 'name' => $action->assignedTo->name]
                        : null,
                    client: $client,
                    site: $site,
                    dueAt: optional($action->due_date)->toIso8601String(),
                    createdAt: optional($action->created_at)->toIso8601String(),
                    link: "/health-safety/corrective-actions/{$action->id}",
                    type: 'Corrective action',
                    description: $description ? str($description)->limit(140)->toString() : null,
                    journey: $journey,
                    sourceContext: 'Health & Safety',
                    actionLabel: match ($action->status) {
                        HsCorrectiveAction::STATUS_OPEN => 'Start corrective action',
                        HsCorrectiveAction::STATUS_IN_PROGRESS => 'Complete corrective action',
                        HsCorrectiveAction::STATUS_COMPLETED => 'Verify corrective action',
                        HsCorrectiveAction::STATUS_VERIFIED => 'Close corrective action',
                        default => 'Review corrective action',
                    },
                    // B1.1: the action's one status, the same words as the board, list and record.
                    displayState: \App\Support\HealthSafety\HsLabels::actionStatus((string) $action->status, $action->effectiveness_confirmed),
                    searchTerms: $includeSearchContext
                        ? array_values(array_filter([
                            $event?->reference_number,
                            $action->assignedTo?->name,
                            $canReadSourceTask ? $sourceTask->title : null,
                            $canReadSourceTask ? $sourceTask->description : null,
                            $action->hsInvestigation?->reference_number,
                        ]))
                        : [],
                    actionHelp: match ($action->status) {
                        HsCorrectiveAction::STATUS_COMPLETED => 'A different H&S manager must review the retained evidence.',
                        HsCorrectiveAction::STATUS_VERIFIED => 'The evidence is verified; close the action to finish the responsibility.',
                        default => 'Open the action to manage work or hand over its accountable owner.',
                    },
                );
            },
        );
    }
}
