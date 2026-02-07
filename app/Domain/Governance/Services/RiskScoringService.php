<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\RiskAcceptance;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\RiskTreatment;
use App\Models\User;

class RiskScoringService
{
    /**
     * Likelihood scale (1-5)
     */
    const LIKELIHOOD_LABELS = [
        1 => 'Rare',
        2 => 'Unlikely',
        3 => 'Possible',
        4 => 'Likely',
        5 => 'Almost Certain',
    ];

    /**
     * Impact scale (1-5)
     */
    const IMPACT_LABELS = [
        1 => 'Insignificant',
        2 => 'Minor',
        3 => 'Moderate',
        4 => 'Major',
        5 => 'Catastrophic',
    ];

    /**
     * Control effectiveness multipliers
     */
    const CONTROL_MULTIPLIERS = [
        'none' => 1.0,
        'weak' => 0.8,
        'moderate' => 0.5,
        'strong' => 0.2,
    ];

    /**
     * Default risk appetite thresholds by category
     */
    const DEFAULT_APPETITE_THRESHOLDS = [
        'client_safety' => 15,
        'reputational' => 12,
        'financial' => 15,
        'it_cyber' => 12,
        'workforce' => 12,
        'legal_compliance' => 10,
        'operational' => 15,
        'clinical' => 12,
    ];

    /**
     * Calculate inherent risk score
     */
    public function calculateInherentScore(int $likelihood, int $impact): int
    {
        return min(25, $likelihood * $impact);
    }

    /**
     * Calculate residual risk score after controls
     */
    public function calculateResidualScore(int $inherentScore, string $controlEffectiveness): int
    {
        $multiplier = self::CONTROL_MULTIPLIERS[$controlEffectiveness] ?? 1.0;
        return max(1, (int) round($inherentScore * $multiplier));
    }

    /**
     * Get risk level label
     */
    public function getRiskLevel(int $score): string
    {
        return match(true) {
            $score >= 20 => 'Critical',
            $score >= 15 => 'High',
            $score >= 10 => 'Medium',
            $score >= 5 => 'Low',
            default => 'Minimal',
        };
    }

    /**
     * Get color for risk score
     */
    public function getRiskColor(int $score): string
    {
        return match(true) {
            $score >= 20 => '#dc2626', // red-600
            $score >= 15 => '#ea580c', // orange-600
            $score >= 10 => '#ca8a04', // yellow-600
            default => '#16a34a', // green-600
        };
    }

    /**
     * Check if risk is within appetite for its category
     */
    public function isWithinAppetite(RiskRegisterEntry $risk): bool
    {
        $threshold = $risk->appetite_threshold ?? self::DEFAULT_APPETITE_THRESHOLDS[$risk->category] ?? 15;
        return $risk->residual_score <= $threshold;
    }

    /**
     * Get appetite threshold for category
     */
    public function getAppetiteThreshold(string $category): int
    {
        return self::DEFAULT_APPETITE_THRESHOLDS[$category] ?? 15;
    }

    /**
     * Recalculate all risk scores
     */
    public function recalculateRisk(RiskRegisterEntry $risk): void
    {
        $risk->inherent_score = $this->calculateInherentScore($risk->likelihood_score, $risk->impact_score);
        $risk->residual_score = $this->calculateResidualScore($risk->inherent_score, $risk->control_effectiveness);
        $risk->within_appetite = $this->isWithinAppetite($risk);
        $risk->save();
    }

    /**
     * Create a risk acceptance record
     */
    public function acceptRisk(
        RiskRegisterEntry $risk,
        string $type,
        string $justification,
        User $acceptedBy,
        ?int $resolutionId = null,
        ?string $delegatedToRole = null,
        int $expiryMonths = 12,
        array $conditions = []
    ): RiskAcceptance {
        return RiskAcceptance::create([
            'risk_register_entry_id' => $risk->id,
            'acceptance_type' => $type,
            'resolution_id' => $resolutionId,
            'delegated_to_role' => $delegatedToRole,
            'justification' => $justification,
            'conditions' => $conditions,
            'expires_at' => now()->addMonths($expiryMonths),
            'accepted_by' => $acceptedBy->id,
            'accepted_at' => now(),
            'review_due_date' => now()->addMonths($expiryMonths / 2),
        ]);
    }

    /**
     * Create a treatment action for risk
     */
    public function createTreatment(
        RiskRegisterEntry $risk,
        string $description,
        User $assignedTo,
        \DateTime $dueDate,
        User $createdBy,
        ?int $expectedScoreReduction = null,
        bool $evidenceRequired = false
    ): RiskTreatment {
        return RiskTreatment::create([
            'risk_register_entry_id' => $risk->id,
            'action_description' => $description,
            'assigned_to' => $assignedTo->id,
            'due_date' => $dueDate,
            'status' => 'planned',
            'expected_score_reduction' => $expectedScoreReduction,
            'evidence_required' => $evidenceRequired,
            'created_by' => $createdBy->id,
        ]);
    }

    /**
     * Generate risk heatmap data
     */
    public function generateHeatmapData(): array
    {
        $heatmap = [];
        
        for ($likelihood = 5; $likelihood >= 1; $likelihood--) {
            $row = [];
            for ($impact = 1; $impact <= 5; $impact++) {
                $score = $this->calculateInherentScore($likelihood, $impact);
                $count = RiskRegisterEntry::where('inherent_score', $score)->count();
                
                $row[] = [
                    'score' => $score,
                    'count' => $count,
                    'color' => $this->getRiskColor($score),
                ];
            }
            $heatmap[] = $row;
        }

        return $heatmap;
    }

    /**
     * Get trend analysis for risks
     */
    public function getTrendAnalysis(int $months = 12): array
    {
        $trend = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $count = RiskRegisterEntry::whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->count();
            
            $trend[] = [
                'month' => $date->format('Y-m'),
                'new_risks' => $count,
            ];
        }

        return $trend;
    }

    /**
     * Get risk summary by category
     */
    public function getCategorySummary(): array
    {
        $categories = array_keys(self::DEFAULT_APPETITE_THRESHOLDS);
        $summary = [];

        foreach ($categories as $category) {
            $risks = RiskRegisterEntry::byCategory($category)->active()->get();
            
            $summary[$category] = [
                'total' => $risks->count(),
                'critical' => $risks->where('residual_score', '>=', 20)->count(),
                'high' => $risks->whereBetween('residual_score', [15, 19])->count(),
                'above_appetite' => $risks->where('within_appetite', false)->count(),
                'appetite_threshold' => $this->getAppetiteThreshold($category),
            ];
        }

        return $summary;
    }

    /**
     * Generate board-ready risk report
     */
    public function generateBoardReport(): array
    {
        $topRisks = RiskRegisterEntry::active()
            ->orderByDesc('residual_score')
            ->limit(10)
            ->get();

        $newThisMonth = RiskRegisterEntry::whereMonth('created_at', now()->month)->count();
        $closedThisMonth = RiskRegisterEntry::whereMonth('closed_at', now()->month)->count();

        return [
            'executive_summary' => [
                'total_active' => RiskRegisterEntry::active()->count(),
                'critical_count' => RiskRegisterEntry::critical()->count(),
                'high_count' => RiskRegisterEntry::high()->count(),
                'above_appetite' => RiskRegisterEntry::aboveAppetite()->count(),
                'new_this_month' => $newThisMonth,
                'closed_this_month' => $closedThisMonth,
            ],
            'top_10_risks' => $topRisks->map(fn($r) => [
                'reference' => $r->risk_reference,
                'title' => $r->title,
                'category' => $r->category,
                'score' => $r->residual_score,
                'owner' => $r->riskOwner?->name,
                'treatments' => $r->treatments->count(),
            ]),
            'heatmap' => $this->generateHeatmapData(),
            'by_category' => $this->getCategorySummary(),
            'expiring_acceptances' => RiskAcceptance::expiring(90)->count(),
            'overdue_treatments' => RiskTreatment::overdue()->count(),
        ];
    }
}
