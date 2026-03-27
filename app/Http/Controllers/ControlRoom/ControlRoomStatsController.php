<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ControlRoomStatsController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $period = $request->input('period', '7d');
        if (! in_array($period, ['24h', '7d', '30d'])) {
            $period = '7d';
        }

        $startDate = match ($period) {
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
        };

        $driver = DB::connection()->getDriverName();

        // --- KPIs ---
        $avgAckExpr = $driver === 'sqlite'
            ? "AVG((strftime('%s', acknowledged_at) - strftime('%s', triggered_at)) / 60.0)"
            : 'AVG(TIMESTAMPDIFF(MINUTE, triggered_at, acknowledged_at))';

        $avgResExpr = $driver === 'sqlite'
            ? "AVG((strftime('%s', resolved_at) - strftime('%s', triggered_at)) / 3600.0)"
            : 'AVG(TIMESTAMPDIFF(HOUR, triggered_at, resolved_at))';

        $avgAcknowledgeMinutes = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->whereNotNull('acknowledged_at')
            ->selectRaw($avgAckExpr . ' as avg_min')
            ->value('avg_min');

        $avgResolutionHours = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->whereNotNull('resolved_at')
            ->selectRaw($avgResExpr . ' as avg_hrs')
            ->value('avg_hrs');

        $totalSla = AlertSla::whereHas('alert', fn ($q) => $q->where('triggered_at', '>=', $startDate))->count();
        $compliantSla = $totalSla > 0
            ? AlertSla::whereHas('alert', fn ($q) => $q->where('triggered_at', '>=', $startDate))
                ->where(function ($q) {
                    $q->where(function ($sq) {
                        $sq->where('acknowledge_breached', false)
                            ->orWhereNull('acknowledge_breached');
                    })->where(function ($sq) {
                        $sq->where('response_breached', false)
                            ->orWhereNull('response_breached');
                    })->where(function ($sq) {
                        $sq->where('resolution_breached', false)
                            ->orWhereNull('resolution_breached');
                    });
                })
                ->count()
            : 0;
        $slaCompliancePct = $totalSla > 0 ? round(($compliantSla / $totalSla) * 100, 1) : 100;

        $openAlerts = ControlRoomAlert::unresolved()->count();
        $alertsToday = ControlRoomAlert::whereDate('triggered_at', today())->count();

        $kpis = [
            'avg_acknowledge_minutes' => round((float) $avgAcknowledgeMinutes, 1),
            'avg_resolution_hours' => round((float) $avgResolutionHours, 1),
            'sla_compliance_pct' => $slaCompliancePct,
            'open_alerts' => $openAlerts,
            'alerts_today' => $alertsToday,
        ];

        // --- Alert volume trend ---
        if ($period === '24h') {
            // Hourly buckets
            $dateExpr = $driver === 'sqlite'
                ? "strftime('%Y-%m-%d %H:00', triggered_at)"
                : "DATE_FORMAT(triggered_at, '%Y-%m-%d %H:00')";

            $raw = ControlRoomAlert::where('triggered_at', '>=', $startDate)
                ->selectRaw($dateExpr . ' as bucket, COUNT(*) as count')
                ->groupByRaw($dateExpr)
                ->pluck('count', 'bucket')
                ->toArray();

            $volumeTrend = [];
            for ($i = 23; $i >= 0; $i--) {
                $dt = now()->subHours($i);
                $key = $dt->format('Y-m-d H:00');
                $volumeTrend[] = [
                    'label' => $dt->format('H:00'),
                    'count' => $raw[$key] ?? 0,
                ];
            }
        } else {
            // Daily buckets
            $dateExpr = $driver === 'sqlite'
                ? "DATE(triggered_at)"
                : "DATE(triggered_at)";

            $days = $period === '7d' ? 7 : 30;

            $raw = ControlRoomAlert::where('triggered_at', '>=', $startDate)
                ->selectRaw($dateExpr . ' as bucket, COUNT(*) as count')
                ->groupByRaw($dateExpr)
                ->pluck('count', 'bucket')
                ->toArray();

            $volumeTrend = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $dt = now()->subDays($i);
                $key = $dt->format('Y-m-d');
                $volumeTrend[] = [
                    'label' => $dt->format('M j'),
                    'count' => $raw[$key] ?? 0,
                ];
            }
        }

        // --- Top 10 sources ---
        $topSources = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->whereNotNull('source')
            ->select('source', DB::raw('COUNT(*) as count'))
            ->groupBy('source')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'source')
            ->map(fn ($count, $source) => ['name' => $source, 'count' => $count])
            ->values()
            ->toArray();

        // --- Top 10 alert types ---
        $topAlertTypes = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->whereNotNull('alert_type')
            ->select('alert_type', DB::raw('COUNT(*) as count'))
            ->groupBy('alert_type')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'alert_type')
            ->map(fn ($count, $type) => ['name' => $type, 'count' => $count])
            ->values()
            ->toArray();

        // --- Severity distribution (unresolved) ---
        $severityDistribution = ControlRoomAlert::unresolved()
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        // --- Operator performance ---
        $avgResponseExpr = $driver === 'sqlite'
            ? "AVG((strftime('%s', acknowledged_at) - strftime('%s', assigned_at)) / 60.0)"
            : 'AVG(TIMESTAMPDIFF(MINUTE, assigned_at, acknowledged_at))';

        $operators = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->whereNotNull('assigned_to_user_id')
            ->select(
                'assigned_to_user_id',
                DB::raw('COUNT(*) as alerts_handled'),
                DB::raw($avgResponseExpr . ' as avg_response_minutes')
            )
            ->groupBy('assigned_to_user_id')
            ->orderByDesc('alerts_handled')
            ->limit(20)
            ->get();

        $userIds = $operators->pluck('assigned_to_user_id')->unique()->toArray();
        $userNames = User::whereIn('id', $userIds)->pluck('name', 'id');

        $operatorPerformance = $operators->map(fn ($op) => [
            'name' => $userNames[$op->assigned_to_user_id] ?? 'Unknown',
            'alerts_handled' => (int) $op->alerts_handled,
            'avg_response_minutes' => round((float) $op->avg_response_minutes, 1),
        ])->values()->toArray();

        // --- Shift comparison (last 5 completed) ---
        $shiftComparison = Shift::where('status', 'completed')
            ->whereNotNull('ends_at')
            ->orderByDesc('ends_at')
            ->limit(5)
            ->get()
            ->map(fn (Shift $s) => [
                'name' => $s->name,
                'duration_hours' => round($s->starts_at->diffInMinutes($s->ends_at) / 60, 1),
                'alerts_created' => (int) $s->alerts_created,
                'alerts_resolved' => (int) $s->alerts_resolved,
                'alerts_escalated' => (int) $s->alerts_escalated,
            ])
            ->values()
            ->toArray();

        // --- Signal source health ---
        $signalSources = SignalSource::orderBy('name')
            ->get()
            ->map(fn (SignalSource $ss) => [
                'name' => $ss->name,
                'status' => $ss->status,
                'last_heartbeat_at' => optional($ss->last_heartbeat_at)->toISOString(),
                'signal_count_24h' => (int) $ss->signal_count_24h,
                'is_healthy' => $ss->last_heartbeat_at && $ss->last_heartbeat_at->gte(now()->subMinutes(10)),
            ])
            ->values()
            ->toArray();

        AuditLogger::log('controlRoom.stats.view', null, ['period' => $period]);

        return Inertia::render('control-room/stats', [
            'period' => $period,
            'kpis' => $kpis,
            'volume_trend' => $volumeTrend,
            'top_sources' => $topSources,
            'top_alert_types' => $topAlertTypes,
            'severity_distribution' => $severityDistribution,
            'operator_performance' => $operatorPerformance,
            'shift_comparison' => $shiftComparison,
            'signal_sources' => $signalSources,
        ]);
    }
}
