<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ControlRoomReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.reports.view'), 403);

        $period = $request->input('period', '30d');
        $startDate = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };

        // Overall statistics
        $totalAlerts = ControlRoomAlert::where('triggered_at', '>=', $startDate)->count();
        $resolvedAlerts = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->whereIn('status', ['resolved', 'closed'])
            ->count();

        // Average resolution time (in hours)
        $avgResolutionTime = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->whereNotNull('resolved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, triggered_at, resolved_at)) as avg_hours')
            ->value('avg_hours');

        // Alerts by severity
        $bySeverity = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        // Alerts by status
        $byStatus = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Alerts by source
        $bySource = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->select('source', DB::raw('COUNT(*) as count'))
            ->groupBy('source')
            ->pluck('count', 'source')
            ->toArray();

        // Alerts by alert type (top 10)
        $byAlertType = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->select('alert_type', DB::raw('COUNT(*) as count'))
            ->groupBy('alert_type')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'alert_type')
            ->toArray();

        // Daily trend
        $dailyTrend = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->select(DB::raw('DATE(triggered_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(triggered_at)'))
            ->orderBy('date')
            ->get()
            ->map(fn($row) => [
                'date' => $row->date,
                'count' => $row->count,
            ])
            ->values()
            ->toArray();

        // Escalation statistics
        $escalatedCount = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->where('escalation_level', '>', 0)
            ->count();

        // Response time by severity (average hours to acknowledge)
        $responseTimeBySeverity = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->whereNotNull('acknowledged_at')
            ->select('severity', DB::raw('AVG(TIMESTAMPDIFF(MINUTE, triggered_at, acknowledged_at)) as avg_minutes'))
            ->groupBy('severity')
            ->pluck('avg_minutes', 'severity')
            ->toArray();

        // Top assignees
        $topAssignees = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->whereNotNull('assigned_to_user_id')
            ->with('assignedTo:id,name')
            ->select('assigned_to_user_id', DB::raw('COUNT(*) as count'))
            ->groupBy('assigned_to_user_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'user' => $row->assignedTo?->name ?? 'Unknown',
                'count' => $row->count,
            ])
            ->values()
            ->toArray();

        AuditLogger::log('controlRoom.reports.view', null, [
            'period' => $period,
        ]);

        return Inertia::render('control-room/reports', [
            'period' => $period,
            'stats' => [
                'total_alerts' => $totalAlerts,
                'resolved_alerts' => $resolvedAlerts,
                'resolution_rate' => $totalAlerts > 0 ? round(($resolvedAlerts / $totalAlerts) * 100, 1) : 0,
                'avg_resolution_hours' => round((float) $avgResolutionTime, 1),
                'escalated_count' => $escalatedCount,
                'escalation_rate' => $totalAlerts > 0 ? round(($escalatedCount / $totalAlerts) * 100, 1) : 0,
            ],
            'by_severity' => $bySeverity,
            'by_status' => $byStatus,
            'by_source' => $bySource,
            'by_alert_type' => $byAlertType,
            'daily_trend' => $dailyTrend,
            'response_time_by_severity' => $responseTimeBySeverity,
            'top_assignees' => $topAssignees,
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.reports.view'), 403);

        $period = $request->input('period', '30d');
        $startDate = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };

        $alerts = ControlRoomAlert::where('triggered_at', '>=', $startDate)
            ->with(['asset:id,name,asset_tag', 'assignedTo:id,name', 'resolvedBy:id,name'])
            ->orderByDesc('triggered_at')
            ->get();

        $csv = "ID,Source,Type,Severity,Status,Asset,Assigned To,Triggered At,Acknowledged At,Resolved At,Resolution Time (hrs),Escalation Level,Notes\n";

        foreach ($alerts as $alert) {
            $resolutionHours = '';
            if ($alert->triggered_at && $alert->resolved_at) {
                $resolutionHours = round($alert->triggered_at->diffInMinutes($alert->resolved_at) / 60, 1);
            }

            $csv .= implode(',', [
                $alert->id,
                '"' . str_replace('"', '""', $alert->source ?? '') . '"',
                '"' . str_replace('"', '""', $alert->alert_type ?? '') . '"',
                $alert->severity ?? '',
                $alert->status ?? '',
                '"' . str_replace('"', '""', $alert->asset?->name ?? '') . '"',
                '"' . str_replace('"', '""', $alert->assignedTo?->name ?? '') . '"',
                $alert->triggered_at?->toDateTimeString() ?? '',
                $alert->acknowledged_at?->toDateTimeString() ?? '',
                $alert->resolved_at?->toDateTimeString() ?? '',
                $resolutionHours,
                $alert->escalation_level ?? 0,
                '"' . str_replace('"', '""', substr($alert->notes ?? '', 0, 200)) . '"',
            ]) . "\n";
        }

        AuditLogger::log('controlRoom.reports.export', null, [
            'period' => $period,
            'count' => $alerts->count(),
        ]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="control-room-alerts-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }
}
