<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\EmergencyDrill;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\HsTrainingRequirement;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Support\HealthSafety\QueryPaging;

/**
 * Central query service for H&S dashboard and reporting data.
 *
 * Provides stable aggregation methods that can be consumed by
 * controllers, scheduled reports, and API endpoints.
 *
 * All methods are read-only. No mutations.
 */
class HsDashboardService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Event KPIs */
    /* ------------------------------------------------------------------ */

    /**
     * Core H&S KPIs powered by the HsEvent backbone.
     */
    public function getEventKpis(
        ?Carbon $since = null,
        int|array|null $siteId = null,
        ?User $viewer = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($since, $siteId) {
            $since = $since ?? now()->subDays(30);
            $base = $this->hsEventQuery($siteId, $viewer, $context);

            return [
                'open_events' => (clone $base)->open()->count(),
                'open_events_high_critical' => (clone $base)->open()->highOrCritical()->count(),
                'events_period' => (clone $base)->where('reported_at', '>=', $since)->count(),
                'events_by_category' => (clone $base)->where('reported_at', '>=', $since)
                    ->select('event_category', DB::raw('COUNT(*) as count'))
                    ->groupBy('event_category')
                    ->pluck('count', 'event_category')
                    ->toArray(),
                'events_by_severity' => (clone $base)->open()
                    ->select('severity', DB::raw('COUNT(*) as count'))
                    ->groupBy('severity')
                    ->pluck('count', 'severity')
                    ->toArray(),
                'worksafe_notifiable_open' => (clone $base)->open()
                    ->worksafeNotifiable()
                    ->count(),
                // WorkSafe is an independent lifecycle: a legacy closed governance
                // event with pending notification remains actionable and countable.
                'worksafe_pending' => (clone $base)
                    ->worksafeNotifiable()
                    ->where('worksafe_status', HsEvent::WORKSAFE_PENDING)
                    ->count(),
                // Comparable subset for the Incident register. The H&S-wide count
                // above also includes standalone injury/exposure/safeguarding events.
                'incident_worksafe_pending' => (clone $base)
                    ->worksafeNotifiable()
                    ->where('worksafe_status', HsEvent::WORKSAFE_PENDING)
                    ->where(function (Builder $query): void {
                        $query->where('source_type', ClientIncident::class)
                            ->orWhereHas('clientIncident');
                    })
                    ->count(),
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Investigation KPIs */
    /* ------------------------------------------------------------------ */

    public function getInvestigationKpis(int|array|null $siteId = null, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId) {
            $base = HsInvestigation::query()
                ->when($siteId !== null, fn (Builder $query) => $query->whereHas(
                    'hsEvent',
                    fn (Builder $eventQuery) => $this->applySiteScope($eventQuery, $siteId),
                ));
            $this->applyRelatedSourceScope($base, $viewer, $context);

            return [
                'active_investigations' => (clone $base)->active()->count(),
                'overdue_investigations' => (clone $base)->overdue()->count(),
                'investigations_by_status' => (clone $base)->select('status', DB::raw('COUNT(*) as count'))
                    ->groupBy('status')
                    ->pluck('count', 'status')
                    ->toArray(),
                'awaiting_review' => (clone $base)->ofStatus(HsInvestigation::STATUS_UNDER_REVIEW)->count(),
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Corrective Action KPIs */
    /* ------------------------------------------------------------------ */

    public function getCorrectiveActionKpis(int|array|null $siteId = null, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId) {
            $base = HsCorrectiveAction::query()
                ->when($siteId !== null, fn (Builder $query) => $query->whereHas(
                    'hsEvent',
                    fn (Builder $eventQuery) => $this->applySiteScope($eventQuery, $siteId),
                ));
            $this->applyRelatedSourceScope($base, $viewer, $context);

            return [
                'open_actions' => (clone $base)->open()->count(),
                'overdue_actions' => (clone $base)->overdue()->count(),
                'awaiting_verification' => (clone $base)->awaitingVerification()->count(),
                'actions_by_priority' => (clone $base)->open()
                    ->select('priority', DB::raw('COUNT(*) as count'))
                    ->groupBy('priority')
                    ->pluck('count', 'priority')
                    ->toArray(),
                'actions_by_status' => (clone $base)->select('status', DB::raw('COUNT(*) as count'))
                    ->groupBy('status')
                    ->pluck('count', 'status')
                    ->toArray(),
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Risk Assessment KPIs */
    /* ------------------------------------------------------------------ */

    public function getRiskAssessmentKpis(int|array|null $siteId = null, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId) {
            $base = $this->riskAssessmentQuery($siteId, $viewer, $context);

            return [
                'active_assessments' => (clone $base)->active()->count(),
                'high_extreme_active' => (clone $base)->active()->highOrExtreme()->count(),
                'due_for_review' => (clone $base)->dueForReview()->count(),
                'assessments_by_level' => (clone $base)->active()
                    ->select('risk_level', DB::raw('COUNT(*) as count'))
                    ->groupBy('risk_level')
                    ->pluck('count', 'risk_level')
                    ->toArray(),
            ];

        });
    }

    /* ------------------------------------------------------------------ */
    /*  Training Compliance KPIs */
    /* ------------------------------------------------------------------ */

    public function getTrainingComplianceKpis(int|array|null $siteId = null): array
    {
        $requirements = $this->trainingRequirementQuery($siteId)->get();

        if ($requirements->isEmpty()) {
            return [
                'total_requirements' => 0,
                'blocking_requirements' => 0,
                'staff_non_compliant' => 0,
                'requirements' => [],
            ];
        }

        $hrRequirementIds = $requirements
            ->pluck('hr_compliance_requirement_id')
            ->filter()
            ->values();

        $nonCompliantCount = 0;
        if ($hrRequirementIds->isNotEmpty()) {
            $statusQuery = HrStaffComplianceStatus::query()
                ->whereIn('requirement_id', $hrRequirementIds);
            $this->applyStaffSiteScope($statusQuery, $siteId);
            $nonCompliantCount = $statusQuery
                ->whereIn('status', ['expired', 'not_started'])
                ->distinct('user_id')
                ->count('user_id');
        }

        return [
            'total_requirements' => $requirements->count(),
            'blocking_requirements' => $requirements->where('enforcement_mode', 'block')->count(),
            'staff_non_compliant' => $nonCompliantCount,
            'requirements' => $requirements->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'scope_type' => $r->scope_type,
                'enforcement_mode' => $r->enforcement_mode,
                'is_active' => $r->is_active,
            ])->values()->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Worklist row builders (G6) — actionable rows, not just counts */
    /* ------------------------------------------------------------------ */

    /**
     * Priority H&S attention queues, ordered by the action a governance owner
     * must take next. Rows deep-link to the exact action rather than relying on
     * a register filter or prior knowledge of the H&S reference.
     */
    public function attentionWorklists(
        int|array|null $siteId = null,
        ?User $viewer = null,
        int $limit = 10,
        ?HealthSafetyReadContext $context = null,
    ): array {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId, $limit) {
            $awaitingAcceptance = $this->hsEventQuery($siteId, $viewer, $context)
                ->where('handover_status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE)
                ->where('status', '!=', HsEvent::STATUS_CLOSED)
                ->with([
                    'client:id,first_name,last_name',
                    'clientIncident:id,hs_event_id,title,description,type',
                    'owner:id,name',
                    'site:id,name',
                ])
                ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
                ->orderBy('reported_at');
            $this->protectIncidentEagerLoads($awaitingAcceptance, $viewer, $context);
            $awaitingAcceptanceCount = (clone $awaitingAcceptance)->count();
            $awaitingAcceptance = $awaitingAcceptance
                ->limit($limit)
                ->get();

            return [[
                'key' => 'awaiting_hs_acceptance',
                'label' => 'Awaiting H&S acceptance',
                'help' => 'A named H&S owner must accept governance responsibility.',
                'count' => $awaitingAcceptanceCount,
                'source_scope' => 'readable_subset',
                'source_completeness' => 'unknown',
                'items' => $awaitingAcceptance
                    ->map(fn (HsEvent $event) => [
                        'id' => $event->id,
                        'event_reference' => $event->reference_number,
                        // B1.1/B7: the record's title. protectIncidentEagerLoads() leaves the incident out for a viewer
                        // who can't read it, so they get kind and person, never the incident's words.
                        'title' => $this->reviewTitle($event),
                        'severity' => $event->severity,
                        'reported_at' => $event->reported_at?->toIso8601String(),
                        'site' => $event->site?->name,
                        'client' => $event->client?->full_name,
                        'owner' => $event->owner?->name,
                        'action_url' => "/health-safety/events/{$event->id}?action=accept-handover",
                    ])
                    ->values()
                    ->all(),
            ]];
        });
    }

    /**
     * Overdue corrective actions as worklist rows. Each row carries the linked
     * client/staff ids (from the parent HsEvent) for the context-menu jumps.
     * Site-scoped via the parent event.
     */
    public function overdueCorrectiveActions(int|array|null $siteId = null, int $limit = 10, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId, $limit) {
            $query = HsCorrectiveAction::overdue()
                ->with(['assignedTo:id,name', 'hsEvent:id,client_id,staff_id,site_id,reference_number']);
            $this->applyRelatedSiteScope($query, 'hsEvent', $siteId);
            $this->applyRelatedSourceScope($query, $viewer, $context);

            return $this->protectSourceEagerLoads($query)
                ->orderBy('due_date')
                ->limit($limit)
                ->get()
                ->map(fn (HsCorrectiveAction $a) => [
                    'id' => $a->id,
                    'reference' => $a->reference_number,
                    'title' => $a->title,
                    'priority' => $a->priority,
                    'status' => $a->status,
                    'due_date' => optional($a->due_date)->toDateString(),
                    'days_overdue' => $a->due_date ? (int) abs($a->due_date->diffInDays(now())) : null,
                    'completed_days_ago' => $a->daysSinceCompleted(),
                    'owner' => $a->assignedTo?->name,
                    'client_id' => $a->hsEvent?->client_id,
                    'staff_id' => $a->hsEvent?->staff_id,
                    'event_reference' => $a->hsEvent?->reference_number,
                ])
                ->all();
        });
    }

    /**
     * Open (not-completed) investigations as worklist rows, flagging overdue ones.
     * Site-scoped via the parent event; client/staff ids for context-menu jumps.
     */
    public function openInvestigations(int|array|null $siteId = null, int $limit = 10, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId, $limit) {
            $today = now()->toDateString();

            $query = HsInvestigation::active()
                ->with(['leadInvestigator:id,name', 'hsEvent:id,client_id,staff_id,site_id,reference_number']);
            $this->applyRelatedSiteScope($query, 'hsEvent', $siteId);
            $this->applyRelatedSourceScope($query, $viewer, $context);

            return $this->protectSourceEagerLoads($query)
                ->orderByRaw('target_completion_date IS NULL, target_completion_date')
                ->limit($limit)
                ->get()
                ->map(fn (HsInvestigation $i) => [
                    'id' => $i->id,
                    'event_id' => $i->hsEvent?->id,
                    'reference' => $i->reference_number,
                    'type' => $i->investigation_type,
                    'status' => $i->status,
                    'target_completion_date' => optional($i->target_completion_date)->toDateString(),
                    'is_overdue' => $i->target_completion_date
                        && $i->target_completion_date->toDateString() < $today,
                    'owner' => $i->leadInvestigator?->name,
                    'client_id' => $i->hsEvent?->client_id,
                    'staff_id' => $i->hsEvent?->staff_id,
                    'event_reference' => $i->hsEvent?->reference_number,
                ])
                ->all();
        });
    }

    /**
     * WorkSafe notifiable events from the authoritative HsEvent backbone.
     * `related_incident_id` deep-links to the source incident when present.
     */
    public function notifiableEvents(
        int|array|null $siteId = null,
        int $limit = 10,
        ?User $viewer = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId, $limit) {
            return $this->hsEventQuery($siteId, $viewer, $context)
                ->worksafeNotifiable()
                ->with(['clientIncident:id,hs_event_id,reference_number,title,description,type'])
                ->tap(fn (Builder $query) => $this->protectIncidentEagerLoads($query, $viewer, $context))
                ->orderByRaw("CASE worksafe_status WHEN 'pending' THEN 0 WHEN 'notified' THEN 1 WHEN 'acknowledged' THEN 2 ELSE 3 END")
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get()
                ->map(fn (HsEvent $event) => [
                    'id' => $event->id,
                    'event_reference' => $event->reference_number,
                    'title' => $this->reviewTitle($event),
                    'incident_type' => $event->clientIncident?->type
                        ?: $event->event_category,
                    'status' => $event->worksafe_status,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'notified_at' => $event->worksafe_notified_at?->toIso8601String(),
                    'acknowledged_at' => $event->worksafe_acknowledged_at?->toIso8601String(),
                    'notification_deadline' => null,
                    'site_preserved' => (bool) $event->worksafe_site_preserved,
                    'worksafe_ref' => $event->worksafe_reference,
                    'related_incident_id' => $event->clientIncident?->id
                        ?? ($event->source_type === ClientIncident::class ? $event->source_id : null),
                ])
                ->all();
        });
    }

    /**
     * G2 (B2.2, IR10) "WorkSafe: decide or tell": open reviews still needing the WorkSafe decision, or decided notifiable
     * and not yet told, most severe first. The count is the whole list; rows are the first $limit.
     *
     * @return array{count: int, rows: list<array<string, mixed>>}
     */
    public function worksafeQueue(int|array|null $siteId, ?User $viewer, int $limit = 10, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId, $limit) {
            $query = $this->hsEventQuery($siteId, $viewer, $context)->worksafeDecideOrTell()
                ->with(['clientIncident:id,hs_event_id,title,description,type', 'client:id,first_name,last_name', 'site:id,name', 'owner:id,name'])
                ->tap(fn (Builder $q) => $this->protectIncidentEagerLoads($q, $viewer, $context))
                ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")->orderBy('reported_at');
            $count = (clone $query)->count();

            return ['count' => $count, 'rows' => $query->limit($limit)->get()->map(fn (HsEvent $event) => [
                'id' => $event->id,
                'reference' => $event->reference_number,
                'title' => $this->reviewTitle($event),
                'severity' => $event->severity,
                'site' => $event->site?->name,
                'owner' => $event->owner?->name,
                'reported_at' => $event->reported_at?->toIso8601String(),
                // Decided notifiable but WorkSafe not told yet, or still to decide.
                'step' => $event->hasSignedWorksafeDecision() ? 'tell' : 'decide',
                'awaiting_owner' => $event->handover_status === HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
            ])->values()->all()];
        });
    }

    /**
     * G2 (B2.2/B2.3) "Actions to check": actions marked done that this viewer may check (not one they added, own or did),
     * oldest first. The count is the whole list; rows are the first $limit.
     *
     * @return array{count: int, rows: list<array<string, mixed>>}
     */
    public function checkQueue(int|array|null $siteId, User $viewer, int $limit = 10, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId, $limit) {
            $query = HsCorrectiveAction::query()->where('hs_corrective_actions.status', HsCorrectiveAction::STATUS_COMPLETED)
                ->with(['completedBy:id,name', 'hsEvent:id,site_id,reference_number']);
            // The server's separation-of-duties rule, shared with the header box (HsOverviewHeaderReadService::checkableBy).
            HsOverviewHeaderReadService::checkableBy($query, $viewer);
            $this->applyRelatedSiteScope($query, 'hsEvent', $siteId);
            $this->applyRelatedSourceScope($query, $viewer, $context);
            $query = $this->protectSourceEagerLoads($query)->orderBy('completed_at');
            $count = (clone $query)->count();

            return ['count' => $count, 'rows' => $query->limit($limit)->get()->map(fn (HsCorrectiveAction $action) => [
                'id' => $action->id,
                'reference' => $action->reference_number,
                'title' => $action->title,
                'completed_by' => $action->completedBy?->name,
                'completed_at' => $action->completed_at?->toIso8601String(),
                'event_reference' => $action->hsEvent?->reference_number,
            ])->values()->all()];
        });
    }

    /**
     * G2 (B2.2) "Where safety reviews are up to": open reviews by stage now, and reviews closed in the last 30 days.
     *
     * @return array<string, int>
     */
    public function reviewStages(int|array|null $siteId, ?User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId) {
            $byStatus = $this->hsEventQuery($siteId, $viewer, $context)->where('hs_events.status', '!=', HsEvent::STATUS_CLOSED)
                ->toBase()->selectRaw('hs_events.status AS s, COUNT(*) AS n')->groupBy('s')->pluck('n', 's')->all();
            $closed = $this->hsEventQuery($siteId, $viewer, $context)->where('hs_events.status', HsEvent::STATUS_CLOSED)
                ->where('hs_events.closed_at', '>=', now()->subDays(30))->count();

            return [
                'open' => (int) ($byStatus[HsEvent::STATUS_OPEN] ?? 0),
                'investigating' => (int) ($byStatus[HsEvent::STATUS_INVESTIGATING] ?? 0),
                'corrective_action' => (int) ($byStatus[HsEvent::STATUS_CORRECTIVE_ACTION] ?? 0),
                'monitoring' => (int) ($byStatus[HsEvent::STATUS_MONITORING] ?? 0),
                'closed_30d' => $closed,
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Unified expiring feed (G5) */
    /* ------------------------------------------------------------------ */

    /**
     * One unified `expiring[]` feed across risk assessments (review_due_at), SDS
     * (review_date) and scheduled drills (scheduled_at), within `withinDays` (incl. overdue).
     * Each item: {type, type_label, label, due_date, days_until (negative = overdue),
     * register_url, site}. Site views include only attributable risk, SDS, drill and PPE rows.
     */
    public function expiringFeed(
        int|array|null $siteId = null,
        int $withinDays = 60,
        int $limit = 20,
        ?User $viewer = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        return $this->expiringFeedProjection($siteId, $withinDays, $limit, $viewer, $context)['items'];
    }

    /** Preserve the legacy array API while giving actual consumers the PPE preview's uncertainty. */
    public function expiringFeedProjection(
        int|array|null $siteId = null,
        int $withinDays = 60,
        int $limit = 20,
        ?User $viewer = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($siteId, $withinDays, $limit) {
            $horizon = now()->copy()->addDays($withinDays)->toDateString();
            $items = [];

            foreach ($this->riskAssessmentQuery($siteId, $viewer, $context)
                ->active()
                ->whereNotNull('review_due_at')
                ->where('review_due_at', '<=', $horizon)
                ->orderBy('review_due_at')
                ->limit($limit)
                ->get() as $ra) {
                $items[] = $this->expiringItem('risk_assessment', 'Risk assessment review', $ra->title ?: $ra->reference_number, $ra->review_due_at, '/health-safety/risk-assessments?assessment='.$ra->id);
            }

            foreach (SafetyDataSheet::query()
                ->with('hazardousSubstance')
                ->whereNotNull('review_date')
                ->where('review_date', '<=', $horizon)
                ->when($siteId !== null, fn (Builder $query) => $query->whereHas(
                    'hazardousSubstance.storageLocations',
                    fn (Builder $locationQuery) => $this->applySiteScope($locationQuery, $siteId),
                ))
                ->orderBy('review_date')
                ->limit($limit)
                ->get() as $sds) {
                $label = $sds->hazardousSubstance?->name ?: ($sds->supplier_name ?: 'Safety data sheet');
                $items[] = $this->expiringItem('sds', 'SDS review', $label, $sds->review_date, '/health-safety/substances');
            }

            foreach (EmergencyDrill::query()
                ->where('status', 'scheduled')
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', now()->copy()->addDays($withinDays))
                ->when($siteId !== null, fn (Builder $q) => $this->applySiteScope($q, $siteId))
                ->with('site:id,name')
                ->orderBy('scheduled_at')
                ->limit($limit)
                ->get() as $drill) {
                $label = trim(ucfirst((string) $drill->drill_type).' drill — '.($drill->site?->name ?? ''), ' —');
                $items[] = $this->expiringItem('drill', 'Emergency drill', $label, $drill->scheduled_at, '/health-safety/drills', $drill->site?->name);
            }

            $ppe = ['confirmed_count' => null, 'total' => null, 'repair_count' => null,
                'physical_record_count' => null, 'truncated' => false, 'source_integrity' => 'restricted'];
            if ($viewer !== null) {
                try {
                    $projection = app(PpeSiteReadService::class)->expiringForViewer($viewer, $siteId, $withinDays, $limit);
                    $items = [...$items, ...$projection['items']];
                    $ppe = array_diff_key($projection, ['items' => true]);
                } catch (HttpExceptionInterface $denial) {
                    if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                        throw $denial;
                    }
                }
            }

            usort($items, fn ($a, $b) => strcmp((string) $a['due_date'], (string) $b['due_date']));

            $displayed = array_slice($items, 0, $limit);
            $ppeShown = count(array_filter($displayed, fn ($item) => str_starts_with($item['type'], 'ppe_')));
            $ppeAvailable = count(array_filter($items, fn ($item) => str_starts_with($item['type'], 'ppe_')));
            $ppe['truncated'] = $ppe['truncated'] || $ppeShown < $ppeAvailable;
            $ppe['displayed_count'] = $ppeShown;

            return ['items' => $displayed, 'ppe' => $ppe];

        });
    }

    private function expiringItem(string $type, string $typeLabel, ?string $label, $dueDate, string $registerUrl, ?string $site = null): array
    {
        $due = $dueDate ? Carbon::parse($dueDate) : null;

        return [
            'type' => $type,
            'type_label' => $typeLabel,
            'label' => $label,
            'due_date' => $due?->toDateString(),
            'days_until' => $due ? (int) round(now()->startOfDay()->diffInDays($due->copy()->startOfDay(), false)) : null,
            'register_url' => $registerUrl,
            'site' => $site,
        ];
    }

    /**
     * Alphabetical Site comparison of current-readable reports and open public hazards.
     * Readable report counts are not total reports or a completeness/risk certificate.
     * An independently restricted hazard source remains null, never an invented zero.
     *
     * @return array<int, array{id: int, name: string, incidents: int, hazards: int|null}>
     */
    public function siteLeague(?Carbon $from = null, ?Carbon $to = null, int|array|null $siteId = null, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        if ($viewer === null) {
            return [];
        }
        $permissions = array_values(array_unique([
            ...FirstAidAccessService::PERMISSIONS,
            ...RestraintAccessService::PERMISSIONS,
            ...InjuryAccessService::PERMISSIONS,
            'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view',
        ]));

        $consume = fn (HealthSafetyReadContext $context) => HealthSafetyReadMaterialisationProof::within($context, fn (HealthSafetyReadMaterialisationProof $proof) =>
            $context->consumeViewer($permissions, function (User $current, $reads, array $approvedSites) use ($from, $to, $siteId, $context, $proof): array {
                abort_unless($current->canDo('hazards.view'), 403);
                $selectedSites = $siteId === null ? $approvedSites : $this->normalizeSiteIds($siteId);
                abort_unless(array_diff($selectedSites, $approvedSites) === [], 404);
                if ($selectedSites === []) {
                    return [];
                }
                $sites = $reads->query(Site::query())->whereIn('id', $selectedSites)->orderBy('name')->orderBy('id')->get(['id', 'name']);
                $from = $from ?? now()->subDays(30);
                $to = $to ?? now();

                // Filter before source discovery; one prepared context and grouped count, never per-Site private reads.
                $candidates = HsEvent::query()->whereIn('site_id', $selectedSites)
                    ->whereIn('event_category', [HsEvent::CATEGORY_INCIDENT, HsEvent::CATEGORY_NEAR_MISS, HsEvent::CATEGORY_INJURY])
                    ->whereBetween('occurred_at', [$from, $to]);
                $incidentsBySite = $proof->protect(app(HsEventAccessService::class)->applyReadableScope(clone $candidates, $current, $context, $candidates))
                    ->select('site_id', DB::raw('COUNT(*) as aggregate'))->groupBy('site_id')->pluck('aggregate', 'site_id');

                // Public hazards retain the literal register's independent Site/type boundary.
                $hazardSiteIds = array_values(array_intersect($selectedSites,
                    $this->siteAccess->accessibleSiteIds($current, ['sites.viewAll'], $reads)));
                $allowedTypes = app(\App\Services\SiteTypeAccessService::class)->allowedTypes($current);
                $hazardSiteIds = $reads->query(Site::query())->whereIn('id', $hazardSiteIds)->whereIn('type', $allowedTypes)
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $hazardsBySite = $hazardSiteIds === [] ? [] : $reads->query(SiteHazard::query())
                    ->whereIn('site_id', $hazardSiteIds)->whereIn('status', ['open', 'in_progress'])
                    ->select('site_id', DB::raw('COUNT(*) as aggregate'))->groupBy('site_id')->pluck('aggregate', 'site_id');

                return $sites->map(fn (Site $site): array => [
                    'id' => (int) $site->id,
                    'name' => $site->name,
                    'incidents' => (int) ($incidentsBySite[$site->id] ?? 0),
                    'hazards' => in_array((int) $site->id, $hazardSiteIds, true) ? (int) ($hazardsBySite[$site->id] ?? 0) : null,
                ])->all();
            }));

        return $context !== null ? $consume($context) : HealthSafetyReadContext::within($viewer, $permissions, $consume);
    }

    /* ------------------------------------------------------------------ */
    /*  Combined summary for main dashboard */
    /* ------------------------------------------------------------------ */

    public function getDashboardSummary(
        ?Carbon $since = null,
        int|array|null $siteId = null,
        ?User $viewer = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        return $this->withSourceRead($viewer, $context, function (?User $viewer, ?HealthSafetyReadContext $context) use ($since, $siteId) {
            return [
                'events' => $this->getEventKpis($since, $siteId, $viewer, $context),
                'investigations' => $this->getInvestigationKpis($siteId, $viewer, $context),
                'corrective_actions' => $this->getCorrectiveActionKpis($siteId, $viewer, $context),
                'risk_assessments' => $this->getRiskAssessmentKpis($siteId, $viewer, $context),
                'training' => $this->getTrainingComplianceKpis($siteId),
            ];
        });
    }

    private function riskAssessmentQuery(int|array|null $siteId, ?User $viewer = null, ?HealthSafetyReadContext $context = null): Builder
    {
        $query = HsRiskAssessment::query();
        if ($viewer !== null) {
            $this->siteAccess->applyHsRiskAssessmentScope($query, $viewer, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
        } elseif ($siteId === null) {
            $this->siteAccess->applyHsRiskAssessmentApplicationScope($query);
        }
        if ($siteId !== null) {
            $this->siteAccess->applyHsRiskAssessmentSiteScopeForSiteIds($query, $this->normalizeSiteIds($siteId));
        }
        $modern = (clone $query)->withoutEagerLoads()->reorder();
        $modern->getQuery()->limit = null;
        $modern->getQuery()->offset = null;
        $legacy = (clone $modern)->whereIn('assessable_type', [HsEvent::class, (new HsEvent)->getMorphClass()]);
        $candidates = HsEvent::withTrashed()->where(fn (Builder $events) => $events
            ->whereIn('hs_events.id', $modern->select('hs_risk_assessments.hs_event_id'))
            ->orWhereIn('hs_events.id', $legacy->select('hs_risk_assessments.assessable_id')));

        return $this->protectMaterialisation(app(HsEventAccessService::class)->applyFirstAidRiskSourceScope($query, $viewer, $context, $candidates));
    }

    private function applyStaffSiteScope(Builder $query, int|array|null $siteId): Builder
    {
        if ($siteId === null) {
            return $query;
        }

        $siteIds = $this->normalizeSiteIds($siteId);

        return $query->whereHas('user.hrEmployeeProfile', function (Builder $profileQuery) use ($siteIds): void {
            $profileQuery->whereIn('primary_site_id', $siteIds);
            foreach ($siteIds as $id) {
                $profileQuery->orWhereJsonContains('secondary_site_ids', $id);
            }
        });
    }

    private function trainingRequirementQuery(int|array|null $siteId): Builder
    {
        $query = HsTrainingRequirement::query()->active();
        if ($siteId === null) {
            return $query;
        }

        $siteIds = $this->normalizeSiteIds($siteId);
        $clientIds = Client::query()->whereIn('site_id', $siteIds)->pluck('id');

        return $query->where(function (Builder $scope) use ($siteIds, $clientIds): void {
            $scope->whereIn('scope_type', [
                HsTrainingRequirement::SCOPE_GLOBAL,
                HsTrainingRequirement::SCOPE_ROLE,
            ]);

            foreach ($siteIds as $id) {
                $scope->orWhere(function (Builder $siteScope) use ($id): void {
                    $siteScope->where('scope_type', HsTrainingRequirement::SCOPE_SITE)
                        ->whereJsonContains('scope_site_ids', $id);
                });
            }

            foreach ($clientIds as $clientId) {
                $scope->orWhere(function (Builder $clientScope) use ($clientId): void {
                    $clientScope->where('scope_type', HsTrainingRequirement::SCOPE_CLIENT)
                        ->whereJsonContains('scope_client_ids', (int) $clientId);
                });
            }
        });
    }

    private function hsEventQuery(int|array|null $siteId, ?User $viewer, ?HealthSafetyReadContext $context = null): Builder
    {
        $query = HsEvent::query();

        if ($viewer !== null) {
            $this->siteAccess->applyHsEventScope(
                $query,
                $viewer,
                UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
            );
        }

        if ($siteId !== null) {
            $this->applySiteScope($query, $siteId);
        }

        return $this->protectMaterialisation(app(HsEventAccessService::class)->applyFirstAidSourceScope($query, $viewer, $context));
    }

    private function applySiteScope(Builder $query, int|array|null $siteId, string $column = 'site_id'): Builder
    {
        if ($siteId === null) {
            return $query;
        }

        $siteIds = $this->normalizeSiteIds($siteId);

        return $siteIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($query->qualifyColumn($column), $siteIds);
    }

    private function applyRelatedSiteScope(
        Builder $query,
        string $relationship,
        int|array|null $siteId,
    ): Builder {
        if ($siteId === null) {
            return $query;
        }

        return $query->whereHas(
            $relationship,
            fn (Builder $related) => $this->applySiteScope($related, $siteId),
        );
    }

    /** @return array<int, int> */
    private function normalizeSiteIds(int|array $siteId): array
    {
        return collect(is_array($siteId) ? $siteId : [$siteId])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** Source proof uses an executable filtered child subquery, never the correlated relation builder. */
    private function applyRelatedSourceScope(Builder $query, ?User $viewer, ?HealthSafetyReadContext $context = null): Builder
    {
        $children = (clone $query)->withoutEagerLoads()->reorder();
        $children->getQuery()->limit = null;
        $children->getQuery()->offset = null;
        $candidates = HsEvent::query()->whereIn('hs_events.id', $children->select($query->getModel()->qualifyColumn('hs_event_id')));

        return $this->protectMaterialisation($query->whereHas('hsEvent', fn (Builder $event) => app(HsEventAccessService::class)->applyFirstAidSourceScope($event, $viewer, $context, $candidates)));
    }

    /** All source decisions and their final SQL share the caller's original prepared evidence. */
    private ?HealthSafetyReadMaterialisationProof $materialisationProof = null;

    private function protectMaterialisation(Builder $query): Builder
    {
        return $this->materialisationProof?->protect($query) ?? $query;
    }
    private function withSourceRead(?User $viewer, ?HealthSafetyReadContext $context, callable $consumer): mixed
    {
        $project = static fn (mixed $result): mixed => is_array($result) && ! array_is_list($result)
            ? [...$result, 'source_scope' => 'readable_subset', 'source_completeness' => 'unknown'] : $result;
        if ($viewer === null) {
            abort_unless($context === null, 403);

            return DB::transaction(fn () => $project($consumer(null, null)));
        }
        $permissions = array_values(array_unique([...FirstAidAccessService::PERMISSIONS,
            ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS]));
        $consume = function (HealthSafetyReadContext $active) use ($viewer, $permissions, $consumer, $project): mixed {
            $active->assertViewer($viewer);

            return $active->consumeSourceViewer($permissions,
                function (User $current) use ($active, $project, $consumer): mixed {
                    return HealthSafetyReadMaterialisationProof::within($active, function (HealthSafetyReadMaterialisationProof $proof) use ($current, $active, $project, $consumer): mixed {
                        $previous = $this->materialisationProof;
                        $this->materialisationProof = $proof;
                        try { return $project($consumer($current, $active)); }
                        finally { $this->materialisationProof = $previous; }
                    });
                });
        };

        return $context !== null ? $consume($context) : HealthSafetyReadContext::within($viewer, $permissions, $consume);
    }

    /** Eager names and event bodies retain the original admitted report query through their own SELECT. */
    private function protectSourceEagerLoads(Builder $parents): Builder
    {
        $eagerLoads = $parents->getEagerLoads();
        $columns = [
            'hsEvent' => 'hs_event_id', 'client' => 'client_id', 'staff' => 'staff_id',
            'site' => 'site_id', 'owner' => 'owner_user_id', 'assignedTo' => 'assigned_to_user_id',
            'completedBy' => 'completed_by_user_id', 'verifiedBy' => 'verified_by_user_id',
            'leadInvestigator' => 'lead_investigator_id', 'assessedBy' => 'assessed_by_user_id',
            'approvedBy' => $parents->getModel() instanceof HsInvestigation ? 'approved_by_id' : 'approved_by_user_id',
        ];
        foreach ($columns as $name => $column) {
            if (! isset($eagerLoads[$name])) {
                continue;
            }
            $original = $eagerLoads[$name];
            $proof = (clone $parents)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))
                ->select($parents->getModel()->qualifyColumn($column));
            $parents->with([$name => function ($relation) use ($original, $proof): void {
                $original($relation);
                $relation->whereIn($relation->getRelated()->qualifyColumn('id'), clone $proof);
                $this->reconsumeOriginalSource($relation->getQuery(), $proof);
            }]);
        }
        if (isset($eagerLoads['hsEvent.site'])) {
            $original = $eagerLoads['hsEvent.site'];
            $proof = (clone $parents)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))
                ->select($parents->getModel()->qualifyColumn('hs_event_id'));
            $sites = HsEvent::query()->whereIn('hs_events.id', $proof)->select('hs_events.site_id');
            $parents->with(['hsEvent.site' => function ($relation) use ($original, $sites, $proof): void {
                $original($relation);
                $relation->whereIn('sites.id', clone $sites);
                $this->reconsumeOriginalSource($relation->getQuery(), $proof);
            }]);
        }

        return $this->protectMaterialisation($parents);
    }

    /** Prepare the original incident/HSE parent before either the event or copied incident body SELECT. */
    /** The record's title from what this list already loaded (no extra reads); the incident is only there for readers. */
    private function reviewTitle(HsEvent $event): string
    {
        $incident = $event->relationLoaded('clientIncident') ? $event->clientIncident : null;
        $client = $event->relationLoaded('client') ? $event->client : null;
        $site = $event->relationLoaded('site') ? $event->site : null;

        return \App\Support\HealthSafety\HsReviewTitle::make((string) $event->event_category, $incident !== null, $incident?->title,
            $client ? trim($client->first_name.' '.$client->last_name) : null, null, $site?->name);
    }

    private function protectIncidentEagerLoads(Builder $events, ?User $viewer, ?HealthSafetyReadContext $context): Builder
    {
        $original = $events->getEagerLoads()['clientIncident'];
        $eventProof = (clone $events)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->select('hs_events.id');
        $incidents = ClientIncident::query()->whereIn('client_incidents.hs_event_id', $eventProof);
        $incidents = $viewer === null || $context === null ? $incidents->whereRaw('1 = 0') : $context->applyIncidentSourceScope($incidents);
        $events->with(['clientIncident' => function ($relation) use ($original, $incidents, $eventProof): void {
            $original($relation);
            $relation->whereIn('client_incidents.id', (clone $incidents)->select('client_incidents.id'));
            $this->reconsumeOriginalSource($relation->getQuery(), $incidents);
            $this->reconsumeOriginalSource($relation->getQuery(), $eventProof);
        }]);

        return $this->protectSourceEagerLoads($events);
    }

    /** whereIn compiles subqueries immediately; reconsume the SAME original guard at final body compilation. */
    private function reconsumeOriginalSource(Builder $destination, Builder $originalSource): Builder
    {
        return $this->protectMaterialisation($destination->whereRaw(new class(clone $originalSource) extends \Illuminate\Database\Query\Expression {
            public function __construct(private readonly Builder $originalSource)
            {
                parent::__construct('1 = 1');
            }

            public function getValue(\Illuminate\Database\Grammar $grammar)
            {
                // SQL compilation only: no new authority, source discovery or replacement baseline.
                (clone $this->originalSource)->toSql();

                return parent::getValue($grammar);
            }
        }));
    }
}
