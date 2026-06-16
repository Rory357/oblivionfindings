<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\EmergencyDrill;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\HsTrainingRequirement;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SiteHazard;
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
    /* ------------------------------------------------------------------ */
    /*  Event KPIs                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Core H&S KPIs powered by the HsEvent backbone.
     */
    public function getEventKpis(?Carbon $since = null): array
    {
        $since = $since ?? now()->subDays(30);

        return [
            'open_events' => HsEvent::open()->count(),
            'open_events_high_critical' => HsEvent::open()->highOrCritical()->count(),
            'events_period' => HsEvent::where('reported_at', '>=', $since)->count(),
            'events_by_category' => HsEvent::where('reported_at', '>=', $since)
                ->select('event_category', DB::raw('COUNT(*) as count'))
                ->groupBy('event_category')
                ->pluck('count', 'event_category')
                ->toArray(),
            'events_by_severity' => HsEvent::open()
                ->select('severity', DB::raw('COUNT(*) as count'))
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),
            'worksafe_notifiable_open' => HsEvent::open()
                ->worksafeNotifiable()
                ->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Investigation KPIs                                                 */
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
    /*  Corrective Action KPIs                                             */
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
    /*  Risk Assessment KPIs                                               */
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
    /*  Training Compliance KPIs                                           */
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
    /*  Worklist row builders (G6) — actionable rows, not just counts      */
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
     * WorkSafe notifiable events as worklist rows (awaiting-notification first).
     * Org/PCBU-level — not site-scoped. `related_incident_id` deep-links to the source incident.
     */
    public function notifiableEvents(int $limit = 10): array
    {
        return NotifiableIncident::query()
            ->whereNull('closed_at')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (NotifiableIncident $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'incident_type' => $n->incident_type,
                'status' => $n->status,
                'occurred_at' => optional($n->occurred_at)->toIso8601String(),
                'notified_at' => optional($n->notified_at)->toIso8601String(),
                'notification_deadline' => optional($n->notification_deadline)->toIso8601String(),
                'site_preserved' => (bool) $n->site_preserved,
                'worksafe_ref' => $n->notification_reference,
                'related_incident_id' => $n->related_incident_id,
            ])
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Unified expiring feed (G5)                                         */
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
            $items[] = $this->expiringItem('risk_assessment', 'Risk assessment review', $ra->title ?: $ra->reference_number, $ra->review_due_at, '/health-safety/risk-assessments');
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
    /*  Combined summary for main dashboard                                */
    /* ------------------------------------------------------------------ */

    public function getDashboardSummary(?Carbon $since = null): array
    {
        return [
            'events' => $this->getEventKpis($since),
            'investigations' => $this->getInvestigationKpis(),
            'corrective_actions' => $this->getCorrectiveActionKpis(),
            'risk_assessments' => $this->getRiskAssessmentKpis(),
            'training' => $this->getTrainingComplianceKpis(),
        ];
    }
}
