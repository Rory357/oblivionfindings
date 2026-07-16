<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsInvestigation;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskSearch;
use App\Services\UserSiteAccessService;

class HsInvestigationProvider implements HasModelClass, TaskProvider
{
    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites'];

    public function sourceKey(): string
    {
        return 'hs_investigation';
    }

    public function label(): string
    {
        return 'H&S Investigations';
    }

    public function modelClass(): string
    {
        return HsInvestigation::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $includeSearchContext = TaskSearch::hasQuery($filters);
        $with = [
            'leadInvestigator:id,name',
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

        $query = HsInvestigation::query()
            ->with($with)
            ->whereHas('hsEvent', fn ($q) => app(UserSiteAccessService::class)->applyHsEventScope(
                $q,
                $user,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->when(
                $includeSearchContext,
                fn ($q) => $q->whereHas(
                    'hsEvent.clientIncident',
                    fn ($incident) => TaskSearch::applyIncidentJourneyPredicate($incident, $filters),
                ),
            )
            ->orderByDesc('created_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', [HsInvestigation::STATUS_COMPLETED]);
        }

        return $query->get()->map(function (HsInvestigation $investigation) use ($includeSearchContext) {
            $event = $investigation->hsEvent;
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
                id: 'hs_investigation-'.$investigation->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $investigation->reference_number,
                title: ucfirst(str_replace('_', ' ', (string) $investigation->investigation_type)).' investigation',
                status: (string) $investigation->status,
                bucket: match ($investigation->status) {
                    HsInvestigation::STATUS_DRAFT => TaskItem::BUCKET_OPEN,
                    HsInvestigation::STATUS_COMPLETED => TaskItem::BUCKET_DONE,
                    default => TaskItem::BUCKET_IN_PROGRESS,
                },
                severity: 'medium',
                assignee: $investigation->leadInvestigator
                    ? ['id' => $investigation->leadInvestigator->id, 'name' => $investigation->leadInvestigator->name]
                    : null,
                client: $client,
                site: $site,
                dueAt: optional($investigation->target_completion_date)->toIso8601String(),
                createdAt: optional($investigation->created_at)->toIso8601String(),
                link: "/health-safety/events/{$investigation->hs_event_id}",
                type: 'Investigation',
                description: $investigation->findings_summary
                    ? str($investigation->findings_summary)->limit(140)->toString()
                    : null,
                journey: $journey,
                sourceContext: str_replace('_', ' ', (string) $investigation->investigation_type),
                actionLabel: 'Continue H&S investigation',
            );
        })->all();
    }
}
