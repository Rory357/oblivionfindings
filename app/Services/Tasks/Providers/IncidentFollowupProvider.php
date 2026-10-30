<?php

namespace App\Services\Tasks\Providers;

use App\Models\IncidentFollowup;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Incidents\IncidentTaskReadService;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Services\Tasks\TaskSearch;
use Illuminate\Validation\ValidationException;

class IncidentFollowupProvider implements ContextualHealthSafetyTaskProvider, AssignableTaskProvider, HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'followup';
    }

    public function label(): string
    {
        return 'Incident Follow-ups';
    }

    public function modelClass(): string
    {
        return IncidentFollowup::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors routes/incidents.php: followup writes → permission:incidents.followups.manage
        // (IncidentFollowupPolicy::update).
        return $user->canDo('incidents.followups.manage');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        // Re-fetch with the same viewAssigned client scoping authorizedTasks() applies.
        $followup = IncidentFollowup::query()
            ->whereHas('incident', fn ($query) => app(IncidentAccessService::class)->apply($query, $actor))
            ->find($id);

        if (! $followup) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Follow-up not found or outside your assigned clients.',
            ]);
        }

        // Audit guardrail mirrored from IncidentFollowupController::update().
        if (! empty($followup->completed_at)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'A completed follow-up cannot be modified.',
            ]);
        }

        $followup->update(['assigned_to_user_id' => $assigneeId]);
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/incidents.php: permission:incidents.viewAny|incidents.viewAssigned.
        return $user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        try {
            return HealthSafetyReadContext::within($user, array_unique([
                ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
                ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS,
            ]), fn (HealthSafetyReadContext $context): array => $this->authorizedTasksInContext($user, $filters, $context));
        } catch (HttpException $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
            return [];
        }
    }

    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(IncidentTaskReadService::PERMISSIONS,
            fn (User $current, CurrentAuthorizationReads $reads): array => $this->materialize($current, $filters, $context, $reads));
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(IncidentTaskReadService::PERMISSIONS,
            fn (User $current, CurrentAuthorizationReads $reads): array => $this->materialize($current, [], $context, $reads, true));
    }
    private function materialize(User $user, array $filters, HealthSafetyReadContext $context, CurrentAuthorizationReads $reads, bool $navigation = false): array
    {
        $leaf = app(IncidentTaskReadService::class);
        $includeSearchContext = ! $navigation && TaskSearch::hasQuery($filters);
        $with = [
            $includeSearchContext
                ? 'incident:id,reference_number,title,description,immediate_action_taken,immediate_action,witnesses,potential_consequence,client_id,site_id,hs_event_id,control_room_alert_id,investigation_assigned_to,source,occurred_at'
                : 'incident:id,reference_number,title,client_id,site_id,hs_event_id,control_room_alert_id,source,occurred_at',
            'incident.site:id,name',
            'incident.client:id,first_name,last_name',
            $includeSearchContext
                ? 'incident.controlRoomAlert:id,reference_number,assigned_to_user_id'
                : 'incident.controlRoomAlert:id,reference_number',
            $includeSearchContext
                ? 'incident.hsEvent:id,reference_number,owner_user_id'
                : 'incident.hsEvent:id,reference_number',
            'assignedTo:id,name',
        ];

        if ($includeSearchContext) {
            array_push(
                $with,
                'incident.investigator:id,name',
                'incident.followups:id,client_incident_id,assigned_to_user_id',
                'incident.followups.assignedTo:id,name',
                'incident.controlRoomAlert.assignedTo:id,name',
                'incident.controlRoomAlert.tasks:id,alert_id,title,description,assigned_to_user_id',
                'incident.controlRoomAlert.tasks.assignedTo:id,name',
                'incident.hsEvent.owner:id,name',
                'incident.hsEvent.investigations:id,hs_event_id,reference_number,lead_investigator_id',
                'incident.hsEvent.investigations.leadInvestigator:id,name',
                'incident.hsEvent.correctiveActions:id,hs_event_id,reference_number,assigned_to_user_id',
                'incident.hsEvent.correctiveActions.assignedTo:id,name',
            );
        }

        $parents = $leaf->followupParents($user, $reads, $filters);
        $query = IncidentFollowup::query()->whereIn('client_incident_id', (clone $parents)->reorder()->select('client_incidents.id'))
            ->with($leaf->relations($navigation ? [] : $with, $reads, $parents))
            ->when(
                $includeSearchContext,
                fn ($q) => $q->whereHas(
                    'incident',
                    fn ($incident) => TaskSearch::applyIncidentJourneyPredicate($incident, $filters),
                ),
            )
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('created_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if ($navigation) { $query->select(['id', 'client_incident_id', 'assigned_to_user_id', 'completed_at', 'due_at', 'created_at']); }
        if (empty($filters['include_done'])) {
            $query->whereNull('completed_at');
        }

        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            fn ($scoped, User $actor) => $leaf->current($scoped, $reads),
            function (IncidentFollowup $followup) use ($includeSearchContext, $user, $context, $reads, $parents, $leaf, $navigation) {
                if (! $leaf->isStillFollowup($followup, $parents, $reads)) { return null; }
                if ($navigation) {
                    if (! $leaf->isStillFollowup($followup, $parents, $reads)) { return null; }
                    return new TaskItem(id: 'followup-'.$followup->id, source: $this->sourceKey(), sourceLabel: $this->label(), ref: null, title: '',
                        status: $followup->completed_at ? 'completed' : 'open', bucket: $followup->completed_at ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN, severity: 'info',
                        assignee: $followup->assigned_to_user_id ? ['id' => (int) $followup->assigned_to_user_id, 'name' => ''] : null, dueAt: $followup->due_at?->toIso8601String());
                }
                $incident = $followup->incident;
                if ($incident === null) { return null; }
                $journey = IncidentJourneyTaskContext::make(
                    $incident,
                    $user,
                    includeSearchContext: $includeSearchContext,
                    readContext: $context,
                );

                if (! $leaf->isStillFollowup($followup, $parents, $reads) || ! $leaf->isStillIncident($incident, $parents, $reads)) { return null; }
                return new TaskItem(
                    id: 'followup-'.$followup->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $incident?->reference_number,
                    title: $incident?->title
                        ? 'Follow-up — '.$incident->title
                        : 'Incident follow-up',
                    status: $followup->completed_at ? 'completed' : 'open',
                    bucket: $followup->completed_at ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN,
                    severity: 'medium',
                    assignee: $followup->assignedTo
                        ? ['id' => $followup->assignedTo->id, 'name' => $followup->assignedTo->name]
                        : null,
                    client: $journey['person'] ?? null,
                    site: $journey['site'] ?? null,
                    dueAt: optional($followup->due_at)->toIso8601String(),
                    createdAt: optional($followup->created_at)->toIso8601String(),
                    link: "/incidents/{$followup->client_incident_id}?section=followups",
                    type: 'Follow-up',
                    description: $followup->notes ? str($followup->notes)->limit(140)->toString() : null,
                    journey: $journey,
                    sourceContext: 'Incident follow-up',
                    actionLabel: 'Complete incident follow-up',
                );
            },
        );
    }
}
