<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\HsTrainingRequirement;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Board-level H&S summary service for governance integration.
 *
 * Produces aggregated, decision-focused data suitable for:
 *  - Board packs / dashboard snapshots
 *  - Audit & Risk Committee reports
 *  - CEO board reports
 *  - WorkSafe compliance summaries
 *
 * All data comes from the HsEvent backbone — not raw source models.
 * This is the single governance-facing surface for H&S data.
 */
class HsGovernanceService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Board-level summary (for board packs / dashboard snapshots) */
    /* ------------------------------------------------------------------ */

    /**
     * Comprehensive H&S posture summary for board consumption.
     */
    public function getBoardSummary(
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
        ?User $viewer = null,
    ): array {
        $start = $periodStart ?? now()->subMonth()->startOfMonth();
        $end = $periodEnd ?? now();

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'event_summary' => $this->getEventSummary($start, $end),
            'investigation_summary' => $this->getInvestigationSummary(),
            'corrective_action_summary' => $this->getCorrectiveActionSummary(),
            'risk_posture' => $this->getRiskPosture($viewer),
            'worksafe_status' => $this->getWorksafeStatus($start, $end),
            'training_compliance' => $this->getTrainingComplianceSummary(),
            'overall_status' => $this->calculateOverallStatus($viewer),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Individual summary sections */
    /* ------------------------------------------------------------------ */

    public function getEventSummary(Carbon $start, Carbon $end): array
    {
        $periodEvents = HsEvent::whereBetween('reported_at', [$start, $end]);

        return [
            'total_period' => (clone $periodEvents)->count(),
            'by_category' => (clone $periodEvents)
                ->select('event_category', DB::raw('COUNT(*) as count'))
                ->groupBy('event_category')
                ->pluck('count', 'event_category')
                ->toArray(),
            'by_severity' => (clone $periodEvents)
                ->select('severity', DB::raw('COUNT(*) as count'))
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),
            'open_total' => HsEvent::open()->count(),
            'open_high_critical' => HsEvent::open()->highOrCritical()->count(),
            'closed_period' => HsEvent::whereBetween('closed_at', [$start, $end])->count(),
        ];
    }

    public function getInvestigationSummary(): array
    {
        return [
            'active' => HsInvestigation::active()->count(),
            'overdue' => HsInvestigation::overdue()->count(),
            'completed_with_findings' => HsInvestigation::ofStatus(HsInvestigation::STATUS_COMPLETED)
                ->whereNotNull('findings_summary')
                ->count(),
            'awaiting_review' => HsInvestigation::ofStatus(HsInvestigation::STATUS_UNDER_REVIEW)->count(),
            'by_type' => HsInvestigation::active()
                ->select('investigation_type', DB::raw('COUNT(*) as count'))
                ->groupBy('investigation_type')
                ->pluck('count', 'investigation_type')
                ->toArray(),
        ];
    }

    public function getCorrectiveActionSummary(): array
    {
        return [
            'open' => HsCorrectiveAction::open()->count(),
            'overdue' => HsCorrectiveAction::overdue()->count(),
            'awaiting_verification' => HsCorrectiveAction::awaitingVerification()->count(),
            'verified_period' => HsCorrectiveAction::where('status', HsCorrectiveAction::STATUS_VERIFIED)->count(),
            'effectiveness_rate' => $this->calculateEffectivenessRate(),
            'by_priority' => HsCorrectiveAction::open()
                ->select('priority', DB::raw('COUNT(*) as count'))
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray(),
        ];
    }

    public function getRiskPosture(?User $viewer = null): array
    {
        $active = $this->riskAssessmentQuery($viewer)->active();

        return [
            'total_active' => (clone $active)->count(),
            'by_level' => (clone $active)
                ->select('risk_level', DB::raw('COUNT(*) as count'))
                ->groupBy('risk_level')
                ->pluck('count', 'risk_level')
                ->toArray(),
            'extreme_risks' => (clone $active)->where('risk_level', 'extreme')->count(),
            'high_risks' => (clone $active)->where('risk_level', 'high')->count(),
            'unacceptable_risks' => (clone $active)->where('risk_acceptable', false)->count(),
            'due_for_review' => $this->riskAssessmentQuery($viewer)->dueForReview()->count(),
        ];
    }

    public function getWorksafeStatus(Carbon $start, Carbon $end): array
    {
        $notifiable = HsEvent::where('worksafe_notifiable', true);

        return [
            'notifiable_period' => (clone $notifiable)->whereBetween('reported_at', [$start, $end])->count(),
            'notifiable_open' => (clone $notifiable)->open()->count(),
            'pending_notification' => HsEvent::where('worksafe_notifiable', true)
                ->where('worksafe_status', HsEvent::WORKSAFE_PENDING)
                ->count(),
            'notified' => HsEvent::where('worksafe_notifiable', true)
                ->whereIn('worksafe_status', [HsEvent::WORKSAFE_NOTIFIED, HsEvent::WORKSAFE_ACKNOWLEDGED])
                ->whereBetween('reported_at', [$start, $end])
                ->count(),
            'days_since_last_notifiable' => $this->daysSinceLastNotifiable(),
        ];
    }

    public function getTrainingComplianceSummary(): array
    {
        $requirements = HsTrainingRequirement::active()->get();

        if ($requirements->isEmpty()) {
            return [
                'total_requirements' => 0,
                'blocking_count' => 0,
                'non_compliant_staff' => 0,
                'compliance_rate' => 100,
            ];
        }

        $hrIds = $requirements->pluck('hr_compliance_requirement_id')->filter();
        $nonCompliant = 0;
        $totalChecked = 0;

        if ($hrIds->isNotEmpty()) {
            $nonCompliant = HrStaffComplianceStatus::whereIn('requirement_id', $hrIds)
                ->whereIn('status', ['expired', 'not_started'])
                ->distinct('user_id')
                ->count('user_id');

            $totalChecked = HrStaffComplianceStatus::whereIn('requirement_id', $hrIds)
                ->distinct('user_id')
                ->count('user_id');
        }

        return [
            'total_requirements' => $requirements->count(),
            'blocking_count' => $requirements->where('enforcement_mode', 'block')->count(),
            'non_compliant_staff' => $nonCompliant,
            'compliance_rate' => $totalChecked > 0
                ? (int) round((($totalChecked - $nonCompliant) / $totalChecked) * 100)
                : 100,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Board pack widget data (for DashboardAggregatorService) */
    /* ------------------------------------------------------------------ */

    /**
     * Compact widget data for governance dashboard snapshot.
     * This is the format consumed by DashboardAggregatorService.
     */
    public function getWidgetData(array $range, ?User $viewer = null): array
    {
        $start = Carbon::parse($range['start']);
        $end = Carbon::parse($range['end']);

        $eventSummary = $this->getEventSummary($start, $end);
        $investigations = $this->getInvestigationSummary();
        $actions = $this->getCorrectiveActionSummary();
        $riskPosture = $this->getRiskPosture($viewer);
        $worksafe = $this->getWorksafeStatus($start, $end);

        return [
            'events_period' => $eventSummary['total_period'],
            'open_events' => $eventSummary['open_total'],
            'open_high_critical' => $eventSummary['open_high_critical'],
            'active_investigations' => $investigations['active'],
            'overdue_investigations' => $investigations['overdue'],
            'open_corrective_actions' => $actions['open'],
            'overdue_corrective_actions' => $actions['overdue'],
            'awaiting_verification' => $actions['awaiting_verification'],
            'effectiveness_rate' => $actions['effectiveness_rate'],
            'extreme_risks' => $riskPosture['extreme_risks'],
            'high_risks' => $riskPosture['high_risks'],
            'risk_reviews_due' => $riskPosture['due_for_review'],
            'worksafe_pending' => $worksafe['pending_notification'],
            'days_since_notifiable' => $worksafe['days_since_last_notifiable'],
            'status' => $this->calculateOverallStatus($viewer),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers */
    /* ------------------------------------------------------------------ */

    private function calculateEffectivenessRate(): int
    {
        $verified = HsCorrectiveAction::where('status', HsCorrectiveAction::STATUS_VERIFIED);
        $total = (clone $verified)->count();

        if ($total === 0) {
            return 100;
        }

        $effective = (clone $verified)->where('effectiveness_confirmed', true)->count();

        return (int) round(($effective / $total) * 100);
    }

    private function daysSinceLastNotifiable(): ?int
    {
        $last = HsEvent::where('worksafe_notifiable', true)
            ->orderByDesc('reported_at')
            ->value('reported_at');

        return $last ? (int) Carbon::parse($last)->diffInDays(now()) : null;
    }

    private function calculateOverallStatus(?User $viewer = null): string
    {
        $overdueActions = HsCorrectiveAction::overdue()->count();
        $overdueInvestigations = HsInvestigation::overdue()->count();
        $pendingWorksafe = HsEvent::where('worksafe_notifiable', true)
            ->where('worksafe_status', HsEvent::WORKSAFE_PENDING)
            ->count();
        $extremeRisks = $this->riskAssessmentQuery($viewer)
            ->active()
            ->where('risk_level', 'extreme')
            ->count();

        if ($pendingWorksafe > 0 || $extremeRisks > 0) {
            return 'critical';
        }

        if ($overdueActions > 3 || $overdueInvestigations > 0) {
            return 'warning';
        }

        return 'good';
    }

    private function riskAssessmentQuery(?User $viewer): Builder
    {
        $query = HsRiskAssessment::query();

        return $viewer
            ? $this->siteAccess->applyHsRiskAssessmentScope(
                $query,
                $viewer,
                ['healthSafety.viewAllSites'],
            )
            : $this->siteAccess->applyHsRiskAssessmentApplicationScope($query);
    }
}
