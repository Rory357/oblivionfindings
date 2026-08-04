<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MetricCurrentSummary;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringExternalHeartbeatState;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Models\MonitoringRuntimeHeartbeat;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

final class MonitoringRuntimeHealthService
{
    /** @var array<string, list<string>> */
    private const QUEUE_STREAMS = [
        'events' => ['event'],
        'checks' => ['observation'],
        'discovery' => [],
        'provider' => [],
        'topology' => ['projection'],
        'maintenance' => ['configuration'],
        'orchestration' => [],
        'commands' => [],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly ListenerHeartbeatReporter $listeners,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $canViewAllSites = $this->access->canViewAllSites($viewer);
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $visibleDeviceIds = $this->access->visibleDevices($viewer)->select('devices.id');
        $collectors = MonitoringCollector::query()
            ->when(! $canViewAllSites, fn (Builder $query) => $siteIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('site_id', $siteIds))
            ->get([
                'id', 'status', 'runtime_state', 'backlog_items', 'gap_count',
                'corrupted_frames', 'last_clock_drift_seconds', 'last_heartbeat_at',
                'last_seen_at', 'revoked_at',
            ]);
        $collectorStaleAfter = (int) config('monitoring.collector.heartbeat_stale_seconds', 180);
        $collectorRows = $collectors->map(function (MonitoringCollector $collector) use ($collectorStaleAfter): array {
            $lastHeartbeat = $collector->last_heartbeat_at ?? $collector->last_seen_at;
            $heartbeatAge = $lastHeartbeat?->diffInSeconds(now());
            $state = match (true) {
                $collector->revoked_at !== null => 'revoked',
                $lastHeartbeat === null => 'not_observed',
                $heartbeatAge > $collectorStaleAfter => 'unavailable',
                (int) $collector->gap_count > 0 || (int) $collector->corrupted_frames > 0 => 'degraded',
                default => 'available',
            };

            return [
                'state' => $state,
                'heartbeat_age_seconds' => $heartbeatAge,
                'backlog_items' => (int) $collector->backlog_items,
                'gap_count' => (int) $collector->gap_count,
                'corrupted_frames' => (int) $collector->corrupted_frames,
                'clock_drift_seconds' => $collector->last_clock_drift_seconds === null
                    ? null
                    : (int) $collector->last_clock_drift_seconds,
            ];
        });

        $workers = collect($this->workerStates());

        $queues = collect(self::QUEUE_STREAMS)->mapWithKeys(function (array $streams, string $key) use ($canViewAllSites, $workers): array {
            $worker = $workers->get($key, [
                'state' => 'not_observed',
                'heartbeat_age_seconds' => null,
                'dispatch_lag_seconds' => null,
            ]);

            if (! $canViewAllSites) {
                return [$key => [
                    'state' => 'scope_restricted',
                    'pending' => null,
                    'oldest_age_seconds' => null,
                    'dead_letters' => null,
                    'worker_state' => $worker['state'],
                    'heartbeat_age_seconds' => $worker['heartbeat_age_seconds'],
                    'dispatch_lag_seconds' => $worker['dispatch_lag_seconds'],
                ]];
            }

            if ($key === 'commands') {
                $outbox = DeviceCommandRequest::query()->whereIn('status', [
                    CommandStatus::Queued->value,
                    CommandStatus::Dispatching->value,
                    CommandStatus::Reconciling->value,
                ]);
                $pending = (clone $outbox)->count();
                $oldest = (clone $outbox)->min('updated_at');
                $deadLetters = 0;
            } else {
                $outbox = MonitoringOutbox::query()->whereNull('published_at');
                if ($streams === []) {
                    $outbox->whereRaw('1 = 0');
                } else {
                    $outbox->whereIn('stream', $streams);
                }
                $pending = (clone $outbox)->count();
                $oldest = (clone $outbox)->min('available_at');
                $deadLetters = MonitoringDeadLetter::query()
                    ->whereNull('resolved_at')
                    ->when($streams === [], fn (Builder $query) => $query->whereRaw('1 = 0'))
                    ->when($streams !== [], fn (Builder $query) => $query->whereIn('consumer', $this->consumersForStreams($streams)))
                    ->count();
            }

            $state = match (true) {
                in_array($worker['state'], ['stale', 'unavailable'], true) => 'worker_unavailable',
                $worker['state'] === 'not_observed' => 'worker_not_observed',
                $deadLetters > 0 => 'attention',
                $pending > 0 => 'backlog',
                default => 'clear',
            };

            return [$key => [
                'state' => $state,
                'pending' => $pending,
                'oldest_age_seconds' => $oldest === null ? null : Carbon::parse($oldest)->diffInSeconds(now()),
                'dead_letters' => $deadLetters,
                'worker_state' => $worker['state'],
                'heartbeat_age_seconds' => $worker['heartbeat_age_seconds'],
                'dispatch_lag_seconds' => $worker['dispatch_lag_seconds'],
            ]];
        })->all();

        $metricSeries = MetricSeries::query()->whereIn('device_id', $visibleDeviceIds);
        $seriesCount = (clone $metricSeries)->count();
        $summaryStates = MetricCurrentSummary::query()
            ->whereIn('series_id', (clone $metricSeries)->select('id'))
            ->selectRaw('storage_state, COUNT(*) aggregate')
            ->groupBy('storage_state')
            ->pluck('aggregate', 'storage_state');
        $timeSeriesState = match (true) {
            (int) ($summaryStates['unavailable'] ?? 0) > 0 => 'unavailable',
            (int) ($summaryStates['missing'] ?? 0) > 0 => 'degraded',
            $seriesCount > 0 && (int) ($summaryStates['available'] ?? 0) === $seriesCount => 'available',
            $seriesCount > 0 => 'unknown',
            blank(config('monitoring.storage.timeseries.url')) => 'not_configured',
            default => 'not_observed',
        };

        $snapshots = ConfigurationSnapshot::query()->whereIn('device_id', $visibleDeviceIds);
        $snapshotCount = (clone $snapshots)->count();
        $availableSnapshots = (clone $snapshots)->where('storage_state', 'available')->count();
        $snapshotState = match (true) {
            $snapshotCount === 0 && blank(config('monitoring.storage.snapshots.disk')) => 'not_configured',
            $snapshotCount === 0 => 'not_observed',
            $availableSnapshots === $snapshotCount => 'available',
            $availableSnapshots > 0 => 'degraded',
            default => 'unavailable',
        };

        $listenerRows = collect(['snmp_traps', 'syslog', 'flow'])->mapWithKeys(function (string $listener): array {
            try {
                $snapshot = $this->listeners->snapshot($listener);
                $lastSeen = isset($snapshot['last_seen_at']) && is_string($snapshot['last_seen_at'])
                    ? Carbon::parse($snapshot['last_seen_at'])
                    : null;

                return [$listener => [
                    'state' => $lastSeen === null ? 'not_observed' : ($lastSeen->lt(now()->subMinutes(5)) ? 'stale' : 'available'),
                    'heartbeat_age_seconds' => $lastSeen?->diffInSeconds(now()),
                ]];
            } catch (Throwable) {
                return [$listener => ['state' => 'unavailable', 'heartbeat_age_seconds' => null]];
            }
        })->all();

        $externalHeartbeatEnabled = (bool) config('monitoring.external_heartbeat.enabled', false);
        $externalHeartbeatRecord = MonitoringExternalHeartbeatState::query()
            ->where('key', MonitoringExternalHeartbeatState::KEY_CENTRAL_RUNTIME)
            ->first();
        $lastExternalHeartbeatAge = $externalHeartbeatRecord?->last_sent_at?->diffInSeconds(now());
        $externalHeartbeatStaleAfter = max(60, (int) config('monitoring.external_heartbeat.stale_seconds', 180));
        $externalHeartbeatState = match (true) {
            ! $externalHeartbeatEnabled => 'disabled',
            $externalHeartbeatRecord === null => 'not_observed',
            $externalHeartbeatRecord->state === MonitoringExternalHeartbeatState::STATE_SENT
                && ($lastExternalHeartbeatAge === null || $lastExternalHeartbeatAge > $externalHeartbeatStaleAfter) => 'stale',
            default => $externalHeartbeatRecord->state,
        };
        $externalHeartbeat = [
            'state' => $externalHeartbeatState,
            'reason_code' => in_array($externalHeartbeatState, ['suppressed', 'failed'], true)
                ? $externalHeartbeatRecord?->reason_code
                : null,
            'last_sent_age_seconds' => $lastExternalHeartbeatAge,
            'last_evaluated_age_seconds' => $externalHeartbeatRecord?->last_evaluated_at?->diffInSeconds(now()),
            'note' => match ($externalHeartbeatState) {
                'sent' => 'The independent dead-man monitor received a current central runtime heartbeat.',
                'suppressed' => 'Heartbeat withheld because the central monitoring runtime is not fully ready.',
                'failed' => 'The central runtime was ready but the independent heartbeat delivery failed.',
                'stale' => 'No current independent heartbeat delivery has been recorded.',
                'not_observed' => 'External heartbeat is configured but has not been evaluated yet.',
                default => 'Configure an independently hosted dead-man monitor for total outage detection.',
            },
        ];

        $queueAttention = collect($queues)->contains(fn (array $queue): bool => in_array($queue['state'], [
            'attention', 'backlog', 'worker_unavailable', 'worker_not_observed',
        ], true));
        $workerAttention = $workers->contains(fn (array $worker): bool => $worker['state'] !== 'available');
        $collectorAttention = $collectorRows->contains(fn (array $collector): bool => in_array($collector['state'], ['unavailable', 'degraded'], true));
        $storageAttention = in_array($timeSeriesState, ['unavailable', 'degraded'], true)
            || in_array($snapshotState, ['unavailable', 'degraded'], true);
        $externalHeartbeatAttention = $externalHeartbeatEnabled && $externalHeartbeatState !== 'sent';

        return [
            'state' => ($queueAttention || $workerAttention || $collectorAttention || $storageAttention || $externalHeartbeatAttention)
                ? 'attention'
                : 'operational',
            'workers' => [
                'state' => $workers->every(fn (array $worker): bool => $worker['state'] === 'available')
                    ? 'available'
                    : ($workers->every(fn (array $worker): bool => $worker['state'] === 'not_observed') ? 'not_observed' : 'attention'),
                'available' => $workers->where('state', 'available')->count(),
                'total' => $workers->count(),
                'attention' => $workers->whereIn('state', ['stale', 'unavailable'])->count(),
                'not_observed' => $workers->where('state', 'not_observed')->count(),
                'note' => match (true) {
                    $workers->every(fn (array $worker): bool => $worker['state'] === 'available') => 'Every isolated runtime worker consumed a current heartbeat.',
                    $workers->every(fn (array $worker): bool => $worker['state'] === 'not_observed') => 'No runtime heartbeat has been consumed; check the scheduler and Supervisor workers.',
                    default => 'One or more isolated runtime workers need attention.',
                },
            ],
            'queues' => $queues,
            'listeners' => $listenerRows,
            'external_heartbeat' => $externalHeartbeat,
            'storage' => [
                'time_series' => [
                    'state' => $timeSeriesState,
                    'series' => $seriesCount,
                    'available' => (int) ($summaryStates['available'] ?? 0),
                    'missing' => (int) ($summaryStates['missing'] ?? 0),
                    'unavailable' => (int) ($summaryStates['unavailable'] ?? 0),
                ],
                'snapshots' => [
                    'state' => $snapshotState,
                    'records' => $snapshotCount,
                    'available' => $availableSnapshots,
                ],
            ],
            'collectors' => [
                'total' => $collectorRows->count(),
                'available' => $collectorRows->where('state', 'available')->count(),
                'degraded' => $collectorRows->where('state', 'degraded')->count(),
                'unavailable' => $collectorRows->where('state', 'unavailable')->count(),
                'revoked' => $collectorRows->where('state', 'revoked')->count(),
                'backlog_items' => $collectorRows->sum('backlog_items'),
                'gaps' => $collectorRows->sum('gap_count'),
            ],
            'observed_at' => now()->utc()->toIso8601String(),
        ];
    }

    /** @param list<string> $streams @return list<string> */
    private function consumersForStreams(array $streams): array
    {
        $configured = (array) config('monitoring.delivery.consumers', []);

        return collect($streams)
            ->map(fn (string $stream): mixed => $configured[$stream] ?? null)
            ->filter(fn (mixed $consumer): bool => is_string($consumer) && $consumer !== '')
            ->values()
            ->all();
    }

    /** @return array<string, array{state: string, heartbeat_age_seconds: ?int, dispatch_lag_seconds: ?int}> */
    public function workerStates(): array
    {
        $workerStaleAfter = max(60, (int) config('monitoring.runtime.worker_heartbeat_stale_seconds', 180));
        $heartbeatByComponent = MonitoringRuntimeHeartbeat::query()
            ->whereIn('component', array_keys(self::QUEUE_STREAMS))
            ->get()
            ->keyBy('component');

        return collect(self::QUEUE_STREAMS)->mapWithKeys(function (array $_streams, string $component) use ($heartbeatByComponent, $workerStaleAfter): array {
            $heartbeat = $heartbeatByComponent->get($component);

            return [$component => $this->workerState(
                $heartbeat instanceof MonitoringRuntimeHeartbeat ? $heartbeat : null,
                $workerStaleAfter,
            )];
        })->all();
    }

    /** @return array{state: string, heartbeat_age_seconds: ?int, dispatch_lag_seconds: ?int} */
    private function workerState(?MonitoringRuntimeHeartbeat $heartbeat, int $staleAfter): array
    {
        if ($heartbeat === null) {
            return [
                'state' => 'not_observed',
                'heartbeat_age_seconds' => null,
                'dispatch_lag_seconds' => null,
            ];
        }

        if ($heartbeat->last_consumed_at === null || $heartbeat->last_consumed_dispatch_at === null) {
            $firstDispatchAge = (int) $heartbeat->created_at->diffInSeconds(now());

            return [
                'state' => $firstDispatchAge > $staleAfter ? 'unavailable' : 'not_observed',
                'heartbeat_age_seconds' => null,
                'dispatch_lag_seconds' => $firstDispatchAge,
            ];
        }

        $heartbeatAge = (int) $heartbeat->last_consumed_at->diffInSeconds(now());
        $dispatchLag = (int) $heartbeat->last_consumed_dispatch_at->diffInSeconds(now());

        return [
            'state' => max($heartbeatAge, $dispatchLag) > $staleAfter ? 'stale' : 'available',
            'heartbeat_age_seconds' => $heartbeatAge,
            'dispatch_lag_seconds' => $dispatchLag,
        ];
    }
}
