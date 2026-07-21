<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Support\Collection;

class MonitoringOperationsPresenter
{
    private const ROW_LIMIT = 200;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function present(User $viewer, array $filters = []): array
    {
        $visibleDevices = $this->access->visibleDevices($viewer)
            ->with(['assignments' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get();
        $deviceIds = $visibleDevices->pluck('id');
        $siteContext = $this->siteContext($visibleDevices);
        $sitesByDevice = $visibleDevices->mapWithKeys(fn (Device $device): array => [
            $device->id => $this->siteForDevice($device, $siteContext),
        ]);

        $allMonitors = $deviceIds->isEmpty()
            ? collect()
            : Monitor::query()
                ->whereIn('device_id', $deviceIds)
                ->with([
                    'device:id,name,device_uid,domain,category',
                    'profile:id,name,stale_after_seconds,is_active',
                    'collector:id,name,site_id,status,last_seen_at',
                    'collector.site:id,name',
                ])
                ->orderBy('name')
                ->get();
        $observations = $this->recentObservations($allMonitors->pluck('id'));
        $mapped = $allMonitors->map(fn (Monitor $monitor): array => $this->mapMonitor(
            $monitor,
            $sitesByDevice->get($monitor->device_id),
            $observations->get($monitor->id, collect()),
        ));
        $collectionPaths = $this->collectionPaths($allMonitors, $sitesByDevice);
        $monitorFindings = $mapped
            ->where('finding_scope', 'monitor')
            ->whereIn('effective_state', ['failed', 'degraded', 'unknown', 'stale'])
            ->values();

        $enabledActive = $allMonitors->filter(fn (Monitor $monitor): bool => $monitor->is_enabled
            && ($monitor->profile?->is_active ?? true));
        $monitoredDeviceIds = $enabledActive->pluck('device_id')->unique();
        $paused = $mapped->where('effective_state', 'paused')->count();
        $freshness = $mapped->countBy('freshness_state');
        $stateCounts = $mapped->countBy('effective_state');
        $filtered = $this->filterRows($mapped, $filters);
        $shown = $filtered->take(self::ROW_LIMIT)->values();

        return [
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'findings', 'label' => 'Findings'],
                ['key' => 'coverage', 'label' => 'Coverage'],
                ['key' => 'dependencies', 'label' => 'Dependencies'],
                ['key' => 'trends', 'label' => 'Trends'],
                ['key' => 'collection', 'label' => 'Data collection'],
            ],
            'active_tab' => $this->tab($filters['tab'] ?? null),
            'boundary' => [
                'title' => 'Oblivion native monitoring',
                'description' => 'The main application monitors SD-WAN reachable sites directly. Hardened collectors extend coverage only where a remote path requires local collection.',
                'privacy_note' => 'Probe targets, credentials, configuration, raw messages, and observation metrics are never projected into this workspace.',
                'control_room_note' => 'This workspace explains technical evidence and coverage. Control Room remains the operational triage and escalation destination.',
            ],
            'summary' => [
                'total_devices' => $visibleDevices->count(),
                'total_monitors' => $allMonitors->count(),
                'enabled_monitors' => $enabledActive->count(),
                'direct_monitors' => $allMonitors->whereNull('collector_id')->count(),
                'remote_monitors' => $allMonitors->whereNotNull('collector_id')->count(),
                'monitored_devices' => $monitoredDeviceIds->count(),
                'unmonitored_devices' => $visibleDevices->count() - $monitoredDeviceIds->count(),
                'healthy' => (int) ($stateCounts['healthy'] ?? 0),
                'degraded' => (int) ($stateCounts['degraded'] ?? 0),
                'failed' => (int) ($stateCounts['failed'] ?? 0),
                'unknown' => (int) ($stateCounts['unknown'] ?? 0),
                'stale' => (int) ($stateCounts['stale'] ?? 0),
                'pending' => (int) ($stateCounts['pending'] ?? 0),
                'paused' => $paused,
                'collection_paths_unavailable' => $collectionPaths->where('state', 'unavailable')->count(),
                'active_findings' => $monitorFindings->count() + $collectionPaths->where('state', 'unavailable')->count(),
            ],
            'findings' => [
                'monitors' => $monitorFindings,
                'collection_paths' => $collectionPaths->where('state', 'unavailable')->values(),
                'note' => 'A failed collector is one collection-path finding. Its downstream monitor states remain visible as reported evidence but are not counted as independent failures until collection recovers.',
            ],
            'monitors' => $shown,
            'inventory' => [
                'total' => $filtered->count(),
                'shown' => $shown->count(),
                'truncated' => $filtered->count() > self::ROW_LIMIT,
            ],
            'coverage' => [
                'total_devices' => $visibleDevices->count(),
                'monitored_devices' => $monitoredDeviceIds->count(),
                'missing_devices' => $visibleDevices->count() - $monitoredDeviceIds->count(),
                'paused_monitors' => $paused,
                'fresh' => (int) ($freshness['fresh'] ?? 0),
                'stale' => (int) ($freshness['stale'] ?? 0),
                'never_observed' => (int) ($freshness['never'] ?? 0),
                'unsupported_state' => 'not_assessed',
                'unsupported_note' => 'Protocol support is not yet represented by a canonical capability record, so unsupported checks are not inferred.',
                'by_kind' => $mapped->groupBy('kind')->map->count()->sortKeys(),
                'by_site' => $this->coverageBySite($visibleDevices, $mapped, $sitesByDevice),
            ],
            'dependencies' => [
                'canonical_model_available' => false,
                'note' => 'Canonical service-to-service and device dependency records are not available yet. Only explicit collector path dependencies are shown.',
                'collection_paths' => $collectionPaths->values(),
            ],
            'trends' => $mapped
                ->filter(fn (array $row): bool => $row['trend']['samples'] > 0)
                ->map(fn (array $row): array => [
                    'monitor_id' => $row['id'],
                    'monitor_name' => $row['name'],
                    'device' => $row['device'],
                    ...$row['trend'],
                ])
                ->values(),
            'collection' => [
                'direct' => [
                    'label' => 'Main application over site connectivity',
                    'monitors' => $allMonitors->whereNull('collector_id')->count(),
                    'devices' => $allMonitors->whereNull('collector_id')->pluck('device_id')->unique()->count(),
                ],
                'remote_paths' => $collectionPaths->values(),
            ],
            'filters' => [
                'search' => $this->stringFilter($filters['search'] ?? null),
                'state' => $this->stringFilter($filters['state'] ?? null),
                'kind' => $this->stringFilter($filters['kind'] ?? null),
                'site_id' => $this->integerFilter($filters['site_id'] ?? null),
                'device_id' => $this->integerFilter($filters['device_id'] ?? null),
                'collection_mode' => $this->stringFilter($filters['collection_mode'] ?? null),
            ],
            'filter_options' => [
                'states' => $mapped->pluck('effective_state')->unique()->sort()->values(),
                'kinds' => $mapped->pluck('kind')->unique()->sort()->values(),
                'sites' => $sitesByDevice->filter()->unique('id')->sortBy('name')->map(fn (Site $site): array => [
                    'value' => $site->id,
                    'label' => $site->name,
                ])->values(),
                'devices' => $visibleDevices->map(fn (Device $device): array => [
                    'value' => $device->id,
                    'label' => $device->name,
                ])->values(),
            ],
        ];
    }

    /** @param Collection<int, int> $monitorIds @return Collection<int, Collection<int, MonitorObservation>> */
    private function recentObservations(Collection $monitorIds): Collection
    {
        if ($monitorIds->isEmpty()) {
            return collect();
        }

        return MonitorObservation::query()
            ->whereIn('monitor_id', $monitorIds)
            ->orderByDesc('observed_at')
            ->limit(max(1000, $monitorIds->count() * 10))
            ->get(['id', 'monitor_id', 'state', 'value', 'unit', 'latency_ms', 'observed_at'])
            ->groupBy('monitor_id')
            ->map(fn (Collection $rows): Collection => $rows->take(10)->values());
    }

    /** @param Collection<int, MonitorObservation> $observations @return array<string, mixed> */
    private function mapMonitor(Monitor $monitor, ?Site $site, Collection $observations): array
    {
        $reported = $monitor->current_state?->value ?? 'unknown';
        $collectorAvailable = $monitor->collector_id === null || $this->collectorState($monitor->collector) === 'available';
        $suppressed = $monitor->suppressed_until?->isFuture() ?? false;
        $paused = ! $monitor->is_enabled || ! ($monitor->profile?->is_active ?? true);
        $effective = $paused
            ? 'paused'
            : ($suppressed
                ? 'suppressed'
                : ($collectorAvailable ? $reported : 'collection_unavailable'));
        $freshness = $this->freshness($monitor);
        if ($effective !== 'collection_unavailable' && $freshness === 'stale' && ! in_array($effective, ['paused', 'suppressed'], true)) {
            $effective = 'stale';
        }
        $latest = $observations->first();
        $previous = $observations->skip(1)->first();
        $states = $observations->pluck('state')->map(fn ($state): string => $state?->value ?? (string) $state)->values();
        $stateChanges = $states->zip($states->skip(1))->filter(fn (Collection $pair): bool => $pair->count() === 2 && $pair[0] !== $pair[1])->count();

        return [
            'id' => $monitor->id,
            'name' => $monitor->name,
            'kind' => $monitor->kind?->value ?? (string) $monitor->kind,
            'reported_state' => $reported,
            'effective_state' => $effective,
            'finding_scope' => $effective === 'collection_unavailable' ? 'collection_path' : 'monitor',
            'affects_availability' => (bool) $monitor->affects_availability,
            'enabled' => (bool) $monitor->is_enabled,
            'operational' => (bool) $monitor->is_enabled && ($monitor->profile?->is_active ?? true),
            'suppressed_until' => $monitor->suppressed_until?->toIso8601String(),
            'last_observation_at' => $monitor->last_observation_at?->toIso8601String(),
            'last_state_changed_at' => $monitor->last_state_changed_at?->toIso8601String(),
            'freshness_state' => $freshness,
            'device' => [
                'id' => $monitor->device_id,
                'name' => $monitor->device?->name,
                'href' => "/security-devices/devices/{$monitor->device_id}",
                'domain' => $monitor->device?->domain,
                'category' => $monitor->device?->category,
            ],
            'site' => $site ? [
                'id' => $site->id,
                'name' => $site->name,
                'href' => "/security-devices/sites/{$site->id}",
            ] : null,
            'collection' => $monitor->collector ? [
                'mode' => 'remote_collector',
                'collector_id' => $monitor->collector->id,
                'collector_name' => $monitor->collector->name,
                'state' => $this->collectorState($monitor->collector),
                'last_seen_at' => $monitor->collector->last_seen_at?->toIso8601String(),
            ] : [
                'mode' => 'direct',
                'label' => 'Main application over site connectivity',
                'state' => 'available',
            ],
            'latest_observation' => $latest ? [
                'state' => $latest->state?->value ?? (string) $latest->state,
                'value' => $latest->value,
                'unit' => $latest->unit,
                'latency_ms' => $latest->latency_ms,
                'observed_at' => $latest->observed_at?->toIso8601String(),
            ] : null,
            'trend' => [
                'samples' => $observations->count(),
                'latest_value' => $latest?->value,
                'previous_value' => $previous?->value,
                'unit' => $latest?->unit,
                'direction' => $this->direction($latest?->value, $previous?->value),
                'state_changes' => $stateChanges,
                'retained_from' => $observations->last()?->observed_at?->toIso8601String(),
                'retained_to' => $latest?->observed_at?->toIso8601String(),
            ],
        ];
    }

    /** @param Collection<int, Monitor> $monitors @param Collection<int, Site|null> $sitesByDevice */
    private function collectionPaths(Collection $monitors, Collection $sitesByDevice): Collection
    {
        return $monitors
            ->whereNotNull('collector_id')
            ->groupBy('collector_id')
            ->map(function (Collection $rows) use ($sitesByDevice): array {
                /** @var Monitor $first */
                $first = $rows->first();
                $collector = $first->collector;
                $site = $collector?->site ?? $sitesByDevice->get($first->device_id);

                return [
                    'collector_id' => $collector?->id,
                    'collector_name' => $collector?->name ?? 'Collector record unavailable',
                    'state' => $this->collectorState($collector),
                    'reported_status' => $collector?->status ?? 'unknown',
                    'last_seen_at' => $collector?->last_seen_at?->toIso8601String(),
                    'heartbeat_lag_seconds' => $collector?->last_seen_at?->diffInSeconds(now()),
                    'site' => $site ? [
                        'id' => $site->id,
                        'name' => $site->name,
                        'href' => "/security-devices/sites/{$site->id}",
                    ] : null,
                    'affected_monitors' => $rows->count(),
                    'affected_devices' => $rows->pluck('device_id')->unique()->count(),
                ];
            })
            ->values();
    }

    private function collectorState(?MonitoringCollector $collector): string
    {
        if (! $collector || $collector->status !== 'online' || ! $collector->last_seen_at || $collector->last_seen_at->lt(now()->subMinutes(5))) {
            return 'unavailable';
        }

        return 'available';
    }

    private function freshness(Monitor $monitor): string
    {
        if (! $monitor->last_observation_at) {
            return 'never';
        }

        $staleAfter = max(30, (int) ($monitor->profile?->stale_after_seconds ?? 300));

        return $monitor->last_observation_at->lt(now()->subSeconds($staleAfter)) ? 'stale' : 'fresh';
    }

    private function direction(mixed $latest, mixed $previous): string
    {
        if ($latest === null || $previous === null || ! is_numeric($latest) || ! is_numeric($previous)) {
            return 'not_available';
        }

        return (float) $latest <=> (float) $previous
            ? ((float) $latest > (float) $previous ? 'up' : 'down')
            : 'steady';
    }

    /** @param Collection<int, array<string, mixed>> $rows @param array<string, mixed> $filters */
    private function filterRows(Collection $rows, array $filters): Collection
    {
        $search = mb_strtolower($this->stringFilter($filters['search'] ?? null) ?? '');
        $state = $this->stringFilter($filters['state'] ?? null);
        $kind = $this->stringFilter($filters['kind'] ?? null);
        $siteId = $this->integerFilter($filters['site_id'] ?? null);
        $deviceId = $this->integerFilter($filters['device_id'] ?? null);
        $mode = $this->stringFilter($filters['collection_mode'] ?? null);

        return $rows->filter(function (array $row) use ($search, $state, $kind, $siteId, $deviceId, $mode): bool {
            return ($search === '' || str_contains(mb_strtolower(implode(' ', [
                $row['name'],
                $row['device']['name'],
                $row['site']['name'] ?? '',
                $row['kind'],
            ])), $search))
                && (! $state || $row['effective_state'] === $state)
                && (! $kind || $row['kind'] === $kind)
                && (! $siteId || ($row['site']['id'] ?? null) === $siteId)
                && (! $deviceId || $row['device']['id'] === $deviceId)
                && (! $mode || $row['collection']['mode'] === $mode);
        })->values();
    }

    /** @param Collection<int, Device> $devices @param Collection<int, array<string, mixed>> $rows @param Collection<int, Site|null> $sitesByDevice */
    private function coverageBySite(Collection $devices, Collection $rows, Collection $sitesByDevice): Collection
    {
        return $devices
            ->groupBy(fn (Device $device): string => (string) ($sitesByDevice->get($device->id)?->id ?? 'unassigned'))
            ->map(function (Collection $siteDevices) use ($rows, $sitesByDevice): array {
                $first = $siteDevices->first();
                $site = $sitesByDevice->get($first->id);
                $deviceIds = $siteDevices->pluck('id');
                $siteRows = $rows->whereIn('device.id', $deviceIds);
                $monitored = $siteRows->where('operational', true)->pluck('device.id')->unique()->count();

                return [
                    'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
                    'devices' => $siteDevices->count(),
                    'monitored_devices' => $monitored,
                    'missing_devices' => $siteDevices->count() - $monitored,
                ];
            })
            ->values();
    }

    /** @param Collection<int, Device> $devices @return array<string, Collection> */
    private function siteContext(Collection $devices): array
    {
        $assignments = $devices->flatMap->assignments;
        $siteIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_SITE)->pluck('assignable_id')->unique();
        $roomIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_ROOM)->pluck('assignable_id')->unique();

        return [
            'sites' => $siteIds->isEmpty() ? collect() : Site::query()->whereIn('id', $siteIds)->get(['id', 'name'])->keyBy('id'),
            'rooms' => $roomIds->isEmpty() ? collect() : SiteRoom::query()->whereIn('id', $roomIds)->with('site:id,name')->get()->keyBy('id'),
        ];
    }

    /** @param array<string, Collection> $context */
    private function siteForDevice(Device $device, array $context): ?Site
    {
        $assignment = $device->assignments->first(fn (DeviceAssignment $candidate): bool => in_array(
            $candidate->assignable_type,
            [DeviceAssignment::TARGET_SITE, DeviceAssignment::TARGET_ROOM],
            true,
        ));

        if (! $assignment) {
            return null;
        }

        return $assignment->assignable_type === DeviceAssignment::TARGET_SITE
            ? $context['sites']->get($assignment->assignable_id)
            : $context['rooms']->get($assignment->assignable_id)?->site;
    }

    private function tab(mixed $value): string
    {
        $tab = $this->stringFilter($value) ?? 'overview';

        return in_array($tab, ['overview', 'findings', 'coverage', 'dependencies', 'trends', 'collection'], true)
            ? $tab
            : 'overview';
    }

    private function stringFilter(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && $value !== 'all' ? trim($value) : null;
    }

    private function integerFilter(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
    }
}
