<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoomAlert;
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
