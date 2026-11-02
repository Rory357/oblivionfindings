<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsEvent;
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

class HsEventProvider implements ContextualHealthSafetyTaskProvider, HasModelClass, SiteScopedTaskProvider, TaskProvider
{
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
            'client:id,first_name,last_name',
            'site:id,name',
            'owner:id,name',
            $includeSearchContext
                ? 'controlRoomAlert:id,reference_number,assigned_to_user_id'
                : 'controlRoomAlert:id,reference_number',
            $includeSearchContext
                ? 'clientIncident:id,client_id,site_id,hs_event_id,investigation_assigned_to,reference_number,source,occurred_at,title,description,immediate_action_taken,immediate_action,witnesses,potential_consequence'
                : 'clientIncident:id,client_id,site_id,hs_event_id,reference_number,source,occurred_at,title',
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
            ->with($navigation ? [] : $with)
            ->when(
                $includeSearchContext,
                fn ($q) => $q->where(fn ($match) => $match
                    ->whereHas('clientIncident', fn ($incident) => TaskSearch::applyIncidentJourneyPredicate($incident, $filters))
                    ->orWhere(fn ($own) => TaskSearch::applyRecordPredicate($own, $filters,
                        ['reference_number', 'event_category', 'severity', 'status'],
                        ['owner' => ['name'], 'client' => ['first_name', 'last_name'], 'site' => ['name']],
                    ))),
            )
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('occurred_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        $query = $this->scopeEvents($query);
        if (empty($filters['include_done']) && ! $this->includesClosedEvents()) {
            $query->where('status', '!=', HsEvent::STATUS_CLOSED);
        }

        if ($reads !== null) {
            $query = $reads->query($query);
        }

        return app(TaskProviderAuthorization::class)->siteScoped(
            $current,
            $this->canView($current),
            $query,
            function ($scoped, User $actor) use ($context) {
                if ($context === null) {
                    throw new LogicException('H&S task source discovery requires its active prepared callback.');
                }
                $context->assertViewer($actor);
                // siteScoped delegates this boundary; its incoming query has no Site proof yet.
                $candidates = $context->applyEventSiteScope(clone $scoped,
                    UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);

                return app(HsEventAccessService::class)->applyReadableScope($scoped, $actor, $context, $candidates);
            },
            function (HsEvent $event) use ($includeSearchContext, $user, $context, $current, $navigation) {
                if ($navigation) {
                    // Preserve the canonical query, ordering and cap before badge eligibility.
                    return $this->present(new TaskItem(id: 'hs_event-'.$event->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                        ref: null, title: '', status: (string) $event->status, bucket: match ($event->status) {
                            HsEvent::STATUS_CLOSED => TaskItem::BUCKET_DONE, HsEvent::STATUS_INVESTIGATING, HsEvent::STATUS_CORRECTIVE_ACTION, HsEvent::STATUS_MONITORING => TaskItem::BUCKET_IN_PROGRESS, default => TaskItem::BUCKET_OPEN
                        },
                        severity: 'info', assignee: (int) $event->owner_user_id === (int) $current->id ? ['id' => $current->id] : null,
                        dueAt: null), $event, true);
                }

                $journey = IncidentJourneyTaskContext::make(
                    $event->clientIncident,
                    $user,
                    $event->controlRoomAlert,
                    $event,
                    $includeSearchContext,
                    $context,
                );
                $client = $journey['person'] ?? ($event->client ? [
                    'id' => $event->client->id,
                    'name' => trim($event->client->first_name.' '.$event->client->last_name),
                ] : null);
                $site = $journey['site'] ?? ($event->site ? [
                    'id' => $event->site->id,
                    'name' => $event->site->name,
                ] : null);

                return $this->present(new TaskItem(
                    id: 'hs_event-'.$event->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $event->reference_number,
                    // B1.1/B7: the same title as the record. The incident's words only with a journey (the viewer can read
                    // the incident); exposures and safeguarding say kind and site here (the person shows on the record to readers).
                    title: \App\Support\HealthSafety\HsReviewTitle::make((string) $event->event_category,
                        $journey !== null || ! in_array(class_basename((string) $event->source_type), ['ClientIncident', 'SubstanceExposureRecord', 'SafeguardingConcern'], true),
                        $journey !== null ? $event->clientIncident?->title : null, $client['name'] ?? null, null, $site['name'] ?? null),
                    status: (string) $event->status,
                    displayState: \App\Support\HealthSafety\HsLabels::label('review_status', (string) $event->status),
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
                ), $event, false);
            },
        );
    }

    /** A narrower list of the same readable events (HsEventReviewFlagProvider). */
    protected function scopeEvents(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query;
    }

    protected function includesClosedEvents(): bool
    {
        return false;
    }

    protected function present(TaskItem $item, HsEvent $event, bool $navigation): TaskItem
    {
        return $item;
    }
}
