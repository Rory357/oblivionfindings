<?php

namespace App\Services\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Structured compliance exports for WorkSafe, auditors, and governance.
 *
 * Each method returns a consistently shaped array with this envelope:
 *  - report_type: machine identifier
 *  - title: human-readable report name
 *  - generated_at: ISO 8601 timestamp
 *  - period: {from, to} date range (nullable for point-in-time reports)
 *  - summary: aggregated metrics
 *  - items: detail rows
 *
 * Designed for: JSON API response, future PDF rendering, board pack
 * attachment, and audit evidence packs.
 */
class HsComplianceExportService
{
    /** @var list<string> */
    private const SITE_BYPASS_PERMISSIONS = UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Report type identifiers — stable contract values */
    /*  These are used in report envelopes and must not be renamed. */
    /* ------------------------------------------------------------------ */

    public const REPORT_WORKSAFE_REGISTER = 'worksafe_register';

    public const REPORT_INVESTIGATION_OUTCOMES = 'investigation_outcomes';

    public const REPORT_CORRECTIVE_ACTION_TRACEABILITY = 'corrective_action_traceability';

    public const REPORT_RISK_ASSESSMENT_REGISTER = 'risk_assessment_register';

    /** All available report types. */
    public const REPORT_TYPES = [
        self::REPORT_WORKSAFE_REGISTER,
        self::REPORT_INVESTIGATION_OUTCOMES,
        self::REPORT_CORRECTIVE_ACTION_TRACEABILITY,
        self::REPORT_RISK_ASSESSMENT_REGISTER,
    ];

    /* ------------------------------------------------------------------ */
    /*  WorkSafe Notifiable Events Register */
    /* ------------------------------------------------------------------ */

    public function worksafeRegister(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?User $viewer = null,
        ?int $siteId = null,
    ): array {
        $query = $this->eventQuery($viewer, $siteId)
            ->where('worksafe_notifiable', true)
            ->with(['site:id,name', 'client:id,first_name,last_name', 'staff:id,name'])
            ->orderByDesc('reported_at');

        if ($from) {
            $query->where('reported_at', '>=', $from);
        }
        if ($to) {
            $query->where('reported_at', '<=', $to);
        }

        return $this->envelope(self::REPORT_WORKSAFE_REGISTER, 'WorkSafe Notifiable Events Register', $from, $to, [
            'total' => (clone $query)->count(),
            'pending_notification' => (clone $query)->where('worksafe_status', HsEvent::WORKSAFE_PENDING)->count(),
            'notified' => (clone $query)->where('worksafe_status', HsEvent::WORKSAFE_NOTIFIED)->count(),
            'acknowledged' => (clone $query)->where('worksafe_status', HsEvent::WORKSAFE_ACKNOWLEDGED)->count(),
        ], $query->get()->map(fn (HsEvent $e) => [
            'reference_number' => $e->reference_number,
            'event_category' => $e->event_category,
            'severity' => $e->severity,
            'status' => $e->status,
            'worksafe_status' => $e->worksafe_status,
            'worksafe_reference' => $e->worksafe_reference,
            'occurred_at' => $e->occurred_at?->toIso8601String(),
            'reported_at' => $e->reported_at?->toIso8601String(),
            'site_name' => $e->site?->name,
            'investigation_required' => $e->investigation_required,
            'has_investigation' => $e->investigations()->exists(),
        ])->toArray());
    }

    /* ------------------------------------------------------------------ */
    /*  Investigation Outcomes Report */
    /* ------------------------------------------------------------------ */

    public function investigationOutcomes(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?User $viewer = null,
        ?int $siteId = null,
    ): array {
        $query = $this->investigationQuery($viewer, $siteId)
            ->ofStatus(HsInvestigation::STATUS_COMPLETED)
            ->with([
                'hsEvent:id,reference_number,event_category,severity,site_id',
                'hsEvent.site:id,name',
                'leadInvestigator:id,name',
                'approvedBy:id,name',
            ])
            ->orderByDesc('completed_at');

        if ($from) {
            $query->where('completed_at', '>=', $from);
        }
        if ($to) {
            $query->where('completed_at', '<=', $to);
        }

        $results = $query->get();

        return $this->envelope(self::REPORT_INVESTIGATION_OUTCOMES, 'Investigation Outcomes Summary', $from, $to, [
            'total_completed' => $results->count(),
            'by_type' => $results->countBy('investigation_type')->toArray(),
            'by_methodology' => $results->countBy('methodology')->filter()->toArray(),
            'total_recommendations' => $results->sum(fn ($inv) => count($inv->recommendations ?? [])),
        ], $results->map(fn (HsInvestigation $inv) => [
            'reference_number' => $inv->reference_number,
            'investigation_type' => $inv->investigation_type,
            'methodology' => $inv->methodology,
            'event_reference' => $inv->hsEvent?->reference_number,
            'event_category' => $inv->hsEvent?->event_category,
            'event_severity' => $inv->hsEvent?->severity,
            'site_name' => $inv->hsEvent?->site?->name,
            'lead_investigator' => $inv->leadInvestigator?->name,
            'started_at' => $inv->started_at?->toIso8601String(),
            'completed_at' => $inv->completed_at?->toIso8601String(),
            'approved_by' => $inv->approvedBy?->name,
            'immediate_causes_count' => count($inv->immediate_causes ?? []),
            'root_causes_count' => count($inv->root_causes ?? []),
            'recommendation_count' => count($inv->recommendations ?? []),
            'findings_summary' => $inv->findings_summary,
            'lessons_learned' => $inv->lessons_learned,
        ])->toArray());
    }

    /* ------------------------------------------------------------------ */
    /*  Corrective Action Traceability Report */
    /* ------------------------------------------------------------------ */

    public function correctiveActionTraceability(
        ?string $statusFilter = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?User $viewer = null,
        ?int $siteId = null,
    ): array {
        $summaryQuery = $this->correctiveActionQuery($viewer, $siteId);
        $query = (clone $summaryQuery)->with([
            'hsEvent:id,reference_number,event_category,severity',
            'hsInvestigation:id,reference_number,investigation_type',
            'assignedTo:id,name',
            'completedBy:id,name',
            'verifiedBy:id,name',
        ])->orderBy('due_date');

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $this->envelope(self::REPORT_CORRECTIVE_ACTION_TRACEABILITY, 'Corrective Action Traceability Report', $from, $to, [
            'total' => (clone $summaryQuery)->count(),
            'open' => (clone $summaryQuery)->open()->count(),
            'overdue' => (clone $summaryQuery)->overdue()->count(),
            'awaiting_verification' => (clone $summaryQuery)->awaitingVerification()->count(),
            'verified' => (clone $summaryQuery)
                ->where('status', HsCorrectiveAction::STATUS_VERIFIED)
                ->count(),
            'closed' => (clone $summaryQuery)
                ->where('status', HsCorrectiveAction::STATUS_CLOSED)
                ->count(),
            'effectiveness_rate' => $this->effectivenessRate($summaryQuery),
            'applied_filter' => $statusFilter,
        ], $query->get()->map(fn (HsCorrectiveAction $a) => [
            'reference_number' => $a->reference_number,
            'title' => $a->title,
            'action_type' => $a->action_type,
            'priority' => $a->priority,
            'status' => $a->status,
            'event_reference' => $a->hsEvent?->reference_number,
            'event_category' => $a->hsEvent?->event_category,
            'investigation_reference' => $a->hsInvestigation?->reference_number,
            'assigned_to' => $a->assignedTo?->name,
            'due_date' => $a->due_date?->toDateString(),
            'is_overdue' => $a->isOverdue(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'completed_by' => $a->completedBy?->name,
            'verified_at' => $a->verified_at?->toIso8601String(),
            'verified_by' => $a->verifiedBy?->name,
            'effectiveness_confirmed' => $a->effectiveness_confirmed,
        ])->toArray());
    }

    /* ------------------------------------------------------------------ */
    /*  Risk Assessment Register */
    /* ------------------------------------------------------------------ */

    public function riskAssessmentRegister(?User $viewer = null, ?int $siteId = null): array
    {
        $query = HsRiskAssessment::query()->active();
        if ($viewer) {
            if ($siteId !== null) {
                $this->assertRequestedSite($viewer, $siteId);
                $this->siteAccess->applyHsRiskAssessmentSiteScopeForSiteIds($query, [$siteId]);
            } else {
                $this->siteAccess->applyHsRiskAssessmentScope(
                    $query,
                    $viewer,
                    self::SITE_BYPASS_PERMISSIONS,
                );
            }
        } else {
            abort_if($siteId !== null, 403, UserSiteAccessService::DEFAULT_MESSAGE);
            $query->whereRaw('1 = 0');
        }

        $assessments = $query
            ->with(['assessedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('risk_score')
            ->get();

        return $this->envelope(self::REPORT_RISK_ASSESSMENT_REGISTER, 'H&S Risk Assessment Register', null, null, [
            'total_active' => $assessments->count(),
            'extreme' => $assessments->where('risk_level', 'extreme')->count(),
            'high' => $assessments->where('risk_level', 'high')->count(),
            'medium' => $assessments->where('risk_level', 'medium')->count(),
            'low' => $assessments->where('risk_level', 'low')->count(),
            'due_for_review' => $assessments->filter(fn ($a) => $a->isDueForReview())->count(),
            'unacceptable' => $assessments->where('risk_acceptable', false)->count(),
        ], $assessments->map(fn (HsRiskAssessment $ra) => [
            'reference_number' => $ra->reference_number,
            'title' => $ra->title,
            'assessable_type' => $ra->assessable_type ? class_basename($ra->assessable_type) : null,
            'likelihood' => $ra->likelihood,
            'consequence' => $ra->consequence,
            'risk_score' => $ra->risk_score,
            'risk_level' => $ra->risk_level,
            'residual_risk_score' => $ra->residual_risk_score,
            'residual_risk_level' => $ra->residual_risk_level,
            'risk_acceptable' => $ra->risk_acceptable,
            'assessed_by' => $ra->assessedBy?->name,
            'approved_by' => $ra->approvedBy?->name,
            'review_due_at' => $ra->review_due_at?->toDateString(),
            'is_due_for_review' => $ra->isDueForReview(),
        ])->toArray());
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Consistent report envelope for all exports.
     */
    private function envelope(string $reportType, string $title, ?Carbon $from, ?Carbon $to, array $summary, array $items): array
    {
        return [
            'report_type' => $reportType,
            'title' => $title,
            'generated_at' => now()->toIso8601String(),
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'summary' => $summary,
            'item_count' => count($items),
            'items' => $items,
        ];
    }

    private function effectivenessRate(Builder $actions): int
    {
        $verified = (clone $actions)->where('status', HsCorrectiveAction::STATUS_VERIFIED);
        $total = (clone $verified)->count();
        if ($total === 0) {
            return 100;
        }
        $effective = (clone $verified)
            ->where('effectiveness_confirmed', true)->count();

        return (int) round(($effective / $total) * 100);
    }

    private function eventQuery(?User $viewer, ?int $siteId): Builder
    {
        $query = HsEvent::query();
        if (! $viewer) {
            abort_if($siteId !== null, 403, UserSiteAccessService::DEFAULT_MESSAGE);

            return $query->whereRaw('1 = 0');
        }

        $this->siteAccess->applyHsEventScope($query, $viewer, self::SITE_BYPASS_PERMISSIONS);
        if ($siteId !== null) {
            $this->assertRequestedSite($viewer, $siteId);
            $query->where('site_id', $siteId);
        }

        return $query;
    }

    private function investigationQuery(?User $viewer, ?int $siteId): Builder
    {
        return HsInvestigation::query()->whereHas(
            'hsEvent',
            fn (Builder $event): Builder => $this->scopeEventBuilder($event, $viewer, $siteId),
        );
    }

    private function correctiveActionQuery(?User $viewer, ?int $siteId): Builder
    {
        return HsCorrectiveAction::query()->whereHas(
            'hsEvent',
            fn (Builder $event): Builder => $this->scopeEventBuilder($event, $viewer, $siteId),
        );
    }

    private function scopeEventBuilder(Builder $query, ?User $viewer, ?int $siteId): Builder
    {
        if (! $viewer) {
            abort_if($siteId !== null, 403, UserSiteAccessService::DEFAULT_MESSAGE);

            return $query->whereRaw('1 = 0');
        }

        $this->siteAccess->applyHsEventScope($query, $viewer, self::SITE_BYPASS_PERMISSIONS);
        if ($siteId !== null) {
            $this->assertRequestedSite($viewer, $siteId);
            $query->where($query->qualifyColumn('site_id'), $siteId);
        }

        return $query;
    }

    private function assertRequestedSite(User $viewer, int $siteId): void
    {
        $this->siteAccess->assertCanAccessSiteId(
            $viewer,
            $siteId,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }
}
