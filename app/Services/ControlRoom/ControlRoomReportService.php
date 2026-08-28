<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly ControlRoomAlertAccessService $alertAccess,
    ) {
        $this->dbDriver = DB::connection()->getDriverName();
    }

    /**
     * SLA compliance metrics.
     */
    public function slaCompliance(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId = null,
        ?User $viewer = null,
    ): array {
        $cycles = $this->slaCyclesInPeriod($from, $to, $siteId, $viewer);
        $totalWithSla = $cycles->count();
        $assessed = $cycles->where('assessed', true)->count();
        $breached = $cycles->where('breached', true)->count();
        $met = $cycles->where('met', true)->count();
        $inProgress = $cycles->where('assessed', false)->count();

        // By severity
        $bySeverity = [];
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $severityCycles = $cycles->where('severity', $severity);
            $sevTotal = $severityCycles->count();
            $sevAssessed = $severityCycles->where('assessed', true)->count();
            $sevBreached = $severityCycles->where('breached', true)->count();
            $sevMet = $severityCycles->where('met', true)->count();

            $bySeverity[$severity] = [
                'total' => $sevTotal,
                'assessed_total' => $sevAssessed,
                'met' => $sevMet,
                'breached' => $sevBreached,
                'in_progress' => max(0, $sevTotal - $sevAssessed),
                'compliance_pct' => $sevAssessed > 0 ? round(($sevMet / $sevAssessed) * 100, 1) : null,
            ];
        }

        return [
            'total_with_sla' => $totalWithSla,
            'total_sla_cycles' => $totalWithSla,
            'unique_alerts_with_sla' => $cycles->pluck('alert_id')->unique()->count(),
            'assessed_total' => $assessed,
            'sla_met' => $met,
            'sla_breached' => $breached,
            'sla_in_progress' => $inProgress,
            'compliance_pct' => $assessed > 0 ? round(($met / $assessed) * 100, 1) : null,
            'breach_breakdown' => [
                'acknowledge' => $cycles->where('acknowledge_breached', true)->count(),
                'response' => $cycles->where('response_breached', true)->count(),
                'resolution' => $cycles->where('resolution_breached', true)->count(),
            ],
            'avg_acknowledge_minutes' => $this->averageCycleDuration($cycles, 'acknowledged_at', 60),
            'avg_response_minutes' => $this->averageCycleDuration($cycles, 'responded_at', 60),
            'avg_resolution_hours' => $this->averageCycleDuration($cycles, 'resolved_at', 3600),
            'by_severity' => $bySeverity,
        ];
    }

    /**
     * Daily SLA compliance grouped by the start date of every reportable cycle.
     *
     * @return array<int, array{date: string, compliance_pct: int|null}>
     */
    public function slaDailyTrend(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId = null,
        ?User $viewer = null,
    ): array {
        return $this->slaCyclesInPeriod($from, $to, $siteId, $viewer)
            ->groupBy(fn (array $cycle): string => $cycle['started_at']->toDateString())
            ->sortKeys()
            ->map(function (Collection $cycles, string $date): array {
                $assessed = $cycles->where('assessed', true)->count();
                $met = $cycles->where('met', true)->count();

                return [
                    'date' => $date,
                    'compliance_pct' => $assessed > 0
                        ? (int) round(($met / $assessed) * 100)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Alert volume and distribution metrics.
     */
    public function alertVolume(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId = null,
        ?User $viewer = null,
    ): array {
        $query = $this->baseAlertQuery($from, $to, $siteId, $viewer);

        $total = (clone $query)->count();
        $resolved = (clone $query)->whereIn('status', ['resolved', 'closed'])->count();
        $open = (clone $query)->actionable()->count();

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
    public function escalationAnalysis(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId = null,
        ?User $viewer = null,
    ): array {
        $query = $this->baseAlertQuery($from, $to, $siteId, $viewer);

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
            ->actionable()
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
    public function workloadDistribution(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId = null,
        ?User $viewer = null,
    ): array {
        // Active alerts per user (currently unresolved)
        $activePerUser = ControlRoomAlert::query()
            ->actionable()
            ->whereNotNull('assigned_to_user_id')
            ->tap(fn ($query) => $this->applySiteConstraint($query, $siteId))
            ->tap(fn (Builder $query) => $this->applyViewerContentConstraint($query, $viewer))
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
        $handledPerUser = $this->baseAlertQuery($from, $to, $siteId, $viewer)
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
            ->withCount(['alerts as active_count' => fn ($q) => $q->actionable()
                ->tap(fn ($alertQuery) => $this->applySiteConstraint($alertQuery, $siteId))
                ->tap(fn (Builder $alertQuery) => $this->applyViewerContentConstraint($alertQuery, $viewer)),
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
            ->actionable()
            ->whereNull('assigned_to_user_id')
            ->tap(fn ($query) => $this->applySiteConstraint($query, $siteId))
            ->tap(fn (Builder $query) => $this->applyViewerContentConstraint($query, $viewer))
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
    public function playbookPerformance(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId = null,
        ?User $viewer = null,
    ): array {
        $runs = PlaybookRun::where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->when($siteId !== null || $viewer !== null, fn ($query) => $query->whereHas(
                'alert',
                function (Builder $alertQuery) use ($siteId, $viewer): void {
                    $this->applySiteConstraint($alertQuery, $siteId);
                    $this->applyViewerContentConstraint($alertQuery, $viewer);
                },
            ))
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
    public function siteComparison(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId = null,
        ?User $viewer = null,
    ): array {
        $query = ControlRoomAlert::query()
            ->where('triggered_at', '>=', $from)
            ->where('triggered_at', '<=', $to);
        $this->applySiteConstraint($query, $siteId);
        $this->applyViewerContentConstraint($query, $viewer);
        $effectiveSiteExpression = $this->siteAccess->alertEffectiveSiteExpression($query);

        return $query
            ->join('sites', fn ($join) => $join->on(
                'sites.id',
                '=',
                DB::raw($effectiveSiteExpression),
            ))
            ->select(
                'sites.id as site_id',
                'sites.name as site_name',
                DB::raw('COUNT(*) as total_alerts'),
                DB::raw("SUM(CASE WHEN control_room_alerts.severity = 'critical' THEN 1 ELSE 0 END) as critical_count"),
                DB::raw('SUM(CASE WHEN control_room_alerts.escalation_level > 0 THEN 1 ELSE 0 END) as escalated_count'),
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
    public function attentionFlags(int|array|null $siteId = null): array
    {
        $baseQuery = ControlRoomAlert::query()
            ->actionable()
            ->tap(fn ($query) => $this->applySiteConstraint($query, $siteId));

        $unassigned = (clone $baseQuery)->whereNull('assigned_to_user_id')->count();
        $critical = (clone $baseQuery)->where('severity', 'critical')->count();
        $highEscalation = (clone $baseQuery)->where('escalation_level', '>=', 3)->count();
        $staleOpen = (clone $baseQuery)->where('status', 'open')
            ->where('triggered_at', '<', now()->subHours(4))
            ->count();

        // SLA compliance check (last 7 days). Reopened work retains one SLA
        // row, so the reporting window must use each cycle's start time rather
        // than the age of the original row.
        $recentSla = $this->slaCompliance(now()->subDays(7), now(), $siteId);
        $slaCompliancePct = $recentSla['compliance_pct'];

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

        if ($slaCompliancePct !== null && $slaCompliancePct < 90) {
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
    protected function baseAlertQuery(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId = null,
        ?User $viewer = null,
    ) {
        return ControlRoomAlert::query()
            ->where('triggered_at', '>=', $from)
            ->where('triggered_at', '<=', $to)
            ->tap(fn ($query) => $this->applySiteConstraint($query, $siteId))
            ->tap(fn (Builder $query) => $this->applyViewerContentConstraint($query, $viewer));
    }

    /**
     * Calculate average time difference between two datetime columns.
     */
    protected function avgTimeDiff(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId,
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

    protected function applySiteConstraint(Builder $query, int|array|null $siteId): void
    {
        if ($siteId === null) {
            return;
        }

        $this->siteAccess->applyAlertSiteScopeForSiteIds(
            $query,
            is_array($siteId) ? $siteId : [$siteId],
        );
    }

    private function applyViewerContentConstraint(Builder $query, ?User $viewer): void
    {
        if ($viewer !== null) {
            $this->alertAccess->applyControlledMedicationContentScope($query, $viewer);
        }
    }

    /**
     * Flatten the persisted current clock and immutable history snapshots into
     * one report row per SLA cycle. Report periods follow cycle start time, not
     * the original alert timestamp, so reopened work is neither hidden nor
     * collapsed into the latest clock.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function slaCyclesInPeriod(
        Carbon $from,
        Carbon $to,
        int|array|null $siteId,
        ?User $viewer = null,
    ): Collection {
        return AlertSla::query()
            ->whereHas('alert', function (Builder $alertQuery) use ($siteId, $viewer): void {
                $this->applySiteConstraint($alertQuery, $siteId);
                $this->applyViewerContentConstraint($alertQuery, $viewer);
            })
            ->with('alert:id,client_id,site_id,severity,status,triggered_at')
            ->get()
            ->flatMap(fn (AlertSla $sla) => $this->reportableSlaCycles($sla))
            ->filter(fn (array $cycle) => $cycle['started_at'] instanceof Carbon
                && $cycle['started_at']->betweenIncluded($from, $to))
            ->values();
    }

    /** @return array<int, array<string, mixed>> */
    private function reportableSlaCycles(AlertSla $sla): array
    {
        $cycles = collect($sla->cycle_history ?? [])
            ->map(fn (array $snapshot) => $this->normaliseSlaCycle($sla, $snapshot))
            ->filter()
            ->values();

        if ($sla->ended_as === null
            && $sla->sla_definition_id !== null
            && $sla->alert?->status !== ControlRoomAlert::STATUS_DISMISSED) {
            $cycles->push($this->normaliseSlaCycle($sla, [
                'cycle_number' => $sla->cycle_number,
                'cycle_started_at' => $sla->cycle_started_at?->toIso8601String(),
                'ended_at' => null,
                'ended_as' => null,
                'definition' => ['id' => $sla->sla_definition_id],
                'deadlines' => [
                    'acknowledge_at' => $sla->acknowledge_deadline?->toIso8601String(),
                    'response_at' => $sla->response_deadline?->toIso8601String(),
                    'resolution_at' => $sla->resolution_deadline?->toIso8601String(),
                ],
                'results' => [
                    'acknowledged_at' => $sla->acknowledged_at?->toIso8601String(),
                    'responded_at' => $sla->responded_at?->toIso8601String(),
                    'resolved_at' => $sla->resolved_at?->toIso8601String(),
                    'acknowledge_breached' => (bool) $sla->acknowledge_breached,
                    'response_breached' => (bool) $sla->response_breached,
                    'resolution_breached' => (bool) $sla->resolution_breached,
                ],
            ]));
        }

        return $cycles->filter()->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function normaliseSlaCycle(AlertSla $sla, array $cycle): ?array
    {
        $endedAs = data_get($cycle, 'ended_as');
        $definitionId = data_get($cycle, 'definition.id');
        if ($definitionId === null || in_array($endedAs, [
            AlertSla::ENDED_DISMISSED,
            AlertSla::ENDED_RECONCILED_NO_MATCH,
        ], true)) {
            return null;
        }

        $cycleNumber = (int) (data_get($cycle, 'cycle_number') ?: 1);
        $endedAt = $this->cycleTimestamp(data_get($cycle, 'ended_at'));
        $startedAt = $this->cycleTimestamp(data_get($cycle, 'cycle_started_at'))
            ?? ($cycleNumber === 1 ? $sla->alert?->triggered_at : null)
            ?? $endedAt
            ?? $sla->created_at;
        $evaluationTime = $endedAt ?? now();

        $milestones = collect(['acknowledge', 'response', 'resolution'])
            ->mapWithKeys(function (string $milestone) use ($cycle, $evaluationTime) {
                $deadline = $this->cycleTimestamp(data_get($cycle, "deadlines.{$milestone}_at"));
                $completedKey = match ($milestone) {
                    'acknowledge' => 'acknowledged_at',
                    'response' => 'responded_at',
                    'resolution' => 'resolved_at',
                };
                $completed = $this->cycleTimestamp(data_get($cycle, "results.{$completedKey}"));
                $storedBreach = (bool) data_get($cycle, "results.{$milestone}_breached", false);
                $breached = $storedBreach || ($deadline !== null && (
                    $completed !== null
                        ? $completed->gt($deadline)
                        : $evaluationTime->gt($deadline)
                ));

                return [$milestone => [
                    'deadline' => $deadline,
                    'completed' => $completed,
                    'breached' => $breached,
                ]];
            });
        $configured = $milestones->filter(fn (array $milestone) => $milestone['deadline'] !== null);
        $breached = $configured->contains(fn (array $milestone) => $milestone['breached']);
        $allCompleted = $configured->isNotEmpty()
            && $configured->every(fn (array $milestone) => $milestone['completed'] !== null);
        $assessed = $breached || $allCompleted;
        $met = $assessed
            && ! $breached
            && $configured->isNotEmpty()
            && $configured->every(fn (array $milestone) => $milestone['completed']->lte($milestone['deadline']));

        return [
            'alert_id' => (int) $sla->alert_id,
            'severity' => $sla->alert?->severity,
            'cycle_number' => $cycleNumber,
            'started_at' => $startedAt,
            'acknowledged_at' => $milestones['acknowledge']['completed'],
            'responded_at' => $milestones['response']['completed'],
            'resolved_at' => $milestones['resolution']['completed'],
            'acknowledge_breached' => $milestones['acknowledge']['breached'],
            'response_breached' => $milestones['response']['breached'],
            'resolution_breached' => $milestones['resolution']['breached'],
            'assessed' => $assessed,
            'breached' => $breached,
            'met' => $met,
        ];
    }

    private function cycleTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function averageCycleDuration(
        Collection $cycles,
        string $completedKey,
        int $secondsPerUnit,
    ): float {
        $durations = $cycles
            ->filter(fn (array $cycle) => $cycle['started_at'] instanceof Carbon
                && $cycle[$completedKey] instanceof Carbon
                && $cycle[$completedKey]->gte($cycle['started_at']))
            ->map(fn (array $cycle) => (
                $cycle[$completedKey]->getTimestamp() - $cycle['started_at']->getTimestamp()
            ) / $secondsPerUnit);

        return round((float) ($durations->avg() ?? 0), 1);
    }
}
