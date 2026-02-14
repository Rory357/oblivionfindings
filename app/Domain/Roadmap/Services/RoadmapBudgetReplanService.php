<?php

namespace App\Domain\Roadmap\Services;

use App\Domain\Roadmap\Models\Initiative;

class RoadmapBudgetReplanService
{
    public function replanForBudgetCut(float $newEnvelope, ?int $tenantId = null): array
    {
        $initiatives = Initiative::query()
            ->forTenant($tenantId)
            ->whereIn('status', [
                Initiative::STATUS_APPROVED,
                Initiative::STATUS_PROPOSED,
                Initiative::STATUS_IN_PROGRESS,
            ])
            ->orderByDesc('priority_score')
            ->get();

        $kept = [];
        $deferred = [];
        $runningTotal = 0.0;

        foreach ($initiatives as $initiative) {
            $cost = (float) ($initiative->totalBudgetUpper() ?: $initiative->cost_estimate_high ?: $initiative->cost_estimate_low ?: 0);

            $isProtected = $this->isProtectedFromDeferral($initiative);
            if ($isProtected || ($runningTotal + $cost) <= $newEnvelope) {
                $kept[] = [
                    'initiative_id' => $initiative->id,
                    'code' => $initiative->code,
                    'title' => $initiative->title,
                    'cost' => $cost,
                    'score' => (float) ($initiative->priority_score ?? 0),
                    'protected' => $isProtected,
                ];
                $runningTotal += $cost;

                continue;
            }

            $deferred[] = [
                'initiative_id' => $initiative->id,
                'code' => $initiative->code,
                'title' => $initiative->title,
                'cost' => $cost,
                'score' => (float) ($initiative->priority_score ?? 0),
                'risk_impact' => $initiative->risk_summary,
                'impact_statement' => $initiative->benefit_summary,
            ];
        }

        return [
            'new_envelope' => $newEnvelope,
            'kept_total' => round($runningTotal, 2),
            'kept' => $kept,
            'deferred' => $deferred,
            'required_decisions' => array_values(array_filter($deferred, fn ($item) => $item['score'] >= 70)),
        ];
    }

    protected function isProtectedFromDeferral(Initiative $initiative): bool
    {
        $impact = $initiative->impact_profile ?? [];

        return ((int) ($impact['safety'] ?? 0) >= 4)
            || ((int) ($impact['compliance'] ?? 0) >= 4)
            || $initiative->riskLinks()->whereHas('risk', fn ($q) => $q->where('within_appetite', false))->exists();
    }
}
