<?php

namespace App\Services\Tasks\Providers;

use App\Models\IncidentFollowup;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskSearch;
use App\Services\UserSiteAccessService;
use Illuminate\Validation\ValidationException;

class IncidentFollowupProvider implements AssignableTaskProvider, HasModelClass, TaskProvider
{
    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites', 'reports.viewAny'];

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
        // Re-fetch with the same viewAssigned client scoping tasks() applies.
        $followup = IncidentFollowup::query()
            ->whereHas('incident', fn ($query) => app(UserSiteAccessService::class)->applyClientIncidentScope(
                $query,
                $actor,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->when(
                ! $actor->canDo('incidents.viewAny') && $actor->canDo('incidents.viewAssigned'),
                fn ($q) => $q->whereHas('incident.client.supportWorkers', fn ($qq) => $qq->whereKey($actor->id)),
            )
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

    public function tasks(User $user, array $filters = []): array
    {
        $includeSearchContext = TaskSearch::hasQuery($filters);
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

        $query = IncidentFollowup::query()
            ->with($with)
            ->whereHas('incident', fn ($q) => app(UserSiteAccessService::class)->applyClientIncidentScope(
                $q,
                $user,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            // Same viewAssigned client scoping as the incidents register.
            ->when(
                ! $user->canDo('incidents.viewAny') && $user->canDo('incidents.viewAssigned'),
                fn ($q) => $q->whereHas('incident.client.supportWorkers', fn ($qq) => $qq->whereKey($user->id)),
            )
            ->when(
                $includeSearchContext,
                fn ($q) => $q->whereHas(
                    'incident',
                    fn ($incident) => TaskSearch::applyIncidentJourneyPredicate($incident, $filters),
                ),
            )
            ->orderByDesc('created_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if (empty($filters['include_done'])) {
            $query->whereNull('completed_at');
        }

        return $query->get()->map(function (IncidentFollowup $followup) use ($includeSearchContext) {
            $incident = $followup->incident;
            $journey = IncidentJourneyTaskContext::make(
                $incident,
                includeSearchContext: $includeSearchContext,
            );

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
                link: "/incidents?incident={$followup->client_incident_id}",
                type: 'Follow-up',
                description: $followup->notes ? str($followup->notes)->limit(140)->toString() : null,
                journey: $journey,
                sourceContext: 'Incident follow-up',
                actionLabel: 'Complete incident follow-up',
            );
        })->all();
    }
}
