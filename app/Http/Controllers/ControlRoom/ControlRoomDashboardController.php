<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\Shift;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ControlRoomDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $query = ControlRoomAlert::query()
            ->with(['asset:id,name,asset_tag', 'assignedTo:id,name', 'sla', 'client:id,first_name,last_name']);

        // Filter by status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        // Filter by severity
        if ($request->filled('severity') && $request->input('severity') !== 'all') {
            $query->where('severity', $request->input('severity'));
        }

        // Filter by source
        if ($request->filled('source') && $request->input('source') !== 'all') {
            $query->where('source', $request->input('source'));
        }

        // Filter by assignee
        if ($request->filled('assigned_to')) {
            if ($request->input('assigned_to') === 'unassigned') {
                $query->whereNull('assigned_to_user_id');
            } elseif ($request->input('assigned_to') === 'me') {
                $query->where('assigned_to_user_id', $user->id);
            } else {
                $query->where('assigned_to_user_id', (int) $request->input('assigned_to'));
            }
        }

        // Filter by escalation level
        if ($request->filled('escalation_level') && $request->input('escalation_level') !== 'all') {
            $query->where('escalation_level', '>=', (int) $request->input('escalation_level'));
        }

        // Search by alert type or notes
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('alert_type', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('asset', fn($aq) => $aq->where('name', 'like', "%{$search}%")
                      ->orWhere('asset_tag', 'like', "%{$search}%"));
            });
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('triggered_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('triggered_at', '<=', $request->input('date_to'));
        }

        // Sorting
        $sortField = $request->input('sort', 'triggered_at');
        $sortDir = $request->input('dir', 'desc');
        $allowedSorts = ['triggered_at', 'severity', 'status', 'escalation_level', 'alert_type'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->latest('triggered_at');
        }

        $alerts = $query->paginate(25)->withQueryString();

        // Calculate statistics
        $stats = [
            'total' => ControlRoomAlert::count(),
            'open' => ControlRoomAlert::where('status', 'open')->count(),
            'acknowledged' => ControlRoomAlert::where('status', 'ack')->count(),
            'triaging' => ControlRoomAlert::where('status', 'triaging')->count(),
            'resolved' => ControlRoomAlert::where('status', 'resolved')->count(),
            'closed' => ControlRoomAlert::where('status', 'closed')->count(),
            'critical' => ControlRoomAlert::where('severity', 'critical')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'high' => ControlRoomAlert::where('severity', 'high')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'escalated' => ControlRoomAlert::where('escalation_level', '>', 0)->whereNotIn('status', ['resolved', 'closed'])->count(),
            'unassigned' => ControlRoomAlert::whereNull('assigned_to_user_id')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'my_alerts' => ControlRoomAlert::where('assigned_to_user_id', $user->id)->whereNotIn('status', ['resolved', 'closed'])->count(),
        ];

        // Staff list for assignment filter
        $staff = User::staff()
            ->whereHas('roles', fn($q) => $q->whereIn('name', ['admin', 'provider_manager', 'coordinator']))
            ->orderBy('name')
            ->get(['id', 'name']);

        // Daily trend (last 14 days) - generate all dates to avoid empty gaps
        $startDate = now()->subDays(13)->startOfDay();
        $dailyTrendRaw = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->select(DB::raw('DATE(triggered_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(triggered_at)'))
            ->pluck('count', 'date')
            ->toArray();

        $dailyTrend = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dailyTrend[] = [
                'date' => now()->subDays($i)->format('M j'),
                'count' => $dailyTrendRaw[$date] ?? 0,
            ];
        }

        // Severity breakdown for chart
        $bySeverity = ControlRoomAlert::select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        // Unresolved by severity (for donut chart)
        $unresolvedBySeverity = ControlRoomAlert::unresolved()
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        // By source breakdown
        $bySource = ControlRoomAlert::where('triggered_at', '>=', now()->subDays(7))
            ->select('source', DB::raw('COUNT(*) as count'))
            ->groupBy('source')
            ->orderByDesc('count')
            ->limit(8)
            ->pluck('count', 'source')
            ->toArray();

        // Top alert types (last 7 days)
        $topAlertTypes = ControlRoomAlert::where('triggered_at', '>=', now()->subDays(7))
            ->select('alert_type', DB::raw('COUNT(*) as count'))
            ->groupBy('alert_type')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'alert_type')
            ->toArray();

        // Sparkline data (just the counts array)
        $sparklineData = array_map(fn($d) => $d['count'], $dailyTrend);

        // Alerts today vs yesterday for trend
        $alertsToday = ControlRoomAlert::whereDate('triggered_at', now()->toDateString())->count();
        $alertsYesterday = ControlRoomAlert::whereDate('triggered_at', now()->subDay()->toDateString())->count();

        // Average response time (last 7 days)
        $driver = DB::connection()->getDriverName();
        $avgAckExpr = $driver === 'sqlite'
            ? "AVG((strftime('%s', acknowledged_at) - strftime('%s', created_at)) / 60.0)"
            : 'AVG(TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at))';
        $avgResponseMinutes = (float) AlertSla::where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('acknowledged_at')
            ->selectRaw($avgAckExpr . ' as avg_mins')
            ->value('avg_mins') ?: 0;

        // SLA compliance (last 7 days)
        $totalSlaAlerts = AlertSla::where('created_at', '>=', now()->subDays(7))->count();
        $breachedSlaAlerts = AlertSla::where('created_at', '>=', now()->subDays(7))
            ->where(function ($q) {
                $q->where('acknowledge_breached', true)
                  ->orWhere('response_breached', true)
                  ->orWhere('resolution_breached', true);
            })->count();
        $slaCompliancePct = $totalSlaAlerts > 0 ? round((($totalSlaAlerts - $breachedSlaAlerts) / $totalSlaAlerts) * 100) : 100;

        // Active shift
        $activeShift = Shift::where('status', 'active')->latest('starts_at')->first();
        $activeShiftData = null;
        if ($activeShift) {
            $leadName = $activeShift->shift_lead_user_id
                ? User::where('id', $activeShift->shift_lead_user_id)->value('name')
                : null;
            $activeShiftData = [
                'name' => $activeShift->name,
                'lead_name' => $leadName,
                'started_at' => $activeShift->starts_at?->toISOString(),
            ];
        }

        // Recent activity (last 15 control room audit logs)
        $recentActivity = AuditLog::where('action', 'like', 'controlRoom.%')
            ->where('action', '!=', 'controlRoom.dashboard.view')
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn($log) => [
                'id' => $log->id,
                'type' => $log->action,
                'occurred_at' => $log->created_at->toISOString(),
                'subject' => str_replace('controlRoom.', '', $log->action),
                'body' => null,
                'meta' => $log->meta,
                'client' => null,
                'site' => null,
            ])->values()->toArray();

        AuditLogger::log('controlRoom.dashboard.view', null, [
            'filters' => $request->only(['status', 'severity', 'source', 'assigned_to', 'search']),
        ]);

        return Inertia::render('control-room/index', [
            'alerts' => [
                'data' => $alerts->getCollection()->map(fn($a) => [
                    'id' => $a->id,
                    'source' => $a->source,
                    'alert_type' => $a->alert_type,
                    'severity' => $a->severity,
                    'status' => $a->status,
                    'escalation_level' => $a->escalation_level,
                    'triggered_at' => optional($a->triggered_at)->toISOString(),
                    'acknowledged_at' => optional($a->acknowledged_at)->toISOString(),
                    'asset_id' => $a->asset_id,
                    'asset' => $a->asset ? [
                        'id' => $a->asset->id,
                        'name' => $a->asset->name,
                        'asset_tag' => $a->asset->asset_tag,
                    ] : null,
                    'assigned_to' => $a->assignedTo ? [
                        'id' => $a->assignedTo->id,
                        'name' => $a->assignedTo->name,
                    ] : null,
                    'client_id' => $a->client_id,
                    'client_name' => $a->client ? trim($a->client->first_name . ' ' . $a->client->last_name) : null,
                    'site_id' => $a->site_id,
                    'sla_status' => $a->sla ? ($a->sla->acknowledge_breached || $a->sla->response_breached || $a->sla->resolution_breached ? 'breached' : (($a->sla->acknowledge_deadline && $a->sla->acknowledge_deadline->isPast()) || ($a->sla->response_deadline && $a->sla->response_deadline->isPast()) ? 'at_risk' : 'on_track')) : null,
                    'notes' => $a->notes ? substr($a->notes, 0, 100) . (strlen($a->notes) > 100 ? '...' : '') : null,
                ])->values(),
                'links' => $alerts->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $alerts->currentPage(),
                    'last_page' => $alerts->lastPage(),
                    'per_page' => $alerts->perPage(),
                    'total' => $alerts->total(),
                ],
            ],
            'stats' => $stats,
            'daily_trend' => $dailyTrend,
            'by_severity' => $bySeverity,
            'unresolved_by_severity' => $unresolvedBySeverity,
            'by_source' => $bySource,
            'top_alert_types' => $topAlertTypes,
            'sparkline_data' => $sparklineData,
            'alerts_today' => $alertsToday,
            'alerts_yesterday' => $alertsYesterday,
            'avg_response_minutes' => round($avgResponseMinutes, 1),
            'sla_compliance_pct' => $slaCompliancePct,
            'active_shift' => $activeShiftData,
            'recent_activity' => $recentActivity,
            'staff' => $staff,
            'filters' => $request->only(['status', 'severity', 'source', 'assigned_to', 'escalation_level', 'search', 'date_from', 'date_to', 'sort', 'dir']),
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
                'escalate' => $user->canDo('controlRoom.alerts.escalate'),
                'create' => $user->canDo('controlRoom.alerts.create'),
                'viewReports' => $user->canDo('controlRoom.reports.view'),
            ],
        ]);
    }
}
