<?php

namespace App\Domain\Roadmap\Services;

use App\Domain\Governance\Models\Budget;
use App\Domain\Roadmap\Models\DecisionRequest;
use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoadmapDashboardService
{
    public function governanceWidget(): array
    {
        if (! $this->schemaReady()) {
            return $this->emptySummary('roadmap module not migrated');
        }

        $latestPlan = QuarterlyRoadmapPlan::query()
            ->where('status', QuarterlyRoadmapPlan::STATUS_PUBLISHED)
            ->orderByDesc('fiscal_year')
            ->orderByDesc('quarter')
            ->orderByDesc('revision_no')
            ->first();

        $initiativeQuery = Initiative::query();

        if ($latestPlan) {
            $initiativeIds = $latestPlan->items()->pluck('initiative_id');
            $initiativeQuery->whereIn('id', $initiativeIds);
        }

        $initiatives = $initiativeQuery->with(['budgets', 'riskLinks', 'assurancePlans'])->get();

        $totalBudget = $initiatives->sum(function (Initiative $initiative) {
            $latest = $initiative->budgets->sortByDesc('updated_at')->first();

            return (float) ($latest?->forecast_total ?? $initiative->cost_estimate_high ?? 0);
        });

        $siteProgress = DB::table('roadmap_initiative_site_scope_sites')
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $pendingDecisions = DecisionRequest::query()
            ->where('status', 'pending')
            ->count();

        $governanceBudget = $this->getGovernanceBudget();

        return [
            'published_plan' => $latestPlan ? [
                'id' => $latestPlan->id,
                'fiscal_year' => $latestPlan->fiscal_year,
                'quarter' => $latestPlan->quarter,
                'revision_no' => $latestPlan->revision_no,
                'published_at' => $latestPlan->published_at?->toDateString(),
            ] : null,
            'initiatives' => [
                'total' => $initiatives->count(),
                'in_progress' => $initiatives->where('status', Initiative::STATUS_IN_PROGRESS)->count(),
                'blocked' => $initiatives->where('status', Initiative::STATUS_BLOCKED)->count(),
                'deferred' => $initiatives->where('status', Initiative::STATUS_DEFERRED)->count(),
                'completed' => $initiatives->where('status', Initiative::STATUS_COMPLETED)->count(),
                'top' => $initiatives
                    ->sortByDesc('priority_score')
                    ->take(5)
                    ->map(fn (Initiative $initiative) => [
                        'id' => $initiative->id,
                        'code' => $initiative->code,
                        'title' => $initiative->title,
                        'score' => (float) ($initiative->priority_score ?? 0),
                        'status' => $initiative->status,
                        'owner' => $initiative->owner?->name,
                    ])
                    ->values()
                    ->all(),
            ],
            'budget' => [
                'forecast_total' => round((float) $totalBudget, 2),
            ],
            'governance_budget' => $governanceBudget,
            'assurance' => [
                'overdue' => $initiatives->sum(function (Initiative $initiative) {
                    return $initiative->assurancePlans
                        ->whereNull('verified_at')
                        ->where('verify_due_date', '<', now()->toDateString())
                        ->count();
                }),
                'verified' => $initiatives->sum(fn (Initiative $initiative) => $initiative->assurancePlans->whereNotNull('verified_at')->count()),
            ],
            'decisions_required' => $pendingDecisions,
            'house_rollout' => [
                'not_started' => (int) ($siteProgress['not_started'] ?? 0),
                'in_progress' => (int) ($siteProgress['in_progress'] ?? 0),
                'blocked' => (int) ($siteProgress['blocked'] ?? 0),
                'completed' => (int) ($siteProgress['completed'] ?? 0),
            ],
        ];
    }

    public function decisionsRequired(int $limit = 10): array
    {
        if (! Schema::hasTable('roadmap_decision_requests')) {
            return [
                'count' => 0,
                'overdue' => 0,
                'items' => [],
            ];
        }

        $requests = DecisionRequest::query()
            ->where('status', 'pending')
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        return [
            'count' => $requests->count(),
            'overdue' => $requests->where('due_date', '<', now()->toDateString())->count(),
            'items' => $requests->map(fn (DecisionRequest $request) => [
                'id' => $request->id,
                'source_type' => $request->source_type,
                'source_id' => $request->source_id,
                'request_type' => $request->request_type,
                'required_role' => $request->required_role,
                'amount' => (float) ($request->amount ?? 0),
                'risk_level' => $request->risk_level,
                'due_date' => $request->due_date?->toDateString(),
            ])->values()->all(),
        ];
    }

    protected function getGovernanceBudget(): ?array
    {
        if (! Schema::hasTable('budgets')) {
            return null;
        }

        $budget = Budget::approved()
            ->latest('approved_by_board_at')
            ->with('approvalResolution:id,resolution_reference,title')
            ->first();

        if (! $budget) {
            return null;
        }

        $budget->loadMissing('lineItems');

        return [
            'id' => $budget->id,
            'fiscal_year' => $budget->fiscal_year,
            'title' => $budget->title,
            'total_budget' => round((float) $budget->total_budget, 2),
            'total_allocated' => round($budget->getTotalAllocated(), 2),
            'total_actual' => round($budget->getTotalActual(), 2),
            'variance_pct' => round($budget->getVariancePercentage(), 1),
            'remaining' => round($budget->getRemainingBudget(), 2),
            'approved_at' => $budget->approved_by_board_at?->toDateString(),
            'resolution' => $budget->approvalResolution ? [
                'id' => $budget->approvalResolution->id,
                'reference' => $budget->approvalResolution->resolution_reference,
                'title' => $budget->approvalResolution->title,
            ] : null,
        ];
    }

    protected function schemaReady(): bool
    {
        return Schema::hasTable('roadmap_quarterly_plans')
            && Schema::hasTable('roadmap_initiatives')
            && Schema::hasTable('roadmap_initiative_site_scope_sites')
            && Schema::hasTable('roadmap_decision_requests');
    }

    protected function emptySummary(string $reason): array
    {
        return [
            'published_plan' => null,
            'initiatives' => [
                'total' => 0,
                'in_progress' => 0,
                'blocked' => 0,
                'deferred' => 0,
                'completed' => 0,
                'top' => [],
            ],
            'budget' => [
                'forecast_total' => 0.0,
            ],
            'governance_budget' => $this->getGovernanceBudget(),
            'assurance' => [
                'overdue' => 0,
                'verified' => 0,
            ],
            'decisions_required' => 0,
            'house_rollout' => [
                'not_started' => 0,
                'in_progress' => 0,
                'blocked' => 0,
                'completed' => 0,
            ],
            'status' => 'unavailable',
            'reason' => $reason,
        ];
    }
}
