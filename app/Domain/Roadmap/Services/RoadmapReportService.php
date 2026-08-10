<?php

namespace App\Domain\Roadmap\Services;

use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Models\ReportSnapshot;

class RoadmapReportService
{
    public function generate(
        string $reportType,
        ?QuarterlyRoadmapPlan $plan,
        ?int $generatedBy = null
    ): ReportSnapshot {
        $payload = match ($reportType) {
            'budget_first' => $this->budgetFirstPayload($plan),
            'board_ceo_short' => $this->boardCeoShortPayload($plan),
            'security_compliance' => $this->securityCompliancePayload($plan),
            'house_rollout' => $this->houseRolloutPayload($plan),
            'maintenance_sop' => $this->maintenanceSopPayload($plan),
            'scoring_transparency' => $this->scoringTransparencyPayload($plan),
            default => $this->bestAllRoundPayload($plan),
        };

        $checksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $name = strtoupper($reportType).' '.now()->format('Y-m-d H:i');

        return ReportSnapshot::create([
            'quarterly_plan_id' => $plan?->id,
            'report_type' => $reportType,
            'name' => $name,
            'checksum' => $checksum,
            'payload' => $payload,
            'generated_by' => $generatedBy,
            'generated_at' => now(),
            'immutable' => true,
        ]);
    }

    protected function bestAllRoundPayload(?QuarterlyRoadmapPlan $plan): array
    {
        $initiatives = $this->baseInitiatives($plan);

        return [
            'type' => 'best_all_round',
            'generated_at' => now()->toIso8601String(),
            'plan' => $this->planSummary($plan),
            'summary' => [
                'total_initiatives' => $initiatives->count(),
                'decision_required' => $initiatives->where('decision_required', true)->count(),
                'budget_total' => round((float) $initiatives->sum('budget_total'), 2),
            ],
            'initiatives' => $initiatives->values()->all(),
        ];
    }

    protected function budgetFirstPayload(?QuarterlyRoadmapPlan $plan): array
    {
        $initiatives = $this->baseInitiatives($plan)
            ->sortByDesc(function ($item) {
                $cost = max(1, (float) $item['budget_total']);

                return (float) $item['score'] / $cost;
            })
            ->values();

        return [
            'type' => 'budget_first',
            'generated_at' => now()->toIso8601String(),
            'plan' => $this->planSummary($plan),
            'prioritised' => $initiatives->all(),
            'deferral_candidates' => $initiatives->filter(fn ($item) => $item['score'] < 55)->values()->all(),
        ];
    }

    protected function boardCeoShortPayload(?QuarterlyRoadmapPlan $plan): array
    {
        $initiatives = $this->baseInitiatives($plan)
            ->sortByDesc('score')
            ->take(8)
            ->values();

        return [
            'type' => 'board_ceo_short',
            'generated_at' => now()->toIso8601String(),
            'plan' => $this->planSummary($plan),
            'decisions_required' => $initiatives->where('decision_required', true)->values()->all(),
            'top_outcomes' => $initiatives->map(fn ($item) => [
                'initiative_code' => $item['code'],
                'title' => $item['title'],
                'outcome' => $item['benefit_summary'],
                'budget_total' => $item['budget_total'],
                'score' => $item['score'],
            ])->all(),
        ];
    }

    protected function securityCompliancePayload(?QuarterlyRoadmapPlan $plan): array
    {
        $initiatives = $this->baseInitiatives($plan)
            ->filter(fn ($item) => in_array($item['stream'], ['it', 'continuous_improvement'], true))
            ->values();

        return [
            'type' => 'security_compliance',
            'generated_at' => now()->toIso8601String(),
            'plan' => $this->planSummary($plan),
            'controls' => $initiatives->map(fn ($item) => [
                'initiative_code' => $item['code'],
                'title' => $item['title'],
                'risks_linked' => $item['risk_count'],
                'assurance_items' => $item['assurance_count'],
                'decision_required' => $item['decision_required'],
            ])->all(),
        ];
    }

    protected function houseRolloutPayload(?QuarterlyRoadmapPlan $plan): array
    {
        $initiatives = Initiative::query()
            ->with(['siteScope.sites.site'])
            ->when($plan !== null, function ($query) use ($plan) {
                $query->whereIn('id', $plan->items()->pluck('initiative_id'));
            })
            ->get();

        $rows = [];
        foreach ($initiatives as $initiative) {
            foreach ($initiative->siteScope as $scope) {
                foreach ($scope->sites as $site) {
                    $rows[] = [
                        'initiative_code' => $initiative->code,
                        'initiative_title' => $initiative->title,
                        'site_id' => $site->site_id,
                        'site_name' => $site->site?->name,
                        'wave' => $site->wave_no,
                        'status' => $site->status,
                        'readiness' => $site->readiness_status,
                        'blocked_reason' => $site->blocked_reason,
                    ];
                }
            }
        }

        return [
            'type' => 'house_rollout',
            'generated_at' => now()->toIso8601String(),
            'plan' => $this->planSummary($plan),
            'rows' => $rows,
        ];
    }

    protected function maintenanceSopPayload(?QuarterlyRoadmapPlan $plan): array
    {
        $initiatives = Initiative::query()
            ->whereIn('stream', ['maintenance', 'operations'])
            ->with(['tasks'])
            ->when($plan !== null, function ($query) use ($plan) {
                $query->whereIn('id', $plan->items()->pluck('initiative_id'));
            })
            ->get();

        return [
            'type' => 'maintenance_sop',
            'generated_at' => now()->toIso8601String(),
            'plan' => $this->planSummary($plan),
            'initiatives' => $initiatives->map(fn (Initiative $initiative) => [
                'initiative_code' => $initiative->code,
                'title' => $initiative->title,
                'tasks' => $initiative->tasks->map(fn ($task) => [
                    'title' => $task->title,
                    'task_type' => $task->task_type,
                    'status' => $task->status,
                    'due_date' => $task->due_date?->toDateString(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    protected function scoringTransparencyPayload(?QuarterlyRoadmapPlan $plan): array
    {
        $initiatives = Initiative::query()
            ->when($plan !== null, fn ($q) => $q->whereIn('id', $plan->items()->pluck('initiative_id')))
            ->get();

        return [
            'type' => 'scoring_transparency',
            'generated_at' => now()->toIso8601String(),
            'plan' => $this->planSummary($plan),
            'rows' => $initiatives->map(fn (Initiative $initiative) => [
                'initiative_code' => $initiative->code,
                'title' => $initiative->title,
                'score' => (float) ($initiative->priority_score ?? 0),
                'priority_band' => $initiative->priority_band,
                'profile' => $initiative->score_profile,
                'breakdown' => $initiative->score_breakdown,
                'manual_override' => (bool) $initiative->manual_priority_override,
                'manual_reason' => $initiative->manual_priority_reason,
            ])->values()->all(),
        ];
    }

    protected function baseInitiatives(?QuarterlyRoadmapPlan $plan)
    {
        return Initiative::query()
            ->with(['budgets', 'riskLinks', 'assurancePlans'])
            ->when($plan !== null, fn ($q) => $q->whereIn('id', $plan->items()->pluck('initiative_id')))
            ->get()
            ->map(function (Initiative $initiative) {
                $latestBudget = $initiative->budgets->sortByDesc('updated_at')->first();

                return [
                    'id' => $initiative->id,
                    'code' => $initiative->code,
                    'title' => $initiative->title,
                    'stream' => $initiative->stream,
                    'status' => $initiative->status,
                    'score' => (float) ($initiative->priority_score ?? 0),
                    'priority_band' => $initiative->priority_band,
                    'budget_total' => (float) ($latestBudget?->forecast_total ?? $initiative->cost_estimate_high ?? 0),
                    'benefit_summary' => $initiative->benefit_summary,
                    'risk_count' => $initiative->riskLinks->count(),
                    'assurance_count' => $initiative->assurancePlans->count(),
                    'decision_required' => $initiative->decisionRequests()->where('status', 'pending')->exists(),
                ];
            });
    }

    protected function planSummary(?QuarterlyRoadmapPlan $plan): array
    {
        if (! $plan) {
            return [];
        }

        return [
            'id' => $plan->id,
            'fiscal_year' => $plan->fiscal_year,
            'quarter' => $plan->quarter,
            'status' => $plan->status,
            'revision_no' => $plan->revision_no,
            'published_at' => $plan->published_at?->toIso8601String(),
        ];
    }
}
