<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Services\DashboardAggregatorService;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Domain\Governance\Support\GovernancePresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
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

        $myWork = $this->myWork($user);

        return Inertia::render('Governance/Dashboard', [
            'isBoardMember' => $boardMember !== null,
            'boardRole' => $boardMember?->board_role,
            'workTotals' => $myWork['totals'] ?? null,
        ]);
    }

    /**
     * Seconds an aggregated dashboard payload may be served from cache.
     * The Refresh button sends ?fresh=1 to bypass and recompute.
     */
    private const DASHBOARD_CACHE_TTL = 300;

    /** Page size for the ranked board priorities (initial load and each further page). */
    public const PRIORITIES_PER_PAGE = 100;

    public function data(Request $request)
    {
        if ($request->query('section') === 'priorities') {
            return $this->priorities($request);
        }

        // Home always reports the current month; `period` is kept for
        // existing callers (reports, integrations) and defaults to month.
        $period = $request->validate(['period' => 'sometimes|in:today,week,month,year'])['period'] ?? 'month';
        $user = $request->user();
        $workflow = $this->workflowService->dashboardWorkflow($user, self::PRIORITIES_PER_PAGE);
        $myWork = $this->myWork($user);

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
            $widgets = $this->widgetsForViewer($result['data']['widgets'] ?? [], $user);
            $freshness = $result['freshness'] ?? [];

            return response()->json([
                'snapshot_id' => null,
                'period' => $periodData,
                'widgets' => $widgets,
                'workflow' => $workflow,
                'work_totals' => $myWork['totals'] ?? null,
                'my_work' => $myWork,
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

    /**
     * Widgets that carry record titles from a Governance register (risk or
     * requirement titles) and the permission that opens that register. The
     * roadmap summary isn't listed: its page (/roadmap/dashboard) is open to
     * everyone who can open Governance Home.
     */
    private const WIDGET_PERMISSIONS = [
        'top_risks' => 'governance.risks.view',
        'risk_changes' => 'governance.risks.view',
        'voided_risks' => 'governance.risks.view',
        'compliance_calendar' => 'governance.compliance.view',
    ];

    /**
     * Masks, on the server, the register widgets the viewer can't open, so
     * neither the raw widgets nor the Home cards built from them carry those
     * records' titles or counts.
     *
     * @param  array<string, mixed>  $widgets
     * @return array<string, mixed>
     */
    protected function widgetsForViewer(array $widgets, User $user): array
    {
        foreach (self::WIDGET_PERMISSIONS as $key => $permission) {
            if (array_key_exists($key, $widgets) && ! $user->canDo($permission)) {
                unset($widgets[$key]);
            }
        }

        return $widgets;
    }

    /** Personal obligations previewed on Home; the full list lives on My work. */
    public const MY_WORK_PREVIEW = 5;

    /**
     * The viewer's personal obligations exactly as `/governance/my-work` builds
     * them (default filters: to do, all kinds): the top items plus the full
     * authorised totals, so Home never reports the number of cards displayed.
     * Upcoming meetings arrive separately as `coming_up` and are never counted
     * as work to do. Null when the work feed could not be built — Home then
     * shows an unavailable state instead of "all caught up".
     *
     * @return array<string, mixed>|null
     */
    protected function myWork(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        try {
            $feed = $this->workflowService->workQuery()->queryFeed($user, [], self::MY_WORK_PREVIEW, 1);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return [
            'items' => $feed['items'] ?? [],
            'coming_up' => $feed['coming_up'] ?? [],
            'totals' => $feed['totals'] ?? null,
            'pagination' => $feed['pagination'] ?? null,
            'availability' => $feed['availability'] ?? [],
            'all_sources_succeeded' => $feed['all_sources_succeeded'] ?? true,
            'href' => '/governance/my-work',
        ];
    }

    /**
     * One further page of the viewer's ranked board priorities
     * (`GET /governance/dashboard/data?section=priorities&tab=…&page=…`).
     * Same audience as the dashboard payload — always the requesting user —
     * and the same tab definition as `summary.by_tab`, so every counted
     * priority is reachable.
     */
    protected function priorities(Request $request)
    {
        $validated = $request->validate([
            'tab' => 'nullable|in:'.implode(',', GovernanceWorkflowService::PRIORITY_TABS),
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.self::PRIORITIES_PER_PAGE,
        ]);

        $workflow = $this->workflowService->dashboardWorkflow(
            $request->user(),
            (int) ($validated['per_page'] ?? self::PRIORITIES_PER_PAGE),
            (int) ($validated['page'] ?? 1),
            $validated['tab'] ?? 'all',
        );

        return response()->json(['workflow' => $workflow]);
    }

    public function widget(Request $request, string $widget)
    {
        $period = $request->validate(['period' => 'required|in:today,week,month,year'])['period'];
        $range = $this->getDateRange($period);

        // Same audience as Home: no register titles for viewers who can't open the register.
        $permission = self::WIDGET_PERMISSIONS[$widget] ?? null;
        abort_if($permission !== null && ! $request->user()->canDo($permission), 403);

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
