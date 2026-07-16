<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsEvent;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskSearch;
use App\Services\UserSiteAccessService;

class HsEventProvider implements HasModelClass, TaskProvider
{
    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites'];

    public function sourceKey(): string
    {
        return 'hs_event';
    }

    public function label(): string
    {
        return 'H&S Events';
    }

    public function modelClass(): string
    {
        return HsEvent::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $includeSearchContext = TaskSearch::hasQuery($filters);
        $with = [
            'client:id,first_name,last_name',
            'site:id,name',
            'owner:id,name',
            $includeSearchContext
                ? 'controlRoomAlert:id,reference_number,assigned_to_user_id'
                : 'controlRoomAlert:id,reference_number',
            $includeSearchContext
                ? 'clientIncident:id,client_id,site_id,hs_event_id,investigation_assigned_to,reference_number,source,occurred_at,title,description,immediate_action_taken,immediate_action,witnesses,potential_consequence'
                : 'clientIncident:id,client_id,site_id,hs_event_id,reference_number,source,occurred_at',
            'clientIncident.client:id,first_name,last_name',
            'clientIncident.site:id,name',
        ];

        if ($includeSearchContext) {
            array_push(
                $with,
                'controlRoomAlert.assignedTo:id,name',
                'controlRoomAlert.tasks:id,alert_id,title,description,assigned_to_user_id',
                'controlRoomAlert.tasks.assignedTo:id,name',
                'investigations:id,hs_event_id,reference_number,lead_investigator_id',
                'investigations.leadInvestigator:id,name',
                'correctiveActions:id,hs_event_id,reference_number,assigned_to_user_id',
                'correctiveActions.assignedTo:id,name',
                'clientIncident.investigator:id,name',
                'clientIncident.followups:id,client_incident_id,assigned_to_user_id',
                'clientIncident.followups.assignedTo:id,name',
            );
        }

        $query = HsEvent::query()
            ->with($with)
            ->tap(fn ($q) => app(UserSiteAccessService::class)->applyHsEventScope(
                $q,
                $user,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->when(
                $includeSearchContext,
                fn ($q) => $q->whereHas(
                    'clientIncident',
                    fn ($incident) => TaskSearch::applyIncidentJourneyPredicate($incident, $filters),
                ),
            )
            ->orderByDesc('occurred_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if (empty($filters['include_done'])) {
            $query->where('status', '!=', HsEvent::STATUS_CLOSED);
        }

        return $query->get()->map(function (HsEvent $event) use ($includeSearchContext) {
            $journey = IncidentJourneyTaskContext::make(
                $event->clientIncident,
                $event->controlRoomAlert,
                $event,
                $includeSearchContext,
            );
            $client = $journey['person'] ?? ($event->client ? [
                'id' => $event->client->id,
                'name' => trim($event->client->first_name.' '.$event->client->last_name),
            ] : null);
            $site = $journey['site'] ?? ($event->site ? [
                'id' => $event->site->id,
                'name' => $event->site->name,
            ] : null);

            return new TaskItem(
                id: 'hs_event-'.$event->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $event->reference_number,
                title: ucfirst(str_replace('_', ' ', (string) $event->event_category)).' event',
                status: (string) $event->status,
                bucket: match ($event->status) {
                    HsEvent::STATUS_CLOSED => TaskItem::BUCKET_DONE,
                    HsEvent::STATUS_INVESTIGATING,
                    HsEvent::STATUS_CORRECTIVE_ACTION,
                    HsEvent::STATUS_MONITORING => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($event->severity),
                assignee: $event->owner ? ['id' => $event->owner->id, 'name' => $event->owner->name] : null,
                client: $client,
                site: $site,
                dueAt: null,
                createdAt: optional($event->created_at)->toIso8601String(),
                link: "/health-safety/events/{$event->id}",
                type: 'H&S event',
                description: null,
                journey: $journey,
                sourceContext: str_replace('_', ' ', (string) $event->event_category),
                actionLabel: $event->handover_status === HsEvent::HANDOVER_AWAITING_ACCEPTANCE
                    ? 'Accept H&S handover'
                    : 'Continue H&S governance',
            );
        })->all();
    }
}
