<?php

namespace App\Services\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\HsTrainingRequirement;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use Carbon\Carbon;
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
