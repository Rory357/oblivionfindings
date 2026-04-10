<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\ControlRoom\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ControlRoomDashboardController extends Controller
{
    public function __construct(
        protected ControlRoomReportService $reportService,
    ) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        // --- Period resolution ---
        $period = $request->input('period', '7d');
        $from = match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => now()->subDays(7),
        };
        $to = now();

        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        // --- Alert list query (existing behaviour preserved) ---
        $query = ControlRoomAlert::query()
            ->with(['asset:id,name,asset_tag', 'assignedTo:id,name', 'sla', 'client:id,first_name,last_name']);

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('severity') && $request->input('severity') !== 'all') {
            $query->where('severity', $request->input('severity'));
        }
        if ($request->filled('source') && $request->input('source') !== 'all') {
            $query->where('source', $request->input('source'));
        }
        if ($request->filled('assigned_to')) {
            if ($request->input('assigned_to') === 'unassigned') {
                $query->whereNull('assigned_to_user_id');
            } elseif ($request->input('assigned_to') === 'me') {
                $query->where('assigned_to_user_id', $user->id);
            } else {
                $query->where('assigned_to_user_id', (int) $request->input('assigned_to'));
            }
        }
        if ($request->filled('escalation_level') && $request->input('escalation_level') !== 'all') {
            $query->where('escalation_level', '>=', (int) $request->input('escalation_level'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('alert_type', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('asset', fn ($aq) => $aq->where('name', 'like', "%{$search}%")
                      ->orWhere('asset_tag', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('date_from')) {
            $query->whereDate('triggered_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('triggered_at', '<=', $request->input('date_to'));
        }
        if ($siteId) {
            $query->where('site_id', $siteId);
        }

        $sortField = $request->input('sort', 'triggered_at');
        $sortDir = $request->input('dir', 'desc');
        $allowedSorts = ['triggered_at', 'severity', 'status', 'escalation_level', 'alert_type'];
        if (in_array($sortField, $allowedSorts, true)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->latest('triggered_at');
        }

        $alerts = $query->paginate(25)->withQueryString();

        // --- Real-time stats (current state, not historical) ---
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

        // --- PR11 report service metrics (period-aware, replaces inline queries) ---
        $volume = $this->reportService->alertVolume($from, $to, $siteId);
        $sla = $this->reportService->slaCompliance($from, $to, $siteId);
        $escalation = $this->reportService->escalationAnalysis($from, $to, $siteId);

        // --- Attention flags (PR12) ---
        $attentionFlags = $this->reportService->attentionFlags($siteId);

        // --- Site comparison (PR12) ---
        $siteComparison = $this->reportService->siteComparison($from, $to);

        // --- Active shift ---
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

        // --- Recent activity ---
        $recentActivity = AuditLog::where('action', 'like', 'controlRoom.%')
            ->where('action', '!=', 'controlRoom.dashboard.view')
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'type' => $log->action,
                'occurred_at' => $log->created_at->toISOString(),
                'subject' => str_replace('controlRoom.', '', $log->action),
                'body' => null,
                'meta' => $log->meta,
                'client' => null,
                'site' => null,
            ])->values()->toArray();

        $staff = User::staff()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'provider_manager', 'coordinator']))
            ->orderBy('name')
            ->get(['id', 'name']);

        $sites = Site::active()->orderBy('name')->get(['id', 'name']);

        AuditLogger::log('controlRoom.dashboard.view', null, [
            'filters' => $request->only(['status', 'severity', 'source', 'assigned_to', 'search', 'period', 'site_id']),
        ]);

        return Inertia::render('control-room/index', [
            'alerts' => [
                'data' => $alerts->getCollection()->map(fn ($a) => [
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

            // PR11 metrics (replaces old inline queries)
            'daily_trend' => $volume['daily_trend'],
            'by_severity' => $volume['by_severity'],
            'unresolved_by_severity' => ControlRoomAlert::unresolved()
                ->select('severity', DB::raw('COUNT(*) as count'))
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),
            'by_source' => $volume['by_source'],
            'top_alert_types' => $volume['top_alert_types'],
            'sparkline_data' => array_map(fn ($d) => $d['count'], $volume['daily_trend']),
            'alerts_today' => ControlRoomAlert::whereDate('triggered_at', now()->toDateString())->count(),
            'alerts_yesterday' => ControlRoomAlert::whereDate('triggered_at', now()->subDay()->toDateString())->count(),
            'avg_response_minutes' => $sla['avg_acknowledge_minutes'],
            'sla_compliance_pct' => (int) $sla['compliance_pct'],
            'escalation_rate' => $escalation['escalation_rate'],

            // Daily trend data for SLA + escalation charts
            'sla_daily_trend' => $this->buildSlaDailyTrend($from, $to, $siteId),
            'escalation_daily_trend' => $this->buildEscalationDailyTrend($from, $to, $siteId),

            // PR12 additions
            'attention_flags' => $attentionFlags,
            'site_comparison' => $siteComparison,
            'period' => $period,
            'sites' => $sites,

            // Workload + queue pressure for dashboard charts
            'workload' => $this->reportService->workloadDistribution($from, $to, $siteId),
            'queues' => \App\Models\ControlRoom\TriageQueue::active()
                ->withCount(['alerts as active_count' => fn ($q) => $q->whereNotIn('status', ['resolved', 'closed'])])
                ->orderBy('tier')
                ->get(['id', 'name', 'tier'])
                ->map(fn ($q) => ['name' => $q->name, 'tier' => $q->tier, 'active_alerts' => $q->active_count])
                ->toArray(),

            // Preserved existing props
            'active_shift' => $activeShiftData,
            'recent_activity' => $recentActivity,
            'staff' => $staff,
            'filters' => $request->only(['status', 'severity', 'source', 'assigned_to', 'escalation_level', 'search', 'date_from', 'date_to', 'sort', 'dir', 'period', 'site_id']),
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
                'escalate' => $user->canDo('controlRoom.alerts.escalate'),
                'create' => $user->canDo('controlRoom.alerts.create'),
                'viewReports' => $user->canDo('controlRoom.reports.view'),
            ],
        ]);
    }

    /**
     * Build daily SLA compliance trend data.
     *
     * Returns array of {date, compliance_pct} for each day in range.
     */
    private function buildSlaDailyTrend(Carbon $from, Carbon $to, ?int $siteId): array
    {
        $rows = \App\Models\ControlRoom\AlertSla::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('alert', fn ($aq) => $aq->where('site_id', $siteId)))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN acknowledge_breached = 1 OR response_breached = 1 OR resolution_breached = 1 THEN 1 ELSE 0 END) as breached")
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return $rows->map(fn ($r) => [
            'date' => $r->date,
            'compliance_pct' => $r->total > 0
                ? (int) round((($r->total - $r->breached) / $r->total) * 100)
                : 100,
        ])->values()->toArray();
    }

    /**
     * Build daily escalation count trend data.
     */
    private function buildEscalationDailyTrend(Carbon $from, Carbon $to, ?int $siteId): array
    {
        return ControlRoomAlert::query()
            ->whereBetween('triggered_at', [$from, $to])
            ->where('escalation_level', '>', 0)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->select(
                DB::raw('DATE(triggered_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy(DB::raw('DATE(triggered_at)'))
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => ['date' => $r->date, 'count' => (int) $r->count])
            ->values()
            ->toArray();
    }
}
