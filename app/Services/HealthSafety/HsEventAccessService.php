<?php

namespace App\Services\HealthSafety;

use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Support\HealthSafety\QueryPaging;

/** The same read boundary for a direct H&S event and its linked projections.
 * Source scopes are consumed and materialized inside the caller transaction.
 */
final class HsEventAccessService
{
    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function applyReadableScope(Builder $query, User $viewer, ?HealthSafetyReadContext $context = null, ?Builder $sourceCandidates = null): Builder
    {
        if ($context !== null) {
            $context->assertViewer($viewer);

            return $context->consumeSourceViewer(FirstAidAccessService::PERMISSIONS, function ($current) use ($query, $context, $sourceCandidates) {
                if (! $current->canDo('hazards.view')) {
                    return $context->query($query->whereRaw('1 = 0'));
                }
                $context->applyEventSiteScope($query, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);

                return $this->applyFirstAidSourceScope($query, $current, $context, $sourceCandidates);
            });
        }
        if (! $viewer->canDo('hazards.view')) {
            return $query->whereRaw('1 = 0');
        }

        $this->sites->applyHsEventScope(
            $query,
            $viewer,
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );

        return $this->applyFirstAidSourceScope($query, $viewer, $context, $sourceCandidates);
    }

    /** Confidential treatment and restraint reviews intersect their source policy; unrelated sources retain theirs. */
    public function applyFirstAidSourceScope(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null, ?Builder $sourceCandidates = null): Builder
    {
        $types = array_unique([FirstAidRecord::class, (new FirstAidRecord)->getMorphClass()]);
        if ($context !== null) {
            if ($viewer === null) {
                return $this->applyRestraintSourceScope($context->query($query->where(fn (Builder $source) => $source->whereNull('hs_events.source_type')->orWhereNotIn('hs_events.source_type', $types))), null, $context, $sourceCandidates);
            }
            $context->assertViewer($viewer);

            return $this->applyRestraintSourceScope($context->applyFirstAidEventScope($query, $types, $sourceCandidates), $viewer, $context, $sourceCandidates);
        }
        $ids = [];
        try {
            $access = app(FirstAidAccessService::class);
            if ($viewer !== null) {
                $ids = $access->withCurrentViewer($viewer, fn ($current, $reads, $sites) => $this->readFirstAidEventIds($current, $reads, $sites, $types, null, $sourceCandidates));
            }
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 403) {
                throw $error;
            }
            $ids = [];
        }

        $query->where(function (Builder $sources) use ($types, $ids): void {
            $sources->whereNull('hs_events.source_type')->orWhereNotIn('hs_events.source_type', $types)
                ->orWhereIn('hs_events.id', $ids);
        });

        return $this->applyRestraintSourceScope($query, $viewer, $context, $sourceCandidates);
    }

    /** Confidential restraint facts never inherit permission from their derived H&S row. */
    public function applyRestraintSourceScope(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null, ?Builder $sourceCandidates = null): Builder
    {
        $types = array_unique([RestraintEvent::class, (new RestraintEvent)->getMorphClass()]);
        $ids = [];
        // A correlated relation scope supplies independent candidates; final correlation remains intact.
        // Discover only requested rows, but prove every matching source before final pagination.
        $candidates = (clone ($sourceCandidates ?? $query))->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))
            ->whereIn('hs_events.source_type', $types)
            ->select(['hs_events.id', 'hs_events.source_id', 'hs_events.client_id', 'hs_events.site_id', 'hs_events.staff_id', 'hs_events.event_category']);
        $candidates = ($context ? $context->query($candidates) : $candidates)->get();
        if ($viewer !== null && $candidates->isNotEmpty()) {
            $context?->assertViewer($viewer);
            $access = app(RestraintAccessService::class);
            try {
                $consume = function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($access, $candidates): array {
                    if (! $current->canDo('hazards.view') || ! $current->canDo('restraints.view')) {
                        return [];
                    }
                    $candidates = $reads->query(HsEvent::query())->whereKey($candidates->pluck('id'))
                        ->select(['id', 'source_id', 'client_id', 'site_id', 'staff_id', 'event_category'])->get();
                    $sources = $reads->query(RestraintEvent::query())->whereIn('id', $candidates->pluck('source_id'))
                        ->select(['id', 'client_id', 'site_id', 'stay_id', 'created_by', 'source_schema_version', 'recorded_client_id', 'recorded_site_id', 'recorded_stay_id',
                            'behaviour_support_plan_id', 'behaviour_support_plan_version_id'])->get()->keyBy('id');
                    $allowed = [];
                    foreach ($candidates as $event) {
                        $source = $sources->get($event->source_id);
                        if (! $source || $event->event_category !== HsEvent::CATEGORY_RESTRAINT
                            || (int) $event->client_id !== (int) $source->client_id
                            || ($event->site_id === null ? null : (int) $event->site_id) !== ($source->site_id === null ? null : (int) $source->site_id)
                            || ($event->staff_id === null ? null : (int) $event->staff_id) !== ($source->created_by === null ? null : (int) $source->created_by)) {
                            continue;
                        }
                        try {
                            // Includes recorded and current stay provenance; HsEvent has no stay column.
                            $access->assertEvent($source, $current, $reads, $sites, 'view');
                            $allowed[] = (int) $event->id;
                        } catch (HttpException $error) {
                            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                                throw $error;
                            }
                        }
                    }

                    return $allowed;
                };
                $ids = $context ? $access->withReadContext($context, $consume) : $access->withCurrentViewer($viewer, $consume);
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
            }
        }
        $query->where(function (Builder $sources) use ($types, $ids): void {
            $sources->whereNull('hs_events.source_type')->orWhereNotIn('hs_events.source_type', $types)->orWhereIn('hs_events.id', $ids);
        });

        return $this->applyInjurySourceScope($query, $viewer, $context, $sourceCandidates);
    }

    /** A governance row cannot grant access to the factual injury that produced it. */
    public function applyInjurySourceScope(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null, ?Builder $sourceCandidates = null): Builder
    {
        $types = array_values(array_unique([WorkplaceInjury::class, (new WorkplaceInjury)->getMorphClass()]));
        if ($viewer !== null && $context !== null) {
            $context->assertViewer($viewer);

            return $this->applyIncidentSourceScope($context->applyInjuryEventScope($query, $sourceCandidates), $viewer, $context, $sourceCandidates);
        }
        $ids = [];
        if ($viewer !== null) {
            $candidates = (clone ($sourceCandidates ?? $query))->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...));
            try {
                $ids = HealthSafetyReadContext::within($viewer, InjuryAccessService::PERMISSIONS,
                    fn (HealthSafetyReadContext $reads): array => app(InjurySourceProjectionService::class)
                        ->readableHsEventIdsInContext($viewer, $candidates, $reads));
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
            }
        }
        $query->where(fn (Builder $sources) => $sources->whereNull('hs_events.source_type')
            ->orWhereNotIn('hs_events.source_type', $types)->orWhereIn('hs_events.id', $ids));

        return $this->applyIncidentSourceScope($query, $viewer, $context, $sourceCandidates);
    }

    /** Incident-derived HSEs never inherit their original source permission from hazards.view. */
    public function applyIncidentSourceScope(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null, ?Builder $sourceCandidates = null): Builder
    {
        if ($context !== null && $viewer !== null) {
            $context->assertViewer($viewer);
            return $context->applyIncidentEventScope($query, $sourceCandidates);
        }
        // Standalone consumers must wrap actual materialisation in prepared evidence; no expired predicate or ID receipt is returned.
        return app(\App\Services\Incidents\IncidentHsEventSourceReadService::class)->withoutIncidentSources($query);
    }

    private function deniedIncidentEvents(?User $viewer, ?HealthSafetyReadContext $context, Builder $sourceCandidates): Builder
    {
        $source = app(\App\Services\Incidents\IncidentHsEventSourceReadService::class);
        $related = $source->incidentRelated(HsEvent::withTrashed());
        if ($context === null || $viewer === null) { return $related->select('hs_events.id'); }
        $context->assertViewer($viewer);
        $candidateIds = (clone $sourceCandidates)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->select('hs_events.id');
        $allowed = $context->applyIncidentEventScope(HsEvent::query()->whereIn('hs_events.id', clone $candidateIds), $sourceCandidates);
        // Retained SQL, not a plucked whitelist: late original-parent/authority changes still deny children.
        $context->reconsumeIncidentPredicate($related, $allowed);

        return $context->query($related->whereNotIn('hs_events.id', $allowed->select('hs_events.id'))->select('hs_events.id'));
    }

    /** Exact treatment/event tuple, shared by fresh public reads and bounded context reads. */
    public function readFirstAidEventIds(User $current, CurrentAuthorizationReads $reads, array $sites, array $types, ?HealthSafetyReadContext $context = null, ?Builder $sourceCandidates = null): array
    {
        $reads->assertActive();
        $context?->assertViewer($current);
        $access = app(FirstAidAccessService::class);
        $sources = $access->readableQuery($current, $reads, $sites, true, $context)
            ->selectRaw('1')->whereColumn('first_aid_records.id', 'hs_events.source_id')
            ->whereColumn('first_aid_records.site_id', 'hs_events.site_id')
            ->where(function (Builder $subject): void {
                $subject->where(function (Builder $client): void {
                    $client->where('treated_person_type', 'client')->whereColumn('first_aid_records.client_id', 'hs_events.client_id')->whereNull('hs_events.staff_id');
                })->orWhere(function (Builder $staff): void {
                    $staff->where('treated_person_type', 'staff')->whereNull('hs_events.client_id')->where(function (Builder $identity): void {
                        $identity->whereColumn('first_aid_records.treated_person_id', 'hs_events.staff_id')
                            ->orWhere(fn (Builder $unlinked) => $unlinked->whereNull('first_aid_records.treated_person_id')->whereNull('hs_events.staff_id'));
                    });
                })->orWhere(function (Builder $other): void {
                    $other->whereIn('treated_person_type', ['visitor', 'contractor'])->whereNull('hs_events.client_id')->whereNull('hs_events.staff_id');
                });
            });

        $events = HsEvent::query()->whereIn('source_type', $types)->where('event_category', HsEvent::CATEGORY_INJURY);
        if ($sourceCandidates !== null) {
            // The caller supplies an independently executable query; keep its
            // exact identity, Site and source filters before treatment proof.
            $candidateIds = (clone $sourceCandidates)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->select('hs_events.id');
            $events->whereIn('hs_events.id', $candidateIds);
        }

        return $reads->query($events)->whereExists($sources->toBase())->pluck('hs_events.id')->all();
    }

    /** Preserve unrelated and orphan source rows while denying known confidential-source work. */
    public function applyFirstAidChildSourceScope(Builder $query, string $eventColumn, ?User $viewer, ?HealthSafetyReadContext $context = null, ?Builder $sourceCandidates = null): Builder
    {
        $sourceCandidates ??= $this->childSourceCandidates($query, $eventColumn);
        $denied = $this->deniedFirstAidEvents($viewer, $context, $sourceCandidates);

        $query->where(fn (Builder $source) => $source->whereNull($eventColumn)->orWhereNotIn($eventColumn, $denied));
        $incidentDenied = $this->deniedIncidentEvents($viewer, $context, $sourceCandidates);
        $query->where(fn (Builder $source) => $source->whereNull($eventColumn)->orWhereNotIn($eventColumn, $incidentDenied));
        if ($context !== null) {
            $context->reconsumeIncidentPredicate($query, $incidentDenied);
        }

        return $context !== null ? $context->query($query) : $query;
    }

    /** Both modern additional provenance and the retained legacy event attachment are binding. */
    public function applyFirstAidRiskSourceScope(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null, ?Builder $sourceCandidates = null): Builder
    {
        $types = array_unique([HsEvent::class, (new HsEvent)->getMorphClass()]);
        $sourceCandidates ??= $this->riskSourceCandidates($query, $types);
        $denied = $this->deniedFirstAidEvents($viewer, $context, $sourceCandidates);
        $eventColumn = $query->qualifyColumn('hs_event_id');
        $query->where(fn (Builder $source) => $source->whereNull($eventColumn)->orWhereNotIn($eventColumn, clone $denied));

        $incidentDenied = $this->deniedIncidentEvents($viewer, $context, $sourceCandidates);
        if ($context !== null) {
            $context->reconsumeIncidentPredicate($query, $incidentDenied);
        }
        $query->where(fn (Builder $source) => $source->whereNull($eventColumn)->orWhereNotIn($eventColumn, clone $incidentDenied));
        $query->where(fn (Builder $source) => $source->whereNull($query->qualifyColumn('assessable_type'))->orWhereNotIn($query->qualifyColumn('assessable_type'), $types)->orWhereNotIn($query->qualifyColumn('assessable_id'), $incidentDenied));

        $query->where(function (Builder $source) use ($query, $types, $denied): void {
            $source->whereNull($query->qualifyColumn('assessable_type'))
                ->orWhereNotIn($query->qualifyColumn('assessable_type'), $types)
                ->orWhereNotIn($query->qualifyColumn('assessable_id'), $denied);
        });

        return $context !== null ? $context->query($query) : $query;
    }

    /** Standalone wrappers discover only event identities bound to their already filtered children. */
    private function childSourceCandidates(Builder $query, string $eventColumn): Builder
    {
        $children = (clone $query)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))
            ->whereNotNull($eventColumn)->select($eventColumn);

        return HsEvent::withTrashed()->whereIn('hs_events.id', $children);
    }

    /** Both retained event bindings are authoritative; unrelated morph IDs are not event IDs. */
    private function riskSourceCandidates(Builder $query, array $types): Builder
    {
        $legacy = (clone $query)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))
            ->whereNotNull($query->qualifyColumn('hs_event_id'))->select($query->qualifyColumn('hs_event_id'));
        $modern = (clone $query)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))
            ->whereIn($query->qualifyColumn('assessable_type'), $types)
            ->whereNotNull($query->qualifyColumn('assessable_id'))->select($query->qualifyColumn('assessable_id'));

        return HsEvent::withTrashed()->where(fn (Builder $events) => $events
            ->whereIn('hs_events.id', $legacy)->orWhereIn('hs_events.id', $modern));
    }

    private function deniedFirstAidEvents(?User $viewer, ?HealthSafetyReadContext $context, Builder $sourceCandidates): Builder
    {
        $types = array_unique([FirstAidRecord::class, (new FirstAidRecord)->getMorphClass(), RestraintEvent::class, (new RestraintEvent)->getMorphClass(),
            WorkplaceInjury::class, (new WorkplaceInjury)->getMorphClass()]);
        $candidateIds = (clone $sourceCandidates)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->select('hs_events.id');
        $allowed = $this->applyFirstAidSourceScope(HsEvent::query()->whereIn('hs_events.id', clone $candidateIds), $viewer, $context, $sourceCandidates)
            ->whereIn('hs_events.source_type', $types)->pluck('hs_events.id')->all();

        // Final SQL denial remains complete even if a supplied discovery candidate omits a binding.
        // This subquery reads identities only; no unrelated private source models are materialized.
        return HsEvent::withTrashed()->whereIn('hs_events.source_type', $types)
            ->whereNotIn('hs_events.id', $allowed)->select('hs_events.id')->lock('for share nowait');
    }

    public function canView(HsEvent $event, User $viewer, ?HealthSafetyReadContext $context = null): bool
    {
        if ($context === null) {
            try {
                return HealthSafetyReadContext::within($viewer, [...FirstAidAccessService::PERMISSIONS,
                    ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS],
                    fn (HealthSafetyReadContext $reads): bool => $reads->consumeSourceViewer(FirstAidAccessService::PERMISSIONS,
                        fn (User $current): bool => $this->canView($event, $current, $reads)));
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }

                return false;
            }
        }

        $context->assertViewer($viewer);
        $candidates = $context->applyEventSiteScope(HsEvent::query()->whereKey($event->id),
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);

        return $this->applyReadableScope(clone $candidates, $viewer, $context, $candidates)->exists();
    }
}
