<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\ClientIncident;
use App\Models\EmergencyDrill;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\HsTrainingRequirement;
use App\Models\PpeInventory;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        ?int $siteId = null,
        ?User $viewer = null,
    ): array {
        $since = $since ?? now()->subDays(30);
        $base = $this->hsEventQuery($siteId, $viewer);

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
    }

    /* ------------------------------------------------------------------ */
    /*  Investigation KPIs */
    /* ------------------------------------------------------------------ */

    public function getInvestigationKpis(): array
    {
        return [
            'active_investigations' => HsInvestigation::active()->count(),
            'overdue_investigations' => HsInvestigation::overdue()->count(),
            'investigations_by_status' => HsInvestigation::select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'awaiting_review' => HsInvestigation::ofStatus(HsInvestigation::STATUS_UNDER_REVIEW)->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Corrective Action KPIs */
    /* ------------------------------------------------------------------ */

    public function getCorrectiveActionKpis(): array
    {
        return [
            'open_actions' => HsCorrectiveAction::open()->count(),
            'overdue_actions' => HsCorrectiveAction::overdue()->count(),
            'awaiting_verification' => HsCorrectiveAction::awaitingVerification()->count(),
            'actions_by_priority' => HsCorrectiveAction::open()
                ->select('priority', DB::raw('COUNT(*) as count'))
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray(),
            'actions_by_status' => HsCorrectiveAction::select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Risk Assessment KPIs */
    /* ------------------------------------------------------------------ */

    public function getRiskAssessmentKpis(): array
    {
        return [
            'active_assessments' => HsRiskAssessment::active()->count(),
            'high_extreme_active' => HsRiskAssessment::active()->highOrExtreme()->count(),
            'due_for_review' => HsRiskAssessment::dueForReview()->count(),
            'assessments_by_level' => HsRiskAssessment::active()
                ->select('risk_level', DB::raw('COUNT(*) as count'))
                ->groupBy('risk_level')
                ->pluck('count', 'risk_level')
                ->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Training Compliance KPIs */
    /* ------------------------------------------------------------------ */

    public function getTrainingComplianceKpis(): array
    {
        $requirements = HsTrainingRequirement::active()->get();

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
            $nonCompliantCount = HrStaffComplianceStatus::whereIn('requirement_id', $hrRequirementIds)
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
     * Overdue corrective actions as worklist rows. Each row carries the linked
     * client/staff ids (from the parent HsEvent) for the context-menu jumps.
     * Site-scoped via the parent event.
     */
    public function overdueCorrectiveActions(?int $siteId = null, int $limit = 10): array
    {
        return HsCorrectiveAction::overdue()
            ->with(['assignedTo:id,name', 'hsEvent:id,client_id,staff_id,site_id,reference_number'])
            ->when($siteId, fn (Builder $q) => $q->whereHas('hsEvent', fn (Builder $e) => $e->where('site_id', $siteId)))
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
                'owner' => $a->assignedTo?->name,
                'client_id' => $a->hsEvent?->client_id,
                'staff_id' => $a->hsEvent?->staff_id,
                'event_reference' => $a->hsEvent?->reference_number,
            ])
            ->all();
    }

    /**
     * Open (not-completed) investigations as worklist rows, flagging overdue ones.
     * Site-scoped via the parent event; client/staff ids for context-menu jumps.
     */
    public function openInvestigations(?int $siteId = null, int $limit = 10): array
    {
        $today = now()->toDateString();

        return HsInvestigation::active()
            ->with(['leadInvestigator:id,name', 'hsEvent:id,client_id,staff_id,site_id,reference_number'])
            ->when($siteId, fn (Builder $q) => $q->whereHas('hsEvent', fn (Builder $e) => $e->where('site_id', $siteId)))
            ->orderByRaw('target_completion_date IS NULL, target_completion_date')
            ->limit($limit)
            ->get()
            ->map(fn (HsInvestigation $i) => [
                'id' => $i->id,
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
    }

    /**
     * WorkSafe notifiable events from the authoritative HsEvent backbone.
     * `related_incident_id` deep-links to the source incident when present.
     */
    public function notifiableEvents(
        ?int $siteId = null,
        int $limit = 10,
        ?User $viewer = null,
    ): array {
        return $this->hsEventQuery($siteId, $viewer)
            ->worksafeNotifiable()
            ->with(['clientIncident:id,hs_event_id,reference_number,title,description,type'])
            ->orderByRaw("CASE worksafe_status WHEN 'pending' THEN 0 WHEN 'notified' THEN 1 WHEN 'acknowledged' THEN 2 ELSE 3 END")
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (HsEvent $event) => [
                'id' => $event->id,
                'event_reference' => $event->reference_number,
                'title' => $event->clientIncident?->title
                    ?: $event->clientIncident?->description,
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
    }

    /* ------------------------------------------------------------------ */
    /*  Unified expiring feed (G5) */
    /* ------------------------------------------------------------------ */

    /**
     * One unified `expiring[]` feed across risk assessments (review_due_at), SDS
     * (review_date) and scheduled drills (scheduled_at), within `withinDays` (incl. overdue).
     * Each item: {type, type_label, label, due_date, days_until (negative = overdue),
     * register_url, site}. Drills are site-scoped; risk/SDS are org-level registers.
     */
    public function expiringFeed(?int $siteId = null, int $withinDays = 60, int $limit = 20): array
    {
        $horizon = now()->copy()->addDays($withinDays)->toDateString();
        $items = [];

        foreach (HsRiskAssessment::active()
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
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
            ->with('site:id,name')
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get() as $drill) {
            $label = trim(ucfirst((string) $drill->drill_type).' drill — '.($drill->site?->name ?? ''), ' —');
            $items[] = $this->expiringItem('drill', 'Emergency drill', $label, $drill->scheduled_at, '/health-safety/drills', $drill->site?->name);
        }

        // PPE inspections due + items expiring (site-scoped; one register = /health-safety/ppe).
        foreach (PpeInventory::query()
            ->whereNotIn('status', ['condemned', 'disposed'])
            ->whereNotNull('next_inspection_due')
            ->whereDate('next_inspection_due', '<=', $horizon)
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
            ->with(['ppeType:id,name', 'site:id,name'])
            ->orderBy('next_inspection_due')
            ->limit($limit)
            ->get() as $ppe) {
            $label = trim(($ppe->ppeType?->name ?? 'PPE item').' · '.($ppe->serial_number ?? ''), ' ·');
            $items[] = $this->expiringItem('ppe_inspection', 'PPE inspection', $label, $ppe->next_inspection_due, '/health-safety/ppe', $ppe->site?->name);
        }

        foreach (PpeInventory::query()
            ->whereNotIn('status', ['condemned', 'disposed'])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $horizon)
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
            ->with(['ppeType:id,name', 'site:id,name'])
            ->orderBy('expiry_date')
            ->limit($limit)
            ->get() as $ppe) {
            $label = trim(($ppe->ppeType?->name ?? 'PPE item').' · '.($ppe->serial_number ?? ''), ' ·');
            $items[] = $this->expiringItem('ppe_expiry', 'PPE expiry', $label, $ppe->expiry_date, '/health-safety/ppe', $ppe->site?->name);
        }

        usort($items, fn ($a, $b) => strcmp((string) $a['due_date'], (string) $b['due_date']));

        return array_slice($items, 0, $limit);
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
     * Site safety league — incidents (HsEvent) + open hazards per site over the period,
     * ranked by a simple risk score (incidents weighted ×2). Feeds the Overview league bars.
     *
     * @return array<int, array{id: int, name: string, incidents: int, hazards: int}>
     */
    public function siteLeague(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();

        // Two grouped queries (not 2 per site) — avoid an N+1 on the dashboard endpoint.
        $incidentsBySite = HsEvent::query()
            ->whereIn('event_category', [HsEvent::CATEGORY_INCIDENT, HsEvent::CATEGORY_NEAR_MISS, HsEvent::CATEGORY_INJURY])
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('site_id')
            ->select('site_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('site_id')
            ->pluck('aggregate', 'site_id');

        $hazardsBySite = SiteHazard::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('site_id')
            ->select('site_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('site_id')
            ->pluck('aggregate', 'site_id');

        return Site::orderBy('name')->get(['id', 'name'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'incidents' => (int) ($incidentsBySite[$site->id] ?? 0),
                'hazards' => (int) ($hazardsBySite[$site->id] ?? 0),
            ])
            ->sortByDesc(fn ($s) => $s['incidents'] * 2 + $s['hazards'])
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Combined summary for main dashboard */
    /* ------------------------------------------------------------------ */

    public function getDashboardSummary(
        ?Carbon $since = null,
        ?int $siteId = null,
        ?User $viewer = null,
    ): array {
        return [
            'events' => $this->getEventKpis($since, $siteId, $viewer),
            'investigations' => $this->getInvestigationKpis(),
            'corrective_actions' => $this->getCorrectiveActionKpis(),
            'risk_assessments' => $this->getRiskAssessmentKpis(),
            'training' => $this->getTrainingComplianceKpis(),
        ];
    }

    private function hsEventQuery(?int $siteId, ?User $viewer): Builder
    {
        $query = HsEvent::query();

        if ($viewer !== null) {
            $this->siteAccess->applyHsEventScope(
                $query,
                $viewer,
                ['healthSafety.viewAllSites'],
            );
        }

        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        return $query;
    }
}
