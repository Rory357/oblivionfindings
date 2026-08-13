<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsCorrectiveAction;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Services\Tasks\TaskSearch;
use App\Services\UserSiteAccessService;
use Illuminate\Validation\ValidationException;

class HsCorrectiveActionProvider implements AssignableTaskProvider, HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites'];

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

    public function canAssign(User $user): bool
    {
        // Mirrors routes/health-safety.php: all corrective-action writes sit
        // behind permission:hazards.manage.
        return $user->canDo('hazards.manage');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        $access = app(UserSiteAccessService::class);
        $action = HsCorrectiveAction::query()
            ->with('hsEvent')
            ->whereHas('hsEvent', fn ($query) => $access->applyHsEventScope(
                $query,
                $actor,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->find($id);

        if (! $action) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Corrective action not found.',
            ]);
        }

        if ($action->status === HsCorrectiveAction::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'assignee_id' => 'A closed corrective action cannot be reassigned.',
            ]);
        }

        if ($assigneeId !== null) {
            $assignee = User::query()
                ->whereKey($assigneeId)
                ->tap(fn ($query) => $access->applyHsEventStaffScope(
                    $query,
                    $action->hsEvent,
                    $actor,
                    self::SITE_BYPASS_PERMISSIONS,
                ))
                ->first();

            if (! $assignee) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'That staff member is not eligible for this event site.',
                ]);
            }
        }

        // Same side-effect columns HsCorrectiveActionService stamps on
        // create/start: assignee + who assigned it + when.
        $action->update([
            'assigned_to_user_id' => $assigneeId,
            'assigned_by_user_id' => $assigneeId !== null ? $actor->id : null,
            'assigned_at' => $assigneeId !== null ? now() : null,
            'updated_by' => $actor->id, // module service stamps this on every write
        ]);
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $includeSearchContext = TaskSearch::hasQuery($filters);
        $with = [
            'assignedTo:id,name',
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
                'sourceControlRoomTask:id,title,description',
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
            ->with($with)
            ->when(
                $includeSearchContext,
                fn ($q) => $q->whereHas(
                    'hsEvent.clientIncident',
                    fn ($incident) => TaskSearch::applyIncidentJourneyPredicate($incident, $filters),
                ),
            )
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('created_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if (empty($filters['include_done'])) {
            $query->where('status', '!=', HsCorrectiveAction::STATUS_CLOSED);
        }

        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            fn ($scoped, User $actor) => $scoped->whereHas(
                'hsEvent',
                fn ($events) => app(UserSiteAccessService::class)->applyHsEventScope(
                    $events,
                    $actor,
                    self::SITE_BYPASS_PERMISSIONS,
                ),
            ),
            function (HsCorrectiveAction $action) use ($includeSearchContext) {
                $event = $action->hsEvent;
                $journey = IncidentJourneyTaskContext::make(
                    $event?->clientIncident,
                    $event?->controlRoomAlert,
                    $event,
                    $includeSearchContext,
                );
                $client = $journey['person'] ?? ($event?->client ? [
                    'id' => $event->client->id,
                    'name' => trim($event->client->first_name.' '.$event->client->last_name),
                ] : null);
                $site = $journey['site'] ?? ($event?->site ? [
                    'id' => $event->site->id,
                    'name' => $event->site->name,
                ] : null);

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
                    link: "/health-safety/corrective-actions?event={$action->hs_event_id}",
                    type: 'Corrective action',
                    description: $action->description ? str($action->description)->limit(140)->toString() : null,
                    journey: $journey,
                    sourceContext: 'Health & Safety',
                    actionLabel: match ($action->status) {
                        HsCorrectiveAction::STATUS_OPEN => 'Start corrective action',
                        HsCorrectiveAction::STATUS_IN_PROGRESS => 'Complete corrective action',
                        HsCorrectiveAction::STATUS_COMPLETED => 'Verify corrective action',
                        HsCorrectiveAction::STATUS_VERIFIED => 'Close corrective action',
                        default => 'Review corrective action',
                    },
                    displayState: match ($action->status) {
                        HsCorrectiveAction::STATUS_OPEN => 'Not started',
                        HsCorrectiveAction::STATUS_IN_PROGRESS => 'In progress',
                        HsCorrectiveAction::STATUS_COMPLETED => 'Awaiting independent verification',
                        HsCorrectiveAction::STATUS_VERIFIED => 'Verified — ready to close',
                        HsCorrectiveAction::STATUS_CLOSED => 'Closed',
                        default => ucfirst(str_replace('_', ' ', (string) $action->status)),
                    },
                    searchTerms: $includeSearchContext
                        ? array_values(array_filter([
                            $action->assignedTo?->name,
                            $action->sourceControlRoomTask?->title,
                            $action->sourceControlRoomTask?->description,
                            $action->hsInvestigation?->reference_number,
                        ]))
                        : [],
                    actionHelp: match ($action->status) {
                        HsCorrectiveAction::STATUS_COMPLETED => 'A different H&S manager must review the retained evidence.',
                        HsCorrectiveAction::STATUS_VERIFIED => 'The evidence is verified; close the action to finish the responsibility.',
                        default => null,
                    },
                );
            },
        );
    }
}
