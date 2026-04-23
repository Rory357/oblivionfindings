<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomReportService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ControlRoomReportController extends Controller
{
    public function __construct(
        protected ControlRoomReportService $reportService,
    ) {}

    /**
     * Main reports dashboard — overview with all metric groups.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $this->canViewReports($user), 403);

        [$from, $to, $period] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;
        $reportSiteScope = $this->reportSiteScope($user, $siteId);
        $sla = $this->reportService->slaCompliance($from, $to, $reportSiteScope);
        $volume = $this->reportService->alertVolume($from, $to, $reportSiteScope);
        $escalation = $this->reportService->escalationAnalysis($from, $to, $reportSiteScope);
        $workload = $this->reportService->workloadDistribution($from, $to, $reportSiteScope);
        $playbooks = $this->reportService->playbookPerformance($from, $to);

        AuditLogger::log('controlRoom.reports.view', null, ['period' => $period]);

        return Inertia::render('control-room/reports', [
            'period' => $period,
            'site_id' => $siteId,
            'sla' => $sla,
            'volume' => $volume,
            'escalation' => $escalation,
            'workload' => $workload,
            'playbooks' => $playbooks,
            'stats' => [
                'total_alerts' => $volume['total'],
                'resolved_alerts' => $volume['resolved'],
                'resolution_rate' => $volume['resolution_rate'],
                'avg_resolution_hours' => $sla['avg_resolution_hours'],
                'escalated_count' => $escalation['escalated'],
                'escalation_rate' => $escalation['escalation_rate'],
            ],
            'by_severity' => $volume['by_severity'] ?? [],
            'by_status' => $this->statusBreakdown($from, $to, $user, $siteId),
            'by_source' => $volume['by_source'] ?? [],
            'by_alert_type' => $volume['top_alert_types'] ?? [],
            'daily_trend' => $volume['daily_trend'] ?? [],
            'response_time_by_severity' => [],
            'top_assignees' => collect($workload['handled_per_user'] ?? [])
                ->map(fn (array $row) => [
                    'user' => $row['user'],
                    'count' => $row['alerts_handled'],
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * SLA compliance report endpoint.
     */
    public function sla(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $this->canViewReports($user), 403);

        [$from, $to] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        return response()->json($this->reportService->slaCompliance($from, $to, $this->reportSiteScope($user, $siteId)));
    }

    /**
     * Alert volume report endpoint.
     */
    public function alerts(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $this->canViewReports($user), 403);

        [$from, $to] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        return response()->json($this->reportService->alertVolume($from, $to, $this->reportSiteScope($user, $siteId)));
    }

    /**
     * Workload distribution report endpoint.
     */
    public function workload(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $this->canViewReports($user), 403);

        [$from, $to] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        return response()->json($this->reportService->workloadDistribution($from, $to, $this->reportSiteScope($user, $siteId)));
    }

    /**
     * Summary endpoint — compact KPIs for dashboard widgets.
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $this->canViewReports($user), 403);

        [$from, $to] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;
        $reportSiteScope = $this->reportSiteScope($user, $siteId);

        $sla = $this->reportService->slaCompliance($from, $to, $reportSiteScope);
        $volume = $this->reportService->alertVolume($from, $to, $reportSiteScope);
        $escalation = $this->reportService->escalationAnalysis($from, $to, $reportSiteScope);

        return response()->json([
            'total_alerts' => $volume['total'],
            'open_alerts' => $volume['open'],
            'resolution_rate' => $volume['resolution_rate'],
            'sla_compliance' => $sla['compliance_pct'],
            'avg_acknowledge_minutes' => $sla['avg_acknowledge_minutes'],
            'avg_resolution_hours' => $sla['avg_resolution_hours'],
            'escalation_rate' => $escalation['escalation_rate'],
            'sla_breached' => $sla['sla_breached'],
        ]);
    }

    /**
     * CSV export — full alert data for the period.
     */
    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $this->canViewReports($user), 403);

        [$from, $to, $period] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        $alerts = ControlRoomAlert::where('triggered_at', '>=', $from)
            ->where('triggered_at', '<=', $to)
            ->tap(fn ($query) => $this->applyReportAlertScope($query, $user, $siteId))
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
                '"'.str_replace('"', '""', $alert->source ?? '').'"',
                '"'.str_replace('"', '""', $alert->alert_type ?? '').'"',
                $alert->severity ?? '',
                $alert->status ?? '',
                '"'.str_replace('"', '""', $alert->asset?->name ?? '').'"',
                '"'.str_replace('"', '""', $alert->assignedTo?->name ?? '').'"',
                $alert->triggered_at?->toDateTimeString() ?? '',
                $alert->acknowledged_at?->toDateTimeString() ?? '',
                $alert->resolved_at?->toDateTimeString() ?? '',
                $resolutionHours,
                $alert->escalation_level ?? 0,
                '"'.str_replace('"', '""', substr($alert->notes ?? '', 0, 200)).'"',
            ])."\n";
        }

        AuditLogger::log('controlRoom.reports.export', null, [
            'period' => $period,
            'count' => $alerts->count(),
        ]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="control-room-alerts-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    protected function applyReportAlertScope($query, $user, ?int $siteId): void
    {
        $siteAccess = app(UserSiteAccessService::class);
        $siteAccess->applyAlertScope($query, $user, $this->alertBypassPermissions());

        if ($siteId) {
            $query->where(function ($scopedQuery) use ($siteId) {
                $scopedQuery->where('site_id', $siteId)
                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('site_id', $siteId));
            });
        }
    }

    protected function reportSiteScope($user, ?int $siteId): int|array|null
    {
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->alertBypassPermissions();

        if ($siteId) {
            $siteAccess->assertCanAccessSiteId(
                $user,
                $siteId,
                $bypassPermissions,
                'You are not authorized to access Control Room reports for that site.',
            );

            return $siteId;
        }

        if ($siteAccess->canBypass($user, $bypassPermissions)) {
            return null;
        }

        return $siteAccess->accessibleSiteIds($user, $bypassPermissions);
    }

    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    protected function canViewReports($user): bool
    {
        return $user->canDo('controlRoom.reports.view') || $user->canDo('controlRoom.viewAny');
    }

    protected function statusBreakdown(Carbon $from, Carbon $to, $user, ?int $siteId): array
    {
        $query = ControlRoomAlert::query()
            ->where('triggered_at', '>=', $from)
            ->where('triggered_at', '<=', $to);

        $this->applyReportAlertScope($query, $user, $siteId);

        return $query
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Resolve date range from request.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected function resolveDateRange(Request $request): array
    {
        // Explicit date range takes priority
        if ($request->filled('date_from') && $request->filled('date_to')) {
            return [
                Carbon::parse($request->input('date_from'))->startOfDay(),
                Carbon::parse($request->input('date_to'))->endOfDay(),
                'custom',
            ];
        }

        $requestedPeriod = (string) $request->input('period', '30d');
        $period = in_array($requestedPeriod, ['7d', '30d', '90d', '1y'], true)
            ? $requestedPeriod
            : '30d';
        $to = now();
        $from = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
        };

        return [$from, $to, $period];
    }
}
