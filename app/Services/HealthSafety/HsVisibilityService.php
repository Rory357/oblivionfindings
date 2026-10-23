<?php

namespace App\Services\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Read-only service for surfacing H&S data in non-H&S contexts.
 *
 * Used by Control Room, module pages, and anywhere that needs a
 * lightweight, shaped H&S summary without depending on H&S internals.
 *
 * All methods are strictly read-only. No mutations. No side effects.
 */
class HsVisibilityService
{
    private const PROJECTION_PERMISSIONS = ['compliance.view'];

    public function __construct(private readonly HsEventAccessService $access) {}

    /**
     * Shape an already resolved canonical event for another module.
     *
     * Restricted readers retain only the workflow dependency. Do not load or
     * serialize people, references, decisions or investigation detail for them.
     * The optional projector receives the current event and detached workflow
     * facts inside the same active read callback; its result must be scalar.
     */
    public function forEvent(?HsEvent $hsEvent, User $viewer, ?callable $projection = null): ?array
    {
        if (! $hsEvent) {
            return null;
        }

        // The caller supplies identity only. Neither loaded relations nor copied
        // facts are evidence for the source that exists at this read boundary.
        try {
            return HealthSafetyReadContext::within($viewer, [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
                ...InjuryAccessService::PERMISSIONS, ...self::PROJECTION_PERMISSIONS],
                function (HealthSafetyReadContext $context) use ($hsEvent, $viewer, $projection): array {
                    $current = $this->access->applyReadableScope(HsEvent::query()->whereKey($hsEvent->id), $viewer, $context)->first();
                    if (! $current) {
                        // Independent workflow visibility still requires current
                        // staff and the canonical approved Site boundary.
                        $dependency = $context->consumeViewer(FirstAidAccessService::PERMISSIONS,
                            fn () => $context->applyEventSiteScope(HsEvent::query()->whereKey($hsEvent->id),
                                UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS)
                                ->first(['id', 'status', 'handover_status']));

                        return ['access' => 'restricted', 'status' => $dependency?->status,
                            'handover' => ['status' => $dependency?->handover_status]];
                    }

                    // An optional scalar projector is consumed here, never later.
                    // within() rejects a returned model, builder or authority object.
                    if ($projection) {
                        $canManage = false;
                        try {
                            $canManage = $context->consumeViewer(FirstAidAccessService::PERMISSIONS,
                                fn (User $actor): bool => $actor->canDo('hazards.manage'));
                        } catch (HttpException $error) {
                            if ($error->getStatusCode() !== 403) {
                                throw $error;
                            }
                        }
                        $facts = ['present' => true, 'access' => 'readable', 'status' => $current->status,
                            'handover_status' => $current->handover_status,
                            'has_completed_action' => $context->query($current->correctiveActions()->getQuery())
                                ->where('status', HsCorrectiveAction::STATUS_COMPLETED)->exists(),
                            'can_manage' => $canManage];

                        return $context->consumeSourceViewer(self::PROJECTION_PERMISSIONS,
                            fn (User $actor): array => $projection($current, $facts, $actor, $context));
                    }

                    return $this->summary($current);
                });
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return ['access' => 'restricted', 'status' => null, 'handover' => ['status' => null]];
        }
    }

    /** Summary and current workflow facts from one active, source-authorised read. */
    public function summaryWithWorkflowFacts(?HsEvent $hsEvent, User $viewer, ?callable $detailProjection = null): array
    {
        $projection = $this->forEvent($hsEvent, $viewer, function (HsEvent $current, array $facts, User $actor, HealthSafetyReadContext $context) use ($detailProjection): array {
            $result = ['summary' => $this->summary($current), 'workflow_facts' => $facts];
            if ($detailProjection !== null) {
                $result['source_detail'] = $detailProjection($current, $actor, $context);
            }

            return $result;
        });
        if (array_key_exists('workflow_facts', $projection ?? [])) {
            return $projection;
        }

        // Denied reads retain an uncertain linked reference, never an absent or
        // completed case. These are only the freshly scoped dependency scalars.
        $result = ['summary' => $projection, 'workflow_facts' => [
            'present' => $hsEvent !== null,
            'access' => $hsEvent === null ? 'absent' : (($projection['status'] ?? null) === null ? 'unavailable' : 'restricted'),
            'status' => $projection['status'] ?? null,
            'handover_status' => $projection['handover']['status'] ?? null,
            'has_completed_action' => null, 'can_manage' => false,
        ]];
        if ($detailProjection !== null) {
            $result['source_detail'] = null;
        }

        return $result;
    }

    /**
     * Compose a summary inside an already active canonical source callback.
     * The original predicate remains on every actual event/child SELECT;
     * no response, model, authority object or deferred query leaves this method.
     */
    public function summaryWithWorkflowFactsInContext(int $eventId, User $viewer, HealthSafetyReadContext $context, Builder $originalReadableEvent): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(FirstAidAccessService::PERMISSIONS,
            function (User $current) use ($eventId, $context, $originalReadableEvent): array {
                $retention = app(\App\Support\Incidents\LinkedOperationalEvidencePresenter::class);
                $candidates = HsEvent::query()->whereKey($eventId);
                $source = $this->access->applyReadableScope(clone $candidates, $current, $context, $candidates)
                    ->whereExists((clone $originalReadableEvent)->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase());
                // Retain ACTUAL event bytes before child hydration, including original attribution FKs.
                $event = $retention->retainActualChildQuery($context->query((clone $source)->setEagerLoads([])), $context)->first();
                abort_if($event === null, 404);
                foreach (['owner_user_id', 'accepted_by_user_id'] as $column) {
                    $value = $event->getRawOriginal($column);
                    $value === null ? $source->whereNull('hs_events.'.$column)
                        : $source->where('hs_events.'.$column, $value);
                }
                $read = fn (Builder $query): Builder => $context->query($query->setEagerLoads([])->whereExists(
                    (clone $source)->setEagerLoads([])->reorder()->select([])->selectRaw('1')->toBase()));
                $row = fn (Builder $query): Builder => $retention->retainActualChildQuery($read($query), $context);
                $event->setRelation('owner', $event->owner_user_id === null ? null
                    : $row(User::query()->whereKey($event->owner_user_id))->first(['users.id', 'users.name']));
                $event->setRelation('acceptedBy', $event->accepted_by_user_id === null ? null
                    : $row(User::query()->whereKey($event->accepted_by_user_id))->first(['users.id', 'users.name']));

                // Match activeInvestigation's exact semantics: latest overall live id, THEN status filter.
                // Do not embed ofMany's precompiled JOIN selector: its derived block escapes lockTree.
                $active = \App\Models\HsInvestigation::query()->where('hs_investigations.hs_event_id', $eventId)
                    ->whereNotIn('hs_investigations.status', [\App\Models\HsInvestigation::STATUS_COMPLETED])
                    ->whereNotExists(function (\Illuminate\Database\Query\Builder $newer): void {
                        $newer->selectRaw('1')->from('hs_investigations as summary_newer')
                            ->whereColumn('summary_newer.hs_event_id', 'hs_investigations.hs_event_id')
                            ->whereColumn('summary_newer.id', '>', 'hs_investigations.id')
                            ->whereNull('summary_newer.deleted_at');
                    });
                // Same CurrentReads visits the explicit newer-id negative when actual query compiles.
                // Aggregates are proof-bearing scalar subqueries on a real event row, never fake child rows.
                $aggregateFrame = clone $source;
                $counts = [];
                foreach ([
                    'investigations_count' => $event->investigations()->getQuery(),
                    'corrective_actions_count' => $event->correctiveActions()->getQuery(),
                    'open_corrective_actions_count' => $event->openCorrectiveActions()->getQuery(),
                    'completed_corrective_actions_count' => $event->correctiveActions()->getQuery()->where('status', HsCorrectiveAction::STATUS_COMPLETED),
                    'active_investigation_count' => clone $active,
                    'owner_row_count' => User::query()->whereKey($event->owner_user_id),
                    'acceptor_row_count' => User::query()->whereKey($event->accepted_by_user_id),
                ] as $key => $query) {
                    $actual = $read($query);
                    $counts[$key] = (clone $actual)->count();
                    $aggregateFrame->where((clone $actual)->reorder()->select([])->selectRaw('COUNT(*)')->toBase(), '=', $counts[$key]);
                }
                foreach (['investigations_count', 'corrective_actions_count', 'open_corrective_actions_count'] as $key) {
                    $event->setAttribute($key, $counts[$key]);
                }
                $investigationQuery = $read(clone $active);
                $investigation = $retention->retainActualChildQuery(clone $investigationQuery, $context)->first([
                    'hs_investigations.id', 'hs_investigations.reference_number', 'hs_investigations.status',
                    'hs_investigations.lead_investigator_id', 'hs_investigations.target_completion_date',
                ]);
                abort_unless(($investigation === null ? 0 : 1) === $counts['active_investigation_count'], 404);
                if ($investigation !== null) {
                    $leadId = $investigation->getRawOriginal('lead_investigator_id');
                    $originalInvestigation = clone $investigationQuery;
                    $originalInvestigation->whereKey($investigation->id);
                    $leadId === null ? $originalInvestigation->whereNull('hs_investigations.lead_investigator_id')
                        : $originalInvestigation->where('hs_investigations.lead_investigator_id', $leadId);
                    $investigation->setRelation('leadInvestigator', $leadId === null ? null
                        : $row(User::query()->whereKey($leadId)->whereExists(
                            $originalInvestigation->reorder()->select([])->selectRaw('1')->toBase()))->first(['users.id', 'users.name']));
                }
                $event->setRelation('activeInvestigation', $investigation);
                // Different actual field footprint (id only) retains aggregate predicates separately from full event bytes.
                $aggregateRow = $retention->retainActualChildQuery($context->query($aggregateFrame->setEagerLoads([])), $context)
                    ->first(['hs_events.id']);
                abort_if($aggregateRow === null, 404);
                $facts = ['present' => true, 'access' => 'readable', 'status' => $event->status,
                    'handover_status' => $event->handover_status,
                    'has_completed_action' => $counts['completed_corrective_actions_count'] > 0,
                    'can_manage' => $current->canDo('hazards.manage')];
                $result = ['summary' => $this->summary($event, true), 'workflow_facts' => $facts];
                // Workspace's final shared verification must also run after its later child/config reads.
                $retention->verifyReadProjections($context);
                return $result;
            });
    }

    private function summary(HsEvent $hsEvent, bool $materialized = false): array
    {
        if (! $materialized) {
            $hsEvent->loadMissing(['owner:id,name', 'acceptedBy:id,name']);
            $hsEvent->loadCount([
                'investigations',
                'correctiveActions',
                'openCorrectiveActions',
            ]);
        }

        $activeInvestigation = $hsEvent->activeInvestigation;

        return [
            'access' => 'readable',
            'id' => $hsEvent->id,
            'reference_number' => $hsEvent->reference_number,
            'event_category' => $hsEvent->event_category,
            'severity' => $hsEvent->severity,
            'status' => $hsEvent->status,
            'reported_at' => $hsEvent->reported_at?->toIso8601String(),
            'worksafe_notifiable' => $hsEvent->worksafe_notifiable,
            'worksafe_status' => $hsEvent->worksafe_status,
            'worksafe_reference' => $hsEvent->worksafe_reference,
            'worksafe_notified_at' => $hsEvent->worksafe_notified_at?->toIso8601String(),
            'worksafe_acknowledged_at' => $hsEvent->worksafe_acknowledged_at?->toIso8601String(),
            'handover' => [
                'status' => $hsEvent->handover_status,
                'owner' => $hsEvent->owner ? [
                    'id' => $hsEvent->owner->id,
                    'name' => $hsEvent->owner->name,
                ] : null,
                'accepted_by' => $hsEvent->acceptedBy ? [
                    'id' => $hsEvent->acceptedBy->id,
                    'name' => $hsEvent->acceptedBy->name,
                ] : null,
                'accepted_at' => $hsEvent->accepted_at?->toIso8601String(),
                'notes' => $hsEvent->acceptance_notes,
            ],
            'investigation_required' => $hsEvent->investigation_required,
            'investigation' => $activeInvestigation ? [
                'reference_number' => $activeInvestigation->reference_number,
                'status' => $activeInvestigation->status,
                'lead_investigator_name' => $activeInvestigation->leadInvestigator?->name,
                'is_overdue' => $activeInvestigation->isOverdue(),
            ] : null,
            'total_corrective_actions' => $hsEvent->corrective_actions_count ?? 0,
            'open_corrective_actions' => $hsEvent->open_corrective_actions_count ?? 0,
            'href' => "/health-safety/events/{$hsEvent->id}",
        ];
    }
}
