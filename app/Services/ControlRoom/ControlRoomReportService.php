<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reporting and metrics service for Control Room operational intelligence.
 *
 * Provides aggregated metrics that answer:
 * - Are we responding fast enough? (SLA compliance)
 * - What is happening most? (volume analysis)
 * - Where are we failing? (breach analysis)
 * - Who is overloaded? (workload distribution)
 * - Are playbooks working? (completion rates)
 */
class ControlRoomReportService
{
    protected string $dbDriver;

    public function __construct()
    {
        $this->dbDriver = DB::connection()->getDriverName();
    }

    /**
     * SLA compliance metrics.
     */
    public function slaCompliance(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $alertQuery = $this->baseAlertQuery($from, $to, $siteId);

        $totalWithSla = (clone $alertQuery)->whereHas('sla')->count();
        $breached = (clone $alertQuery)->whereHas('sla', fn ($q) => $q->breached())->count();
        $met = $totalWithSla - $breached;

        // Breakdown by breach type
        $ackBreached = (clone $alertQuery)
            ->whereHas('sla', fn ($q) => $q->where('acknowledge_breached', true))
            ->count();
        $responseBreached = (clone $alertQuery)
            ->whereHas('sla', fn ($q) => $q->where('response_breached', true))
            ->count();
        $resolutionBreached = (clone $alertQuery)
            ->whereHas('sla', fn ($q) => $q->where('resolution_breached', true))
            ->count();

        // Average times
        $avgAckMinutes = $this->avgTimeDiff($from, $to, $siteId, 'triggered_at', 'acknowledged_at', 'minute');
        $avgResponseMinutes = $this->avgTimeDiff($from, $to, $siteId, 'acknowledged_at', 'resolved_at', 'minute');
        $avgResolutionHours = $this->avgTimeDiff($from, $to, $siteId, 'triggered_at', 'resolved_at', 'hour');

        // By severity
        $bySeverity = [];
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $sevQuery = (clone $alertQuery)->where('severity', $severity);
            $sevTotal = (clone $sevQuery)->whereHas('sla')->count();
            $sevBreached = (clone $sevQuery)->whereHas('sla', fn ($q) => $q->breached())->count();

            $bySeverity[$severity] = [
                'total' => $sevTotal,
                'met' => $sevTotal - $sevBreached,
                'breached' => $sevBreached,
                'compliance_pct' => $sevTotal > 0 ? round((($sevTotal - $sevBreached) / $sevTotal) * 100, 1) : 100,
            ];
        }

        return [
            'total_with_sla' => $totalWithSla,
            'sla_met' => $met,
            'sla_breached' => $breached,
            'compliance_pct' => $totalWithSla > 0 ? round(($met / $totalWithSla) * 100, 1) : 100,
            'breach_breakdown' => [
                'acknowledge' => $ackBreached,
                'response' => $responseBreached,
                'resolution' => $resolutionBreached,
            ],
            'avg_acknowledge_minutes' => round((float) $avgAckMinutes, 1),
            'avg_response_minutes' => round((float) $avgResponseMinutes, 1),
            'avg_resolution_hours' => round((float) $avgResolutionHours, 1),
            'by_severity' => $bySeverity,
        ];
    }

    /**
     * Alert volume and distribution metrics.
     */
    public function alertVolume(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $query = $this->baseAlertQuery($from, $to, $siteId);

        $total = (clone $query)->count();
        $resolved = (clone $query)->whereIn('status', ['resolved', 'closed'])->count();
        $open = (clone $query)->whereNotIn('status', ['resolved', 'closed'])->count();

        return [
            'total' => $total,
            'resolved' => $resolved,
            'open' => $open,
            'resolution_rate' => $total > 0 ? round(($resolved / $total) * 100, 1) : 0,

            'by_severity' => (clone $query)
                ->select('severity', DB::raw('COUNT(*) as count'))
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),

            'by_source' => (clone $query)
                ->select('source', DB::raw('COUNT(*) as count'))
                ->groupBy('source')
                ->orderByDesc('count')
                ->limit(15)
                ->pluck('count', 'source')
                ->toArray(),

            'top_alert_types' => (clone $query)
                ->select('alert_type', DB::raw('COUNT(*) as count'))
                ->groupBy('alert_type')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'alert_type')
                ->toArray(),

            'daily_trend' => (clone $query)
                ->select(DB::raw('DATE(triggered_at) as date'), DB::raw('COUNT(*) as count'))
                ->groupBy(DB::raw('DATE(triggered_at)'))
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => ['date' => $row->date, 'count' => $row->count])
                ->values()
                ->toArray(),
        ];
    }

    /**
     * Escalation analysis metrics.
     */
    public function escalationAnalysis(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $query = $this->baseAlertQuery($from, $to, $siteId);

        $total = (clone $query)->count();
        $escalated = (clone $query)->where('escalation_level', '>', 0)->count();

        // Distribution by escalation level
        $byLevel = (clone $query)
            ->where('escalation_level', '>', 0)
            ->select('escalation_level', DB::raw('COUNT(*) as count'))
            ->groupBy('escalation_level')
            ->orderBy('escalation_level')
            ->pluck('count', 'escalation_level')
            ->toArray();

        // Currently stuck at high escalation (level 3+, still open)
        $stuckAtHighEscalation = (clone $query)
            ->where('escalation_level', '>=', 3)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        return [
            'total_alerts' => $total,
            'escalated' => $escalated,
            'escalation_rate' => $total > 0 ? round(($escalated / $total) * 100, 1) : 0,
            'by_level' => $byLevel,
            'stuck_at_high_escalation' => $stuckAtHighEscalation,
        ];
    }

    /**
     * Workload distribution metrics.
     */
    public function workloadDistribution(Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        // Active alerts per user (currently unresolved)
        $activePerUser = ControlRoomAlert::query()
            ->whereNotIn('status', ['resolved', 'closed'])
            ->whereNotNull('assigned_to_user_id')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->select('assigned_to_user_id', DB::raw('COUNT(*) as active_count'))
            ->groupBy('assigned_to_user_id')
            ->with('assignedTo:id,name')
            ->orderByDesc('active_count')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'user' => $row->assignedTo?->name ?? 'Unknown',
                'user_id' => $row->assigned_to_user_id,
                'active_alerts' => $row->active_count,
            ])
            ->values()
            ->toArray();

        // Alerts handled per user in period
        $handledPerUser = $this->baseAlertQuery($from, $to, $siteId)
            ->whereNotNull('assigned_to_user_id')
            ->select('assigned_to_user_id', DB::raw('COUNT(*) as total_count'))
            ->groupBy('assigned_to_user_id')
            ->with('assignedTo:id,name')
            ->orderByDesc('total_count')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'user' => $row->assignedTo?->name ?? 'Unknown',
                'user_id' => $row->assigned_to_user_id,
                'alerts_handled' => $row->total_count,
            ])
            ->values()
            ->toArray();

        // Alerts per queue (currently active)
        $perQueue = TriageQueue::active()
            ->withCount(['alerts as active_count' => fn ($q) =>
                $q->whereNotIn('status', ['resolved', 'closed'])
                    ->when($siteId, fn ($qq) => $qq->where('site_id', $siteId))
            ])
            ->orderBy('tier')
            ->get()
            ->map(fn ($q) => [
                'queue' => $q->name,
                'tier' => $q->tier,
                'active_alerts' => $q->active_count,
            ])
            ->toArray();

        // Unassigned alerts
        $unassigned = ControlRoomAlert::query()
            ->whereNotIn('status', ['resolved', 'closed'])
            ->whereNull('assigned_to_user_id')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->count();

        return [
            'active_per_user' => $activePerUser,
            'handled_per_user' => $handledPerUser,
            'per_queue' => $perQueue,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Playbook performance metrics.
     */
    public function playbookPerformance(Carbon $from, Carbon $to): array
    {
        $runs = PlaybookRun::where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->with('playbook:id,name');

        $total = (clone $runs)->count();
        $completed = (clone $runs)->where('status', 'completed')->count();
        $inProgress = (clone $runs)->where('status', 'in_progress')->count();
        $cancelled = (clone $runs)->where('status', 'cancelled')->count();

        // Average completion time (hours)
        $avgCompletionExpr = $this->dbDriver === 'sqlite'
            ? "AVG((strftime('%s', completed_at) - strftime('%s', started_at)) / 3600.0)"
            : 'AVG(TIMESTAMPDIFF(HOUR, started_at, completed_at))';

        $avgCompletionHours = (clone $runs)
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->selectRaw("{$avgCompletionExpr} as avg_hours")
            ->value('avg_hours');

        // By playbook name
        $byPlaybook = (clone $runs)
            ->select('playbook_id', DB::raw('COUNT(*) as total'), DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"))
            ->groupBy('playbook_id')
            ->with('playbook:id,name')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'playbook' => $row->playbook?->name ?? 'Unknown',
                'total_runs' => $row->total,
                'completed' => $row->completed,
                'completion_rate' => $row->total > 0 ? round(($row->completed / $row->total) * 100, 1) : 0,
            ])
            ->values()
            ->toArray();

        return [
            'total_runs' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'cancelled' => $cancelled,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'avg_completion_hours' => round((float) $avgCompletionHours, 1),
            'by_playbook' => $byPlaybook,
        ];
    }

    /**
     * Site-level comparison metrics.
     */
    public function siteComparison(Carbon $from, Carbon $to): array
    {
        return ControlRoomAlert::query()
            ->where('triggered_at', '>=', $from)
            ->where('triggered_at', '<=', $to)
            ->whereNotNull('site_id')
            ->join('sites', 'control_room_alerts.site_id', '=', 'sites.id')
            ->select(
                'sites.id as site_id',
                'sites.name as site_name',
                DB::raw('COUNT(*) as total_alerts'),
                DB::raw("SUM(CASE WHEN control_room_alerts.severity = 'critical' THEN 1 ELSE 0 END) as critical_count"),
                DB::raw("SUM(CASE WHEN control_room_alerts.escalation_level > 0 THEN 1 ELSE 0 END) as escalated_count"),
                DB::raw("SUM(CASE WHEN control_room_alerts.status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as resolved_count"),
            )
            ->groupBy('sites.id', 'sites.name')
            ->orderByDesc('total_alerts')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'site_id' => $row->site_id,
                'site_name' => $row->site_name,
                'total_alerts' => (int) $row->total_alerts,
                'critical_count' => (int) $row->critical_count,
                'escalated_count' => (int) $row->escalated_count,
                'resolution_rate' => $row->total_alerts > 0
                    ? round(($row->resolved_count / $row->total_alerts) * 100, 1)
                    : 0,
            ])
            ->toArray();
    }

    /**
     * Generate "what needs attention" flags from current operational state.
     *
     * Returns actionable flags for operators/managers — simple boolean/threshold
     * checks, not complex analytics.
     */
    public function attentionFlags(?int $siteId = null): array
    {
        $baseQuery = ControlRoomAlert::query()
            ->whereNotIn('status', ['resolved', 'closed'])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId));

        $unassigned = (clone $baseQuery)->whereNull('assigned_to_user_id')->count();
        $critical = (clone $baseQuery)->where('severity', 'critical')->count();
        $highEscalation = (clone $baseQuery)->where('escalation_level', '>=', 3)->count();
        $staleOpen = (clone $baseQuery)->where('status', 'open')
            ->where('triggered_at', '<', now()->subHours(4))
            ->count();

        // SLA compliance check (last 7 days)
        $recentSlaTotal = AlertSla::where('created_at', '>=', now()->subDays(7))->count();
        $recentSlaBreached = AlertSla::where('created_at', '>=', now()->subDays(7))
            ->breached()->count();
        $slaCompliancePct = $recentSlaTotal > 0
            ? round((($recentSlaTotal - $recentSlaBreached) / $recentSlaTotal) * 100, 1)
            : 100;

        $flags = [];

        if ($critical > 0) {
            $flags[] = [
                'level' => 'critical',
                'message' => "{$critical} critical alert(s) require immediate attention",
                'metric' => 'critical_alerts',
                'value' => $critical,
            ];
        }

        if ($unassigned >= 5) {
            $flags[] = [
                'level' => 'warning',
                'message' => "{$unassigned} alerts are unassigned — triage needed",
                'metric' => 'unassigned',
                'value' => $unassigned,
            ];
        }

        if ($highEscalation > 0) {
            $flags[] = [
                'level' => 'warning',
                'message' => "{$highEscalation} alert(s) at escalation level 3+ — management review needed",
                'metric' => 'high_escalation',
                'value' => $highEscalation,
            ];
        }

        if ($staleOpen > 0) {
            $flags[] = [
                'level' => 'warning',
                'message' => "{$staleOpen} alert(s) open for 4+ hours without acknowledgement",
                'metric' => 'stale_open',
                'value' => $staleOpen,
            ];
        }

        if ($slaCompliancePct < 90) {
            $flags[] = [
                'level' => $slaCompliancePct < 75 ? 'critical' : 'warning',
                'message' => "SLA compliance at {$slaCompliancePct}% (7-day average) — below 90% threshold",
                'metric' => 'sla_compliance',
                'value' => $slaCompliancePct,
            ];
        }

        return $flags;
    }

    /**
     * Build a base alert query scoped to date range and optional site.
     */
    protected function baseAlertQuery(Carbon $from, Carbon $to, ?int $siteId = null)
    {
        return ControlRoomAlert::query()
            ->where('triggered_at', '>=', $from)
            ->where('triggered_at', '<=', $to)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId));
    }

    /**
     * Calculate average time difference between two datetime columns.
     */
    protected function avgTimeDiff(
        Carbon $from,
        Carbon $to,
        ?int $siteId,
        string $startCol,
        string $endCol,
        string $unit = 'minute',
    ): ?float {
        $expr = match ($this->dbDriver) {
            'sqlite' => match ($unit) {
                'hour' => "AVG((strftime('%s', {$endCol}) - strftime('%s', {$startCol})) / 3600.0)",
                default => "AVG((strftime('%s', {$endCol}) - strftime('%s', {$startCol})) / 60.0)",
            },
            default => match ($unit) {
                'hour' => "AVG(TIMESTAMPDIFF(HOUR, {$startCol}, {$endCol}))",
                default => "AVG(TIMESTAMPDIFF(MINUTE, {$startCol}, {$endCol}))",
            },
        };

        return $this->baseAlertQuery($from, $to, $siteId)
            ->whereNotNull($startCol)
            ->whereNotNull($endCol)
            ->selectRaw("{$expr} as avg_val")
            ->value('avg_val');
    }
}
