<?php

namespace App\Services\ControlRoom;

use App\Models\AuditLog;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ControlRoomDeskService
{
    /** @var list<string> */
    private const BYPASS_PERMISSIONS = ['reports.viewAny'];

    /** @var list<string> */
    private const ACTIVE_STATUSES = [
        ControlRoomAlert::STATUS_OPEN,
        ControlRoomAlert::STATUS_ACK,
        ControlRoomAlert::STATUS_TRIAGING,
        ControlRoomAlert::STATUS_CONFIRMED,
    ];

    /** @var array<string, array<string, mixed>> */
    private array $liveSnapshots = [];

    public function __construct(
        private readonly AlertWorklistQuery $worklistQuery,
        private readonly AlertWorklistPresenter $worklistPresenter,
        private readonly UserSiteAccessService $siteAccess,
        private readonly ControlRoomReportService $reports,
    ) {}

    public function prepareViewerAccess(User $user): void
    {
        $user->loadMissing([
            'permissionOverrides:id,key',
            'roles.permissions:id,key',
            'hrEmployeeProfile',
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{q: string, status: string, severity: string, source: string, queue_id: int|null, assigned_to: string, escalation_level: int|null, date_from: string, date_to: string, sort: string, dir: string, site_id: int|null, period: string}
     */
    public function filters(User $user, array $input): array
    {
        $this->prepareViewerAccess($user);

        $siteId = filled($input['site_id'] ?? null) ? (int) $input['site_id'] : null;
        if ($siteId !== null) {
            $this->siteAccess->assertCanAccessSiteId(
                $user,
                $siteId,
                self::BYPASS_PERMISSIONS,
                'You are not authorized to access the Control Room Desk for that site.',
            );
        }

        return [
            'q' => trim((string) ($input['q'] ?? $input['search'] ?? '')),
            'status' => $this->allowed((string) ($input['status'] ?? ''), [
                ...ControlRoomAlert::VALID_STATUSES,
                'all',
            ]),
            'severity' => $this->allowed((string) ($input['severity'] ?? ''), ['critical', 'high', 'medium', 'low', 'all']),
            'source' => trim((string) ($input['source'] ?? '')),
            'queue_id' => filled($input['queue_id'] ?? null) ? max(1, (int) $input['queue_id']) : null,
            'assigned_to' => trim((string) ($input['assigned_to'] ?? '')),
            'escalation_level' => filled($input['escalation_level'] ?? null)
                ? max(1, (int) $input['escalation_level'])
                : null,
            'date_from' => $this->dateFilter($input['date_from'] ?? null),
            'date_to' => $this->dateFilter($input['date_to'] ?? null),
            'sort' => $this->allowed((string) ($input['sort'] ?? ''), ['severity', 'triggered_at']),
            'dir' => $this->allowed((string) ($input['dir'] ?? ''), ['asc', 'desc']),
            'site_id' => $siteId,
            'period' => $this->allowed((string) ($input['period'] ?? '7d'), ['24h', '7d', '30d', '90d']) ?: '7d',
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function live(User $user, array $input = []): array
    {
        $filters = $this->filters($user, $input);
        $key = $user->id.':'.md5(serialize($filters));

        return $this->liveSnapshots[$key] ??= $this->buildLive($user, $filters);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function analytics(User $user, array $input = []): array
    {
        $filters = $this->filters($user, $input);
        [$from, $to] = $this->period($filters['period']);
        $siteScope = $this->reportSiteScope($user, $filters['site_id']);
        $cacheKey = sprintf(
            'control-room:desk:analytics:%d:%s:%s',
            $user->id,
            $filters['period'],
            $filters['site_id'] ?? 'all',
        );

        return Cache::remember($cacheKey, now()->addSeconds(90), function () use ($from, $to, $siteScope, $filters): array {
            return [
                'period' => $filters['period'],
                'volume' => $this->reports->alertVolume($from, $to, $siteScope),
                'sla' => $this->reports->slaCompliance($from, $to, $siteScope),
                'escalation' => $this->reports->escalationAnalysis($from, $to, $siteScope),
                'sla_daily_trend' => $this->reports->slaDailyTrend($from, $to, $siteScope),
                'sites' => $this->reports->siteComparison($from, $to, $siteScope),
                'cached_for_seconds' => 90,
            ];
        });
    }

    /** @return array<int, array{id: int, name: string}> */
    public function sites(User $user): array
    {
        return Site::query()
            ->active()
            ->tap(fn (Builder $query) => $this->siteAccess->applySiteScope($query, $user, self::BYPASS_PERMISSIONS))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Site $site) => ['id' => $site->id, 'name' => $site->name])
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    public function staff(User $user): array
    {
        return User::query()
            ->staff()
            ->tap(fn (Builder $query) => $this->siteAccess->applyControlRoomAssigneeScope($query, $user, self::BYPASS_PERMISSIONS))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $staff) => ['id' => $staff->id, 'name' => $staff->name])
            ->all();
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function buildLive(User $user, array $filters): array
    {
        $aggregate = $this->aggregate($user, $filters['site_id']);
        $worklist = $this->worklist($user, $filters);

        return [
            'hero' => $this->hero($aggregate),
            'worklist' => $worklist,
            'queues' => $this->queues($user, $filters['site_id']),
            'handover' => [
                'needs_incident' => (int) $aggregate->needs_incident,
                'awaiting_health_safety' => (int) $aggregate->awaiting_health_safety,
                'accepted_in_progress' => (int) $aggregate->accepted_in_progress,
                'operational_complete_governance_open' => (int) $aggregate->operational_complete_governance_open,
            ],
            'activity' => $this->activity($user, $filters['site_id']),
            'freshness' => [
                'updated_at' => now()->toIso8601String(),
                'stale_after_seconds' => 90,
            ],
            'stats' => $this->legacyStats($aggregate),
            'by_source' => $this->bySource($user, $filters['site_id']),
            'active_shift' => $this->activeShift($user),
        ];
    }

    /** @return array<string, int> */
    private function bySource(User $user, ?int $siteId): array
    {
        $query = ControlRoomAlert::query();
        $this->scopeAlerts($query, $user, $siteId);

        return $query
            ->select('control_room_alerts.source', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('control_room_alerts.source')
            ->pluck('aggregate', 'source')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** @return array{name: string, lead_name: string|null, started_at: string|null}|null */
    private function activeShift(User $user): ?array
    {
        $shift = Shift::query()
            ->with('shiftLead:id,name')
            ->active()
            ->latest('starts_at')
            ->first();

        return $shift ? [
            'name' => $shift->name,
            'lead_name' => $shift->shiftLead?->name,
            'started_at' => $shift->starts_at?->toIso8601String(),
        ] : null;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function worklist(User $user, array $filters): array
    {
        $status = $filters['status'];
        $lens = in_array($status, ControlRoomAlert::TERMINAL_STATUSES, true) ? 'history' : 'active';
        $assignedTo = $filters['assigned_to'] === 'me'
            ? $user->id
            : (is_numeric($filters['assigned_to']) ? (int) $filters['assigned_to'] : null);

        $query = $this->worklistQuery->forUser($user, array_filter([
            'lens' => $lens,
            'site_id' => $filters['site_id'],
            'severity' => $filters['severity'] === 'all' ? null : $filters['severity'],
            'source' => $filters['source'] === 'all' ? null : $filters['source'],
            'queue_id' => $filters['queue_id'],
            'assigned_to' => $assignedTo,
            'escalation_level' => $filters['escalation_level'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'q' => $filters['q'],
        ], fn ($value) => $value !== null && $value !== ''));

        if ($status !== '' && $status !== 'all') {
            $query->where('control_room_alerts.status', $status);
        }
        if ($filters['assigned_to'] === 'unassigned') {
            $query->whereNull('control_room_alerts.assigned_to_user_id');
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(25)->withQueryString();
        $rows = $paginator->getCollection()
            ->map(function (ControlRoomAlert $alert) use ($user): array {
                $row = $this->worklistPresenter->present($alert, $user);
                $row['alert_type'] = $alert->alert_type;
                $row['sla_status'] = match ($row['sla']['status']) {
                    'resolved' => 'on_track',
                    'not_applicable' => null,
                    default => $row['sla']['status'],
                };
                $row['assigned_to'] = $row['assignee'];
                $row['client_name'] = $row['person']['name'] ?? null;

                return $row;
            })
            ->values();

        return [
            'data' => $rows,
            'links' => $paginator->linkCollection()->toArray(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function aggregate(User $user, ?int $siteId): object
    {
        $query = ControlRoomAlert::query();
        $this->scopeAlerts($query, $user, $siteId);

        $active = "'".implode("','", self::ACTIVE_STATUSES)."'";
        $terminal = "'".implode("','", ControlRoomAlert::TERMINAL_STATUSES)."'";
        $unsnoozed = '(control_room_alerts.snoozed_until IS NULL OR control_room_alerts.snoozed_until <= ?)';
        $now = now();
        $responseAverage = DB::connection()->getDriverName() === 'sqlite'
            ? "AVG(CASE WHEN control_room_alerts.triggered_at >= ? AND desk_sla.responded_at IS NOT NULL THEN (strftime('%s', desk_sla.responded_at) - strftime('%s', control_room_alerts.triggered_at)) / 60.0 END)"
            : 'AVG(CASE WHEN control_room_alerts.triggered_at >= ? AND desk_sla.responded_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, control_room_alerts.triggered_at, desk_sla.responded_at) END)';

        return $query
            ->leftJoin('control_room_alert_sla as desk_sla', 'desk_sla.alert_id', '=', 'control_room_alerts.id')
            ->leftJoin('client_incidents as desk_incident', 'desk_incident.control_room_alert_id', '=', 'control_room_alerts.id')
            ->leftJoin('hs_events as desk_hs', function ($join): void {
                $join->on('desk_hs.control_room_alert_id', '=', 'control_room_alerts.id')
                    ->whereNull('desk_hs.deleted_at');
            })
            ->selectRaw('COUNT(control_room_alerts.id) as total')
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status = 'open' THEN 1 ELSE 0 END) as open_count")
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status = 'ack' THEN 1 ELSE 0 END) as acknowledged_count")
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status = 'triaging' THEN 1 ELSE 0 END) as triaging_count")
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status = 'resolved' THEN 1 ELSE 0 END) as resolved_count")
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status = 'closed' THEN 1 ELSE 0 END) as closed_count")
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status IN ({$active}) AND {$unsnoozed} THEN 1 ELSE 0 END) as active_count", [$now])
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status IN ({$active}) AND {$unsnoozed} AND control_room_alerts.severity = 'critical' THEN 1 ELSE 0 END) as critical_count", [$now])
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status IN ({$active}) AND {$unsnoozed} AND control_room_alerts.severity = 'high' THEN 1 ELSE 0 END) as high_count", [$now])
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status IN ({$active}) AND {$unsnoozed} AND control_room_alerts.escalation_level > 0 THEN 1 ELSE 0 END) as escalated_count", [$now])
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status IN ({$active}) AND {$unsnoozed} AND control_room_alerts.assigned_to_user_id IS NULL THEN 1 ELSE 0 END) as unassigned_count", [$now])
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status IN ({$active}) AND {$unsnoozed} AND control_room_alerts.assigned_to_user_id = ? THEN 1 ELSE 0 END) as my_alerts_count", [$now, $user->id])
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status IN ({$active}) AND {$unsnoozed} AND (desk_sla.acknowledge_breached = 1 OR desk_sla.response_breached = 1 OR desk_sla.resolution_breached = 1 OR (desk_sla.acknowledged_at IS NULL AND desk_sla.acknowledge_deadline < CURRENT_TIMESTAMP) OR (desk_sla.responded_at IS NULL AND desk_sla.response_deadline < CURRENT_TIMESTAMP) OR (desk_sla.resolved_at IS NULL AND desk_sla.resolution_deadline < CURRENT_TIMESTAMP)) THEN 1 ELSE 0 END) as sla_breached_count", [$now])
            ->selectRaw("MIN(CASE WHEN control_room_alerts.status IN ({$active}) AND {$unsnoozed} THEN control_room_alerts.triggered_at END) as oldest_open_at", [$now])
            ->selectRaw('SUM(CASE WHEN control_room_alerts.triggered_at >= ? THEN 1 ELSE 0 END) as alerts_24h', [now()->subDay()])
            ->selectRaw("SUM(CASE WHEN control_room_alerts.triggered_at >= ? AND control_room_alerts.status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as resolved_24h", [now()->subDay()])
            ->selectRaw("{$responseAverage} as avg_response_minutes", [now()->subDay()])
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status IN ({$active}) AND {$unsnoozed} AND desk_incident.id IS NULL THEN 1 ELSE 0 END) as needs_incident", [$now])
            ->selectRaw("SUM(CASE WHEN desk_hs.handover_status = 'awaiting_acceptance' THEN 1 ELSE 0 END) as awaiting_health_safety")
            ->selectRaw("SUM(CASE WHEN desk_hs.handover_status = 'accepted' AND desk_hs.status <> 'closed' THEN 1 ELSE 0 END) as accepted_in_progress")
            ->selectRaw("SUM(CASE WHEN control_room_alerts.status IN ({$terminal}) AND desk_hs.status <> 'closed' THEN 1 ELSE 0 END) as operational_complete_governance_open")
            ->first();
    }

    /** @return array<string, mixed> */
    private function hero(object $aggregate): array
    {
        return [
            'active' => (int) $aggregate->active_count,
            'critical' => (int) $aggregate->critical_count,
            'sla_breached' => (int) $aggregate->sla_breached_count,
            'unassigned' => (int) $aggregate->unassigned_count,
            'oldest_open_at' => $aggregate->oldest_open_at ? Carbon::parse($aggregate->oldest_open_at)->toIso8601String() : null,
            'last_24_hours' => [
                'alerts' => (int) $aggregate->alerts_24h,
                'resolved' => (int) $aggregate->resolved_24h,
                'avg_response_minutes' => $aggregate->avg_response_minutes === null
                    ? null
                    : round((float) $aggregate->avg_response_minutes, 1),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function queues(User $user, ?int $siteId): array
    {
        return TriageQueue::query()
            ->active()
            ->withCount([
                'alerts as active_count' => function (Builder $query) use ($user, $siteId): void {
                    $query->actionable()->notSnoozed();
                    $this->scopeAlerts($query, $user, $siteId);
                },
                'alerts as critical_count' => function (Builder $query) use ($user, $siteId): void {
                    $query->actionable()->notSnoozed()->where('severity', 'critical');
                    $this->scopeAlerts($query, $user, $siteId);
                },
            ])
            ->orderBy('tier')
            ->get(['id', 'name', 'tier'])
            ->map(fn (TriageQueue $queue) => [
                'id' => $queue->id,
                'name' => $queue->name,
                'tier' => (int) $queue->tier,
                'active' => (int) $queue->active_count,
                'critical' => (int) $queue->critical_count,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function activity(User $user, ?int $siteId): array
    {
        $visibleAlertIds = ControlRoomAlert::query()->select('control_room_alerts.id');
        $this->scopeAlerts($visibleAlertIds, $user, $siteId);

        return AuditLog::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.action', 'like', 'controlRoom.%')
            ->where('audit_logs.action', '!=', 'controlRoom.dashboard.view')
            ->where('audit_logs.auditable_type', (new ControlRoomAlert)->getMorphClass())
            ->whereIn('audit_logs.auditable_id', $visibleAlertIds)
            ->orderByDesc('audit_logs.created_at')
            ->limit(10)
            ->get([
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.auditable_id',
                'audit_logs.meta',
                'audit_logs.created_at',
                'users.name as actor_name',
            ])
            ->map(fn ($log) => [
                'id' => $log->id,
                'type' => $log->action,
                'alert_id' => (int) $log->auditable_id,
                'occurred_at' => Carbon::parse($log->created_at)->toIso8601String(),
                'actor_name' => $log->actor_name,
                'meta' => is_array($log->meta) ? $log->meta : json_decode((string) $log->meta, true),
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function legacyStats(object $aggregate): array
    {
        return [
            'total' => (int) $aggregate->total,
            'open' => (int) $aggregate->open_count,
            'acknowledged' => (int) $aggregate->acknowledged_count,
            'triaging' => (int) $aggregate->triaging_count,
            'resolved' => (int) $aggregate->resolved_count,
            'closed' => (int) $aggregate->closed_count,
            'critical' => (int) $aggregate->critical_count,
            'high' => (int) $aggregate->high_count,
            'escalated' => (int) $aggregate->escalated_count,
            'unassigned' => (int) $aggregate->unassigned_count,
            'my_alerts' => (int) $aggregate->my_alerts_count,
        ];
    }

    private function scopeAlerts(Builder $query, User $user, ?int $siteId): void
    {
        $this->siteAccess->applyAlertScope($query, $user, self::BYPASS_PERMISSIONS);
        if ($siteId !== null) {
            $this->siteAccess->applyAlertSiteScopeForSiteIds($query, [$siteId]);
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(string $period): array
    {
        $to = now();
        $from = match ($period) {
            '24h' => $to->copy()->subDay(),
            '30d' => $to->copy()->subDays(30),
            '90d' => $to->copy()->subDays(90),
            default => $to->copy()->subDays(7),
        };

        return [$from, $to];
    }

    private function reportSiteScope(User $user, ?int $siteId): int|array|null
    {
        if ($siteId !== null) {
            return $siteId;
        }
        if ($this->siteAccess->canBypass($user, self::BYPASS_PERMISSIONS)) {
            return null;
        }

        return $this->siteAccess->accessibleSiteIds($user, self::BYPASS_PERMISSIONS);
    }

    /** @param list<string> $allowed */
    private function allowed(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    private function dateFilter(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
