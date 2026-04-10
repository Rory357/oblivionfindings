<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        abort_unless($user && $user->canDo('controlRoom.reports.view'), 403);

        [$from, $to, $period] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        AuditLogger::log('controlRoom.reports.view', null, ['period' => $period]);

        return Inertia::render('control-room/reports', [
            'period' => $period,
            'site_id' => $siteId,
            'sla' => $this->reportService->slaCompliance($from, $to, $siteId),
            'volume' => $this->reportService->alertVolume($from, $to, $siteId),
            'escalation' => $this->reportService->escalationAnalysis($from, $to, $siteId),
            'workload' => $this->reportService->workloadDistribution($from, $to, $siteId),
            'playbooks' => $this->reportService->playbookPerformance($from, $to),
        ]);
    }

    /**
     * SLA compliance report endpoint.
     */
    public function sla(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.reports.view'), 403);

        [$from, $to] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        return response()->json($this->reportService->slaCompliance($from, $to, $siteId));
    }

    /**
     * Alert volume report endpoint.
     */
    public function alerts(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.reports.view'), 403);

        [$from, $to] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        return response()->json($this->reportService->alertVolume($from, $to, $siteId));
    }

    /**
     * Workload distribution report endpoint.
     */
    public function workload(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.reports.view'), 403);

        [$from, $to] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        return response()->json($this->reportService->workloadDistribution($from, $to, $siteId));
    }

    /**
     * Summary endpoint — compact KPIs for dashboard widgets.
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.reports.view'), 403);

        [$from, $to] = $this->resolveDateRange($request);
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;

        $sla = $this->reportService->slaCompliance($from, $to, $siteId);
        $volume = $this->reportService->alertVolume($from, $to, $siteId);
        $escalation = $this->reportService->escalationAnalysis($from, $to, $siteId);

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
        abort_unless($user && $user->canDo('controlRoom.reports.view'), 403);

        [$from, $to, $period] = $this->resolveDateRange($request);

        $alerts = ControlRoomAlert::where('triggered_at', '>=', $from)
            ->where('triggered_at', '<=', $to)
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

        $period = $request->input('period', '30d');
        $to = now();
        $from = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };

        return [$from, $to, $period];
    }
}
