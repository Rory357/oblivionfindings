<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomReportService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ControlRoomStatsController extends Controller
{
    public function __construct(
        protected ControlRoomReportService $reportService,
    ) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->alertBypassPermissions();

        $period = $request->input('period', '7d');
        if (! in_array($period, ['24h', '7d', '30d'])) {
            $period = '7d';
        }

        $startDate = match ($period) {
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
        };
        $isUnrestrictedPlatformUser = $siteAccess->isUnrestrictedPlatformUser($user);
        $accessibleSiteIds = $isUnrestrictedPlatformUser
            ? null
            : $siteAccess->accessibleSiteIds($user, $bypassPermissions);
        $slaMetrics = $this->reportService->slaCompliance($startDate, now(), $accessibleSiteIds);

        $driver = DB::connection()->getDriverName();

        // --- KPIs ---
        $openAlerts = $this->scopedAlerts($user, $siteAccess)->actionable()->count();
        $alertsToday = $this->scopedAlerts($user, $siteAccess)->whereDate('triggered_at', today())->count();

        $kpis = [
            'avg_acknowledge_minutes' => $slaMetrics['avg_acknowledge_minutes'],
            'avg_resolution_hours' => $slaMetrics['avg_resolution_hours'],
            'sla_compliance_pct' => $slaMetrics['compliance_pct'],
            'open_alerts' => $openAlerts,
            'alerts_today' => $alertsToday,
        ];

        // --- Alert volume trend ---
        if ($period === '24h') {
            // Hourly buckets
            $dateExpr = $driver === 'sqlite'
                ? "strftime('%Y-%m-%d %H:00', triggered_at)"
                : "DATE_FORMAT(triggered_at, '%Y-%m-%d %H:00')";

            $raw = $this->scopedAlerts($user, $siteAccess)
                ->where('triggered_at', '>=', $startDate)
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

            $raw = $this->scopedAlerts($user, $siteAccess)
                ->where('triggered_at', '>=', $startDate)
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
        $topSources = $this->scopedAlerts($user, $siteAccess)
            ->where('triggered_at', '>=', $startDate)
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
        $topAlertTypes = $this->scopedAlerts($user, $siteAccess)
            ->where('triggered_at', '>=', $startDate)
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
        $severityDistribution = $this->scopedAlerts($user, $siteAccess)
            ->actionable()
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        // --- Operator performance ---
        $avgResponseExpr = $driver === 'sqlite'
            ? "AVG((strftime('%s', acknowledged_at) - strftime('%s', assigned_at)) / 60.0)"
            : 'AVG(TIMESTAMPDIFF(MINUTE, assigned_at, acknowledged_at))';

        $operators = $this->scopedAlerts($user, $siteAccess)
            ->where('triggered_at', '>=', $startDate)
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

        // Control Room shift counters are installation-wide snapshots and have
        // no trustworthy site or tenant dimension.
        $shiftComparison = $isUnrestrictedPlatformUser
            ? Shift::where('status', 'completed')
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
                ->toArray()
            : [];

        // --- Signal source health ---
        $signalSourceQuery = SignalSource::query()->orderBy('name');
        if ($accessibleSiteIds !== null) {
            if ($accessibleSiteIds === []) {
                $signalSourceQuery->whereRaw('1 = 0');
            } else {
                $signalSourceQuery
                    ->where(function ($sourceQuery) use ($accessibleSiteIds) {
                        $sourceQuery->whereHas('devices', fn ($deviceQuery) => $deviceQuery->whereIn('site_id', $accessibleSiteIds))
                            ->orWhereHas('signals', fn (Builder $signalQuery) => $this->applySignalSiteScope(
                                $signalQuery,
                                $accessibleSiteIds,
                            ));
                    })
                    ->withCount([
                        'signals as accessible_signal_count_24h' => function ($signalQuery) use ($accessibleSiteIds) {
                            $signalQuery->where('occurred_at', '>=', now()->subDay());
                            $this->applySignalSiteScope($signalQuery, $accessibleSiteIds);
                        },
                    ]);
            }
        }

        $signalSources = $signalSourceQuery
            ->get()
            ->map(fn (SignalSource $ss) => [
                'name' => $ss->name,
                'status' => $ss->status,
                'last_heartbeat_at' => optional($ss->last_heartbeat_at)->toISOString(),
                'signal_count_24h' => (int) ($accessibleSiteIds === null
                    ? $ss->signal_count_24h
                    : $ss->getAttribute('accessible_signal_count_24h')),
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

    protected function scopedAlerts(User $user, UserSiteAccessService $siteAccess)
    {
        $query = ControlRoomAlert::query();
        $siteAccess->applyAlertScope($query, $user, $this->alertBypassPermissions());

        return $query;
    }

    /**
     * A signal's own site is authoritative. Device site is only a fallback for
     * legacy signals whose direct site provenance is absent.
     *
     * @param  array<int, int>  $siteIds
     */
    protected function applySignalSiteScope(Builder $query, array $siteIds): Builder
    {
        $siteColumn = $query->qualifyColumn('site_id');

        return $query->where(function (Builder $siteScope) use ($siteColumn, $siteIds) {
            $siteScope->whereIn($siteColumn, $siteIds)
                ->orWhere(function (Builder $deviceFallback) use ($siteColumn, $siteIds) {
                    $deviceFallback
                        ->whereNull($siteColumn)
                        ->whereHas('device', fn (Builder $deviceQuery) => $deviceQuery->whereIn(
                            $deviceQuery->qualifyColumn('site_id'),
                            $siteIds,
                        ));
                });
        });
    }

    /**
     * @return array<int, string>
     */
    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }
}
