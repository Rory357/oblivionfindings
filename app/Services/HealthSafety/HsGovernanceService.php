<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\HsTrainingRequirement;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
    /** @var list<string> */
    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites'];

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
        ?int $siteId = null,
    ): array {
        $start = $periodStart ?? now()->subMonth()->startOfMonth();
        $end = $periodEnd ?? now();

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'event_summary' => $this->getEventSummary($start, $end, $viewer, $siteId),
            'investigation_summary' => $this->getInvestigationSummary($viewer, $siteId),
            'corrective_action_summary' => $this->getCorrectiveActionSummary($viewer, $siteId),
            'risk_posture' => $this->getRiskPosture($viewer, $siteId),
            'worksafe_status' => $this->getWorksafeStatus($start, $end, $viewer, $siteId),
            'training_compliance' => $this->getTrainingComplianceSummary($viewer, $siteId),
            'overall_status' => $this->calculateOverallStatus($viewer, $siteId),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Individual summary sections */
    /* ------------------------------------------------------------------ */

    public function getEventSummary(
        Carbon $start,
        Carbon $end,
        ?User $viewer = null,
        ?int $siteId = null,
    ): array {
        $events = $this->eventQuery($viewer, $siteId);
        $periodEvents = (clone $events)->whereBetween('reported_at', [$start, $end]);

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
            'open_total' => (clone $events)->open()->count(),
            'open_high_critical' => (clone $events)->open()->highOrCritical()->count(),
            'closed_period' => (clone $events)->whereBetween('closed_at', [$start, $end])->count(),
        ];
    }

    public function getInvestigationSummary(?User $viewer = null, ?int $siteId = null): array
    {
        $investigations = $this->investigationQuery($viewer, $siteId);

        return [
            'active' => (clone $investigations)->active()->count(),
            'overdue' => (clone $investigations)->overdue()->count(),
            'completed_with_findings' => (clone $investigations)
                ->ofStatus(HsInvestigation::STATUS_COMPLETED)
                ->whereNotNull('findings_summary')
                ->count(),
            'awaiting_review' => (clone $investigations)
                ->ofStatus(HsInvestigation::STATUS_UNDER_REVIEW)
                ->count(),
            'by_type' => (clone $investigations)->active()
                ->select('investigation_type', DB::raw('COUNT(*) as count'))
                ->groupBy('investigation_type')
                ->pluck('count', 'investigation_type')
                ->toArray(),
        ];
    }

    public function getCorrectiveActionSummary(?User $viewer = null, ?int $siteId = null): array
    {
        $actions = $this->correctiveActionQuery($viewer, $siteId);

        return [
            'open' => (clone $actions)->open()->count(),
            'overdue' => (clone $actions)->overdue()->count(),
            'awaiting_verification' => (clone $actions)->awaitingVerification()->count(),
            'verified_period' => (clone $actions)
                ->where('status', HsCorrectiveAction::STATUS_VERIFIED)
                ->count(),
            'effectiveness_rate' => $this->calculateEffectivenessRate($actions),
            'by_priority' => (clone $actions)->open()
                ->select('priority', DB::raw('COUNT(*) as count'))
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray(),
        ];
    }

    public function getRiskPosture(?User $viewer = null, ?int $siteId = null): array
    {
        $active = $this->riskAssessmentQuery($viewer, $siteId)->active();

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
            'due_for_review' => $this->riskAssessmentQuery($viewer, $siteId)->dueForReview()->count(),
        ];
    }

    public function getWorksafeStatus(
        Carbon $start,
        Carbon $end,
        ?User $viewer = null,
        ?int $siteId = null,
    ): array {
        $notifiable = $this->eventQuery($viewer, $siteId)
            ->where('worksafe_notifiable', true);

        return [
            'notifiable_period' => (clone $notifiable)->whereBetween('reported_at', [$start, $end])->count(),
            'notifiable_open' => (clone $notifiable)->open()->count(),
            'pending_notification' => (clone $notifiable)
                ->where('worksafe_status', HsEvent::WORKSAFE_PENDING)
                ->count(),
            'notified' => (clone $notifiable)
                ->whereIn('worksafe_status', [HsEvent::WORKSAFE_NOTIFIED, HsEvent::WORKSAFE_ACKNOWLEDGED])
                ->whereBetween('reported_at', [$start, $end])
                ->count(),
            'days_since_last_notifiable' => $this->daysSinceLastNotifiable($viewer, $siteId),
        ];
    }

    public function getTrainingComplianceSummary(?User $viewer = null, ?int $siteId = null): array
    {
        $requirements = $this->trainingRequirements($viewer, $siteId);

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
            $statuses = HrStaffComplianceStatus::query()->whereIn('requirement_id', $hrIds);
            if ($viewer) {
                $statuses->whereIn('user_id', $this->staffQuery($viewer, $siteId)->select('users.id'));
            }

            $nonCompliant = (clone $statuses)
                ->whereIn('status', ['expired', 'not_started'])
                ->distinct('user_id')
                ->count('user_id');

            $totalChecked = (clone $statuses)
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
    public function getWidgetData(array $range, ?User $viewer = null, ?int $siteId = null): array
    {
        $start = Carbon::parse($range['start']);
        $end = Carbon::parse($range['end']);

        $eventSummary = $this->getEventSummary($start, $end, $viewer, $siteId);
        $investigations = $this->getInvestigationSummary($viewer, $siteId);
        $actions = $this->getCorrectiveActionSummary($viewer, $siteId);
        $riskPosture = $this->getRiskPosture($viewer, $siteId);
        $worksafe = $this->getWorksafeStatus($start, $end, $viewer, $siteId);

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
            'status' => $this->calculateOverallStatus($viewer, $siteId),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers */
    /* ------------------------------------------------------------------ */

    private function calculateEffectivenessRate(Builder $actions): int
    {
        $verified = (clone $actions)->where('status', HsCorrectiveAction::STATUS_VERIFIED);
        $total = (clone $verified)->count();

        if ($total === 0) {
            return 100;
        }

        $effective = (clone $verified)->where('effectiveness_confirmed', true)->count();

        return (int) round(($effective / $total) * 100);
    }

    private function daysSinceLastNotifiable(?User $viewer, ?int $siteId): ?int
    {
        $last = $this->eventQuery($viewer, $siteId)
            ->where('worksafe_notifiable', true)
            ->orderByDesc('reported_at')
            ->value('reported_at');

        return $last ? (int) Carbon::parse($last)->diffInDays(now()) : null;
    }

    private function calculateOverallStatus(?User $viewer = null, ?int $siteId = null): string
    {
        $overdueActions = $this->correctiveActionQuery($viewer, $siteId)->overdue()->count();
        $overdueInvestigations = $this->investigationQuery($viewer, $siteId)->overdue()->count();
        $pendingWorksafe = $this->eventQuery($viewer, $siteId)
            ->where('worksafe_notifiable', true)
            ->where('worksafe_status', HsEvent::WORKSAFE_PENDING)
            ->count();
        $extremeRisks = $this->riskAssessmentQuery($viewer, $siteId)
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

    private function riskAssessmentQuery(?User $viewer, ?int $siteId = null): Builder
    {
        $query = HsRiskAssessment::query();

        if (! $viewer) {
            abort_if($siteId !== null, 403, UserSiteAccessService::DEFAULT_MESSAGE);

            return $query->whereRaw('1 = 0');
        }

        if ($siteId !== null) {
            $this->assertRequestedSite($viewer, $siteId);

            return $this->siteAccess->applyHsRiskAssessmentSiteScopeForSiteIds($query, [$siteId]);
        }

        return $this->siteAccess->applyHsRiskAssessmentScope(
            $query,
            $viewer,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    private function eventQuery(?User $viewer, ?int $siteId = null): Builder
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

    private function investigationQuery(?User $viewer, ?int $siteId = null): Builder
    {
        return HsInvestigation::query()->whereHas(
            'hsEvent',
            fn (Builder $event): Builder => $this->scopeEventBuilder($event, $viewer, $siteId),
        );
    }

    private function correctiveActionQuery(?User $viewer, ?int $siteId = null): Builder
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

    private function staffQuery(User $viewer, ?int $siteId): Builder
    {
        $query = User::query();
        $this->siteAccess->applyStaffScope($query, $viewer, self::SITE_BYPASS_PERMISSIONS);
        if ($siteId !== null) {
            $this->assertRequestedSite($viewer, $siteId);
            $query->whereHas('hrEmployeeProfile', function (Builder $profile) use ($siteId): void {
                $profile->where('primary_site_id', $siteId)
                    ->orWhereJsonContains('secondary_site_ids', $siteId);
            });
        }

        return $query;
    }

    /** @return Collection<int, HsTrainingRequirement> */
    private function trainingRequirements(?User $viewer, ?int $siteId): Collection
    {
        $requirements = HsTrainingRequirement::active()->get();
        if (! $viewer) {
            abort_if($siteId !== null, 403, UserSiteAccessService::DEFAULT_MESSAGE);

            return $requirements->take(0);
        }

        $siteIds = $siteId !== null
            ? [$this->assertRequestedSite($viewer, $siteId)]
            : $this->siteAccess->accessibleSiteIds($viewer, self::SITE_BYPASS_PERMISSIONS);
        if ($siteIds === []) {
            return $requirements->take(0);
        }

        $clientIds = Client::query()
            ->whereIn('site_id', $siteIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $requirements->filter(function (HsTrainingRequirement $requirement) use ($clientIds, $siteIds): bool {
            return match ($requirement->scope_type) {
                HsTrainingRequirement::SCOPE_GLOBAL,
                HsTrainingRequirement::SCOPE_ROLE => true,
                HsTrainingRequirement::SCOPE_SITE => array_intersect(
                    $siteIds,
                    array_map('intval', $requirement->scope_site_ids ?? []),
                ) !== [],
                HsTrainingRequirement::SCOPE_CLIENT => array_intersect(
                    $clientIds,
                    array_map('intval', $requirement->scope_client_ids ?? []),
                ) !== [],
                default => false,
            };
        })->values();
    }

    private function assertRequestedSite(User $viewer, int $siteId): int
    {
        $this->siteAccess->assertCanAccessSiteId(
            $viewer,
            $siteId,
            self::SITE_BYPASS_PERMISSIONS,
        );

        return $siteId;
    }
}
