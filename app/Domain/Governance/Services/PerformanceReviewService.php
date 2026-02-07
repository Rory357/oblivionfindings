<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\PerformanceGoal;
use App\Domain\Governance\Models\PerformanceKpi;
use App\Domain\Governance\Models\PerformanceReview;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\SafeguardingConcern;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;

class PerformanceReviewService
{
    const PILLARS = ['safety', 'quality', 'people', 'finance', 'compliance', 'it_resilience'];

    /**
     * Create a new performance review
     */
    public function createReview(
        User $reviewee,
        string $cycle,
        string $type,
        Carbon $periodStart,
        Carbon $periodEnd,
        User $createdBy
    ): PerformanceReview {
        return PerformanceReview::create([
            'reviewee_id' => $reviewee->id,
            'review_cycle' => $cycle,
            'review_type' => $type,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'drafting',
            'created_by' => $createdBy->id,
        ]);
    }

    /**
     * Add a goal to the review
     */
    public function addGoal(
        PerformanceReview $review,
        string $pillar,
        string $description,
        string $successCriteria,
        float $weight,
        float $targetScore = 3.0
    ): PerformanceGoal {
        return PerformanceGoal::create([
            'performance_review_id' => $review->id,
            'pillar' => $pillar,
            'goal_description' => $description,
            'success_criteria' => $successCriteria,
            'weight' => $weight,
            'target_score' => $targetScore,
            'status' => 'not_started',
        ]);
    }

    /**
     * Add a KPI to the review
     */
    public function addKpi(
        PerformanceReview $review,
        string $pillar,
        string $name,
        string $definition,
        string $dataSource,
        $targetValue,
        string $unit,
        bool $isAutomated = true
    ): PerformanceKpi {
        return PerformanceKpi::create([
            'performance_review_id' => $review->id,
            'pillar' => $pillar,
            'kpi_name' => $name,
            'kpi_definition' => $definition,
            'data_source' => $dataSource,
            'target_value' => $targetValue,
            'unit' => $unit,
            'period_start' => $review->period_start,
            'period_end' => $review->period_end,
            'is_automated' => $isAutomated,
        ]);
    }

    /**
     * Sync automated KPIs from operational data
     */
    public function syncKpis(PerformanceReview $review): void
    {
        $kpis = $review->kpis()->automated()->get();

        foreach ($kpis as $kpi) {
            $value = $this->fetchKpiValue($kpi);
            if ($value !== null) {
                $kpi->update([
                    'actual_value' => $value,
                    'last_synced_at' => now(),
                ]);
            }
        }
    }

    /**
     * Fetch KPI value from data source
     */
    protected function fetchKpiValue(PerformanceKpi $kpi): mixed
    {
        $period = [
            'start' => $kpi->period_start,
            'end' => $kpi->period_end,
        ];

        return match($kpi->data_source) {
            'safety.incident_rate' => $this->calculateIncidentRate($period),
            'safety.serious_incidents' => $this->countSeriousIncidents($period),
            'safeguarding.concerns' => $this->countSafeguardingConcerns($period),
            'quality.audit_score' => $this->getLatestAuditScore(),
            'people.turnover_rate' => $this->calculateTurnoverRate($period),
            'people.training_compliance' => $this->calculateTrainingCompliance(),
            'finance.budget_variance' => $this->getBudgetVariance(),
            'compliance.training_compliance' => $this->calculateComplianceTraining(),
            'it_resilience.uptime' => $this->calculateUptime($period),
            'it_resilience.security_incidents' => $this->countSecurityIncidents($period),
            default => null,
        };
    }

    /**
     * Calculate incident rate per 1000 client-days
     */
    protected function calculateIncidentRate(array $period): ?float
    {
        $count = ClientIncident::whereBetween('occurred_at', [$period['start'], $period['end']])->count();
        
        // Calculate client-days (would need actual client-day count)
        $clientDays = 1000; // Placeholder
        
        return $clientDays > 0 ? round(($count / $clientDays) * 1000, 2) : null;
    }

    /**
     * Count serious incidents
     */
    protected function countSeriousIncidents(array $period): int
    {
        return ClientIncident::whereBetween('occurred_at', [$period['start'], $period['end']])
            ->whereIn('severity', ['high', 'critical'])
            ->count();
    }

    /**
     * Count safeguarding concerns
     */
    protected function countSafeguardingConcerns(array $period): int
    {
        return SafeguardingConcern::whereBetween('reported_at', [$period['start'], $period['end']])->count();
    }

    /**
     * Get latest audit score
     */
    protected function getLatestAuditScore(): ?float
    {
        // Would integrate with audit system
        return null;
    }

    /**
     * Calculate staff turnover rate
     */
    protected function calculateTurnoverRate(array $period): ?float
    {
        // Would need staff movement data
        return null;
    }

    /**
     * Calculate training compliance percentage
     */
    protected function calculateTrainingCompliance(): ?float
    {
        // Would integrate with training module
        return null;
    }

    /**
     * Get current budget variance
     */
    protected function getBudgetVariance(): ?float
    {
        $budget = \App\Domain\Governance\Models\Budget::approved()->latest()->first();
        return $budget?->getVariancePercentage();
    }

    /**
     * Calculate compliance training completion
     */
    protected function calculateComplianceTraining(): ?float
    {
        // Would integrate with training module
        return null;
    }

    /**
     * Calculate system uptime percentage
     */
    protected function calculateUptime(array $period): ?float
    {
        $totalMinutes = $period['start']->diffInMinutes($period['end']);
        
        $downtimeMinutes = ControlRoomAlert::whereBetween('triggered_at', [$period['start'], $period['end']])
            ->where('alert_type', 'like', '%outage%')
            ->whereNotNull('resolved_at')
            ->selectRaw('SUM(TIMESTAMPDIFF(MINUTE, triggered_at, resolved_at)) as total')
            ->value('total') ?? 0;

        return $totalMinutes > 0 
            ? round((($totalMinutes - $downtimeMinutes) / $totalMinutes) * 100, 2)
            : null;
    }

    /**
     * Count security incidents
     */
    protected function countSecurityIncidents(array $period): int
    {
        return ControlRoomAlert::whereBetween('triggered_at', [$period['start'], $period['end']])
            ->where('alert_type', 'like', '%security%')
            ->count();
    }

    /**
     * Generate default goals for CEO review
     */
    public function generateDefaultGoals(PerformanceReview $review): void
    {
        $defaults = [
            [
                'pillar' => 'safety',
                'description' => 'Maintain or improve safety outcomes for supported people',
                'criteria' => 'Zero serious incidents; all incidents investigated within 48 hours',
                'weight' => 20,
            ],
            [
                'pillar' => 'quality',
                'description' => 'Achieve quality standards in service delivery',
                'criteria' => 'Audit score >85%; all corrective actions completed on time',
                'weight' => 15,
            ],
            [
                'pillar' => 'people',
                'description' => 'Maintain workforce engagement and development',
                'criteria' => 'Staff turnover <15%; training compliance >95%',
                'weight' => 15,
            ],
            [
                'pillar' => 'finance',
                'description' => 'Deliver sustainable financial performance',
                'criteria' => 'Budget variance within +/-5%; positive cash flow maintained',
                'weight' => 20,
            ],
            [
                'pillar' => 'compliance',
                'description' => 'Maintain full regulatory compliance',
                'criteria' => 'Zero overdue compliance obligations; all audits passed',
                'weight' => 20,
            ],
            [
                'pillar' => 'it_resilience',
                'description' => 'Ensure IT systems and data security',
                'criteria' => '99.5% uptime; zero security breaches',
                'weight' => 10,
            ],
        ];

        foreach ($defaults as $goal) {
            $this->addGoal(
                $review,
                $goal['pillar'],
                $goal['description'],
                $goal['criteria'],
                $goal['weight']
            );
        }
    }

    /**
     * Generate default KPIs for CEO review
     */
    public function generateDefaultKpis(PerformanceReview $review): void
    {
        $defaults = [
            ['safety', 'Serious Incidents', 'safety.serious_incidents', 0, 'count'],
            ['safety', 'Safeguarding Concerns', 'safeguarding.concerns', 5, 'count'],
            ['quality', 'Audit Score', 'quality.audit_score', 85, 'percentage'],
            ['people', 'Staff Turnover', 'people.turnover_rate', 15, 'percentage'],
            ['people', 'Training Compliance', 'people.training_compliance', 95, 'percentage'],
            ['finance', 'Budget Variance', 'finance.budget_variance', 5, 'percentage'],
            ['compliance', 'Compliance Training', 'compliance.training_compliance', 100, 'percentage'],
            ['it_resilience', 'System Uptime', 'it_resilience.uptime', 99.5, 'percentage'],
            ['it_resilience', 'Security Incidents', 'it_resilience.security_incidents', 0, 'count'],
        ];

        foreach ($defaults as $kpi) {
            $this->addKpi(
                $review,
                $kpi[0],
                $kpi[1],
                $kpi[1],
                $kpi[2],
                $kpi[3],
                $kpi[4]
            );
        }
    }

    /**
     * Generate review scorecard
     */
    public function generateScorecard(PerformanceReview $review): array
    {
        $this->syncKpis($review);

        $pillars = [];
        foreach (self::PILLARS as $pillar) {
            $goals = $review->goals()->byPillar($pillar)->get();
            $kpis = $review->kpis()->byPillar($pillar)->get();

            $pillarScore = $goals->avg('actual_score') ?? 0;

            $pillars[$pillar] = [
                'score' => round($pillarScore, 1),
                'weight' => $goals->sum('weight'),
                'goals' => $goals->map(fn($g) => [
                    'description' => $g->goal_description,
                    'target' => $g->target_score,
                    'actual' => $g->actual_score,
                    'status' => $g->status,
                ]),
                'kpis' => $kpis->map(fn($k) => [
                    'name' => $k->kpi_name,
                    'target' => $k->target_value,
                    'actual' => $k->actual_value,
                    'unit' => $k->unit,
                ]),
            ];
        }

        return [
            'review_info' => [
                'cycle' => $review->review_cycle,
                'type' => $review->review_type,
                'period' => [
                    'start' => $review->period_start->toDateString(),
                    'end' => $review->period_end->toDateString(),
                ],
            ],
            'overall_score' => $review->getWeightedScore(),
            'overall_rating' => $review->overall_rating,
            'pillars' => $pillars,
            'board_decision' => $review->board_decision,
        ];
    }

    /**
     * Submit board assessment
     */
    public function submitBoardAssessment(
        PerformanceReview $review,
        array $goalAssessments,
        string $overallRating,
        string $boardDecision,
        ?string $notes = null
    ): void {
        foreach ($goalAssessments as $goalId => $assessment) {
            $goal = PerformanceGoal::find($goalId);
            if ($goal && $goal->performance_review_id === $review->id) {
                $goal->update([
                    'actual_score' => $assessment['score'],
                    'board_assessment' => $assessment['comments'],
                    'status' => $assessment['score'] >= $goal->target_score ? 'achieved' : 'partially_achieved',
                ]);
            }
        }

        $review->update([
            'overall_rating' => $overallRating,
            'board_decision' => $boardDecision,
            'decision_notes' => $notes,
            'status' => 'board_review',
        ]);
    }
}
