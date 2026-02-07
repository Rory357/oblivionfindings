<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\DashboardSnapshot;
use App\Domain\Governance\Services\DashboardAggregatorService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardAggregatorService $aggregator
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        
        // Check if user is a board member
        $boardMember = $user?->boardMember;
        
        return Inertia::render('Governance/Dashboard', [
            'periods' => [
                ['value' => 'today', 'label' => 'Today'],
                ['value' => 'week', 'label' => 'This Week'],
                ['value' => 'month', 'label' => 'This Month'],
                ['value' => 'year', 'label' => 'This Year'],
            ],
            'isBoardMember' => $boardMember !== null,
            'boardRole' => $boardMember?->board_role,
        ]);
    }

    public function data(Request $request)
    {
        $period = $request->validate(['period' => 'required|in:today,week,month,year'])['period'];

        try {
            // Use the aggregator service to get real data
            $snapshot = $this->aggregator->captureSnapshot($period);

            return response()->json([
                'snapshot_id' => $snapshot->id,
                'period' => $snapshot->snapshot_data['period'] ?? [
                    'type' => $period,
                    'start' => now()->startOfMonth()->toDateString(),
                    'end' => now()->toDateString(),
                ],
                'widgets' => $snapshot->snapshot_data['widgets'] ?? [],
                'freshness' => ['status' => 'fresh', 'last_updated' => now()->toIso8601String()],
                'captured_at' => $snapshot->captured_at?->toIso8601String() ?? now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            // Fallback to empty data if aggregation fails
            report($e);

            return response()->json([
                'snapshot_id' => null,
                'period' => [
                    'type' => $period,
                    'start' => now()->startOfMonth()->toDateString(),
                    'end' => now()->toDateString(),
                ],
                'widgets' => [
                    'top_risks' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'above_appetite' => 0, 'items' => []],
                    'risk_changes' => ['new' => 0, 'escalated' => 0, 'closed' => 0, 'net_change' => 0],
                    'client_safety' => ['high_risk_clients' => 0, 'serious_incidents_period' => 0, 'open_critical_incidents' => 0, 'status' => 'good'],
                    'operational_safety' => ['near_misses' => 0, 'injuries' => 0, 'status' => 'good'],
                    'privacy_data' => ['breaches_90d' => 0, 'open_breaches' => 0, 'open_dpias' => 0, 'dsr_backlog' => 0, 'status' => 'good'],
                    'workforce' => ['overtime_percentage' => 0, 'unfilled_shifts' => 0, 'training_compliance' => 0, 'status' => 'good'],
                    'financial' => ['budget_utilization' => 0, 'variance' => 0, 'status' => 'unknown'],
                    'it_cyber' => ['security_incidents' => 0, 'uptime_percentage' => 99.5, 'critical_open_alerts' => 0, 'status' => 'good'],
                    'compliance_calendar' => [],
                    'decisions_required' => ['count' => 0, 'overdue' => 0, 'items' => []],
                    'control_room' => ['critical_alerts' => 0, 'high_alerts' => 0, 'mtta_minutes' => 0, 'mttr_minutes' => 0, 'open_critical' => 0],
                    'incidents' => ['total_period' => 0, 'by_severity' => [], 'open_count' => 0, 'avg_close_hours' => 0],
                    'safeguarding' => ['new_concerns' => 0, 'critical_concerns' => 0, 'open_concerns' => 0, 'investigations_opened' => 0, 'status' => 'good'],
                ],
                'freshness' => ['status' => 'stale', 'last_updated' => now()->toIso8601String()],
                'captured_at' => now()->toIso8601String(),
            ]);
        }
    }

    public function widget(Request $request, string $widget)
    {
        $period = $request->validate(['period' => 'required|in:today,week,month,year'])['period'];
        $range = $this->getDateRange($period);
        
        $data = match($widget) {
            'top_risks' => $this->aggregator->getTopRisks(),
            'client_safety' => $this->aggregator->getClientSafetyMetrics($range),
            'workforce' => $this->aggregator->getWorkforceMetrics($range),
            'compliance_calendar' => $this->aggregator->getComplianceCalendar(),
            'decisions_required' => $this->aggregator->getDecisionsRequired(),
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
        $start = match($period) {
            'today' => $end->copy()->startOfDay(),
            'week' => $end->copy()->startOfWeek(),
            'month' => $end->copy()->startOfMonth(),
            'year' => $end->copy()->startOfYear(),
            default => $end->copy()->subMonth(),
        };

        return ['start' => $start, 'end' => $end];
    }
}
