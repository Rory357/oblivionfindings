<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\User;
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

class HsInvestigationProvider implements ContextualHealthSafetyTaskProvider, HasModelClass, SiteScopedTaskProvider, TaskProvider
{
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
            ->with($navigation ? [] : $with)
            ->when(
                $includeSearchContext,
                fn ($q) => $q->where(fn ($match) => $match
                    ->whereHas('hsEvent.clientIncident', fn ($incident) => TaskSearch::applyIncidentJourneyPredicate($incident, $filters))
                    ->orWhere(fn ($own) => TaskSearch::applyRecordPredicate($own, $filters,
                        ['reference_number', 'investigation_type', 'status', 'findings_summary'],
                        [
                            'leadInvestigator' => ['name'], 'hsEvent' => ['reference_number'],
                            'hsEvent.client' => ['first_name', 'last_name'], 'hsEvent.site' => ['name'],
                        ],
                    ))),
            )
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('created_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', [HsInvestigation::STATUS_COMPLETED]);
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
            function (HsInvestigation $investigation) use ($includeSearchContext, $user, $context, $current, $navigation) {
                if ($navigation) {
                    // Preserve the canonical query, ordering and cap before badge eligibility.
                    return new TaskItem(id: 'hs_investigation-'.$investigation->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                        ref: null, title: '', status: (string) $investigation->status, bucket: match ($investigation->status) {
                            HsInvestigation::STATUS_DRAFT => TaskItem::BUCKET_OPEN, HsInvestigation::STATUS_COMPLETED => TaskItem::BUCKET_DONE, default => TaskItem::BUCKET_IN_PROGRESS
                        },
                        severity: 'info', assignee: (int) $investigation->lead_investigator_id === (int) $current->id ? ['id' => $current->id] : null,
                        dueAt: optional($investigation->target_completion_date)->toIso8601String());
                }

                $event = $investigation->hsEvent;
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
                    searchTerms: $includeSearchContext ? array_filter([$event?->reference_number, $investigation->findings_summary]) : [],
                );
            },
        );
    }
}
