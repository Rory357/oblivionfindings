<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Services\DashboardAggregatorService;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Domain\Governance\Support\GovernancePresenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardAggregatorService $aggregator,
        protected GovernanceWorkflowService $workflowService,
        protected GovernancePresenter $presenter,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // Check if user is a board member
        $boardMember = $user?->boardMember;

        $workTotals = null;
        try {
            if ($user) {
                $workFeed = $this->workflowService->workQuery()->queryFeed($user);
                $workTotals = $workFeed['totals'] ?? null;
            }
        } catch (\Throwable) {
            // Non-blocking fallback
        }

        return Inertia::render('Governance/Dashboard', [
            'periods' => [
                ['value' => 'today', 'label' => 'Today'],
                ['value' => 'week', 'label' => 'This Week'],
                ['value' => 'month', 'label' => 'This Month'],
                ['value' => 'year', 'label' => 'This Year'],
            ],
            'isBoardMember' => $boardMember !== null,
            'boardRole' => $boardMember?->board_role,
            'workTotals' => $workTotals,
        ]);
    }

    /**
     * Seconds an aggregated dashboard payload may be served from cache.
     * The Refresh button sends ?fresh=1 to bypass and recompute.
     */
    private const DASHBOARD_CACHE_TTL = 300;

    public function data(Request $request)
    {
        $period = $request->validate(['period' => 'required|in:today,week,month,year'])['period'];
        $user = $request->user();
        $workflow = $this->workflowService->dashboardWorkflow($user);
        $workTotals = null;
        try {
            if ($user) {
                $workFeed = $this->workflowService->workQuery()->queryFeed($user);
                $workTotals = $workFeed['totals'] ?? null;
            }
        } catch (\Throwable) {
            // Non-blocking fallback
        }

        try {
            // Widget visibility is permission-scoped, so the cache is per
            // user. Board digests / board packs persist their own
            // DashboardSnapshots; this read path no longer writes one, and
            // budget actuals sync hourly via SyncBudgetActualsJob — an
            // explicit Refresh (?fresh=1) re-syncs and recomputes now.
            $cacheKey = "governance:dashboard:{$period}:{$user->id}";

            if ($request->boolean('fresh')) {
                Cache::forget($cacheKey);
            }

            $result = Cache::remember(
                $cacheKey,
                self::DASHBOARD_CACHE_TTL,
                fn () => $this->aggregator->aggregate($period, viewer: $user),
            );

            $periodData = $result['data']['period'] ?? [
                'type' => $period,
                'start' => now()->startOfMonth()->toDateString(),
                'end' => now()->toDateString(),
            ];
            $widgets = $result['data']['widgets'] ?? [];
            $freshness = $result['freshness'] ?? [];

            return response()->json([
                'snapshot_id' => null,
                'period' => $periodData,
                'widgets' => $widgets,
                'workflow' => $workflow,
                'work_totals' => $workTotals,
                'freshness' => $freshness,
                'cockpit' => $this->presenter->dashboard($widgets, $periodData, $freshness, $workflow, $user),
                'captured_at' => $result['data']['captured_at'] ?? now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Board information could not be loaded.',
            ], 500);
        }
    }

    public function widget(Request $request, string $widget)
    {
        $period = $request->validate(['period' => 'required|in:today,week,month,year'])['period'];
        $range = $this->getDateRange($period);

        $data = match ($widget) {
            'top_risks' => $this->aggregator->getTopRisks(),
            'client_safety' => $this->aggregator->getClientSafetyMetrics($range),
            'workforce' => $this->aggregator->getWorkforceMetrics($range),
            'compliance_calendar' => $this->aggregator->getComplianceCalendar(),
            'decisions_required' => $this->aggregator->getDecisionsRequired(),
            'roadmap' => $this->aggregator->getRoadmapMetrics(),
            default => [],
        };

        return response()->json([
            'widget' => $widget,
            'data' => $data,
        ]);
    }

    protected function getDateRange(string $period): array
    {
        $end = now();
        $start = match ($period) {
            'today' => $end->copy()->startOfDay(),
            'week' => $end->copy()->startOfWeek(),
            'month' => $end->copy()->startOfMonth(),
            'year' => $end->copy()->startOfYear(),
            default => $end->copy()->subMonth(),
        };

        return ['start' => $start, 'end' => $end];
    }
}
