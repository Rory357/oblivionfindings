<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Monitoring\Models\MetricCurrentSummary;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CapacityProjectionService;
use App\Domain\Monitoring\Services\CentralSiteMonitoringReadinessService;
use App\Domain\Monitoring\Services\MonitoringCollectorAvailabilityService;
use App\Domain\Monitoring\Services\MonitoringReplayService;
use App\Domain\Monitoring\Services\MonitoringRuntimeHealthService;
use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

class MonitoringOperationsPresenter
{
    private const ROW_LIMIT = 200;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly MonitoringRuntimeHealthService $runtimeHealth,
        private readonly CentralSiteMonitoringReadinessService $centralReadiness,
        private readonly CapacityProjectionService $capacityProjection,
        private readonly ItWorkAccessService $itAccess,
        private readonly MonitoringReplayService $replay,
        private readonly MonitoringCollectorAvailabilityService $collectorAvailability,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function present(User $viewer, array $filters = []): array
    {
        $visibleDevices = $this->access->visibleDevices($viewer)
            ->with(['assignments' => fn ($query) => $query->active()->where('assigned_at', '<=', now())])
            ->orderBy('name')
            ->get();
        $deviceIds = $visibleDevices->pluck('id');
        $siteContext = $this->siteContext($visibleDevices);
        $sitesByDevice = $visibleDevices->mapWithKeys(fn (Device $device): array => [
            $device->id => $this->siteForDevice($device, $siteContext),
        ]);
        $accessibleSiteIds = $this->access->accessibleSiteIds($viewer);
        $canManageNativeMonitors = $viewer->canDo('securityDevices.monitoring.manage');
        $accessibleSites = $accessibleSiteIds === []
            ? collect()
            : Site::query()
                ->whereIn('id', $accessibleSiteIds)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']);

        $allMonitors = $deviceIds->isEmpty()
            ? collect()
            : Monitor::query()
                ->whereIn('device_id', $deviceIds)
                ->with([
                    'device:id,name,device_uid,domain,category',
                    'profile:id,name,stale_after_seconds,is_active',
                    'collector:id,name,site_id,status,last_seen_at,last_heartbeat_at,revoked_at',
                    'collector.site:id,name',
                    'rootCauseMonitor:id,name,device_id',
                ])
                ->orderBy('name')
                ->get();
        $observations = $this->recentObservations($allMonitors->pluck('id'));
        $correlations = $this->correlations($allMonitors, $sitesByDevice, $viewer);
        $mapped = $allMonitors->map(fn (Monitor $monitor): array => $this->mapMonitor(
            $monitor,
            $sitesByDevice->get($monitor->device_id),
            $observations->get($monitor->id, collect()),
            $correlations->get($monitor->id),
            $canManageNativeMonitors,
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
        $dependencies = $this->dependencies($allMonitors, $mapped);
        $storage = $this->storage($deviceIds);
        $runtime = $this->runtimeHealth->present($viewer);
        $directSiteReadiness = $this->centralReadiness->assess(
            $accessibleSites,
            $visibleDevices,
            $allMonitors,
            $sitesByDevice,
            $runtime['queues'],
        );

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
                'collection_paths_unavailable' => $collectionPaths->where('state', '!=', 'available')->count(),
                'active_findings' => $monitorFindings->count() + $collectionPaths->where('state', '!=', 'available')->count(),
            ],
            'findings' => [
                'monitors' => $monitorFindings,
                'collection_paths' => $collectionPaths->where('state', '!=', 'available')->values(),
                'note' => 'A failed collector is one collection-path finding. Its downstream monitor states remain visible as reported evidence but are not counted as independent failures until collection recovers.',
            ],
            'monitors' => $shown,
            'monitor_management' => [
                'can_manage' => $canManageNativeMonitors,
                'create_url' => $canManageNativeMonitors
                    ? '/security-devices/monitoring/native-monitors'
                    : null,
                'kinds' => $canManageNativeMonitors
                    ? NativeMonitoringDefinitionService::directKindOptions()
                    : [],
                'profiles' => $canManageNativeMonitors
                    ? MonitoringProfile::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn (MonitoringProfile $profile): array => [
                            'id' => (int) $profile->id,
                            'name' => $profile->name,
                        ])
                        ->values()
                        ->all()
                    : [],
                'devices' => $canManageNativeMonitors
                    ? $visibleDevices
                        ->filter(fn (Device $device): bool => $sitesByDevice->get($device->id) instanceof Site)
                        ->map(function (Device $device) use ($sitesByDevice): array {
                            $site = $sitesByDevice->get($device->id);

                            return [
                                'id' => (int) $device->id,
                                'name' => $device->name,
                                'site' => ['id' => (int) $site->id, 'name' => $site->name],
                            ];
                        })
                        ->values()
                        ->all()
                    : [],
            ],
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
                'unsupported_state' => 'evidence_backed',
                'unsupported_note' => 'Coverage is classified from enabled monitors and governed expectations. Missing checks remain visible instead of being inferred from vendor or model names.',
                'by_kind' => $mapped->groupBy('kind')->map->count()->sortKeys(),
                'by_site' => $this->coverageBySite($visibleDevices, $mapped, $sitesByDevice),
            ],
            'dependencies' => $dependencies + ['collection_paths' => $collectionPaths->values()],
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
                'direct_sites' => $directSiteReadiness,
                'remote_paths' => $collectionPaths->values(),
            ],
            'runtime' => $runtime,
            'delivery' => $this->delivery($viewer),
            'storage' => $storage,
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

    /** @return array<string, mixed> */
    private function delivery(User $viewer): array
    {
        $payloads = collect((array) config('monitoring.contracts.payloads', []))
            ->map(fn (mixed $contract): array => [
                'current' => (int) data_get($contract, 'current', 1),
                'accepted' => array_values(array_map('intval', (array) data_get($contract, 'accepted', [1]))),
            ])
            ->all();
        $commands = (array) config('monitoring.contracts.commands', []);
        $canOperate = $viewer->canDo('securityDevices.integrations.manage');

        if (! $canOperate) {
            return [
                'contracts' => [
                    'envelope_current' => (int) config('monitoring.contracts.current', 1),
                    'envelope_accepted' => array_values(array_map('intval', (array) config('monitoring.contracts.accepted', [1]))),
                    'payloads' => $payloads,
                    'commands' => $commands,
                ],
                'dead_letters' => [
                    'visible' => false,
                    'total' => null,
                    'shown' => 0,
                    'truncated' => false,
                    'rows' => [],
                    'note' => 'Dead-letter evidence and recovery actions require integration management permission.',
                ],
            ];
        }

        $siteIds = $this->access->accessibleSiteIds($viewer);
        $letters = MonitoringDeadLetter::query()
            ->whereNull('resolved_at')
            ->where(function ($query) use ($siteIds): void {
                $query->whereNull('site_id');
                if ($siteIds !== []) {
                    $query->orWhereIn('site_id', $siteIds);
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ROW_LIMIT + 1)
            ->get();
        $sites = Site::query()
            ->whereKey($letters->pluck('site_id')->filter()->unique())
            ->get(['id', 'name'])
            ->keyBy('id');
        $rows = $letters->map(function (MonitoringDeadLetter $letter) use ($viewer, $sites): ?array {
            $inspection = $this->replay->inspect($viewer, $letter);
            if ($inspection === null) {
                return null;
            }
            $site = $letter->site_id === null ? null : $sites->get($letter->site_id);

            return [
                'id' => (int) $letter->id,
                'reason_code' => $letter->reason_code,
                'reason_message' => $letter->reason_message,
                'consumer' => $letter->consumer,
                'message_reference' => substr($letter->message_id, -8),
                'site' => $site ? [
                    'id' => (int) $site->id,
                    'name' => $site->name,
                    'href' => "/security-devices/sites/{$site->id}",
                ] : null,
                'replay_count' => (int) $letter->replay_count,
                'created_at' => $letter->created_at?->toIso8601String(),
                ...$inspection,
            ];
        })->filter()->values();
        $truncated = $letters->count() > self::ROW_LIMIT || $rows->count() > self::ROW_LIMIT;
        $shown = $rows->take(self::ROW_LIMIT)->values();

        return [
            'contracts' => [
                'envelope_current' => (int) config('monitoring.contracts.current', 1),
                'envelope_accepted' => array_values(array_map('intval', (array) config('monitoring.contracts.accepted', [1]))),
                'payloads' => $payloads,
                'commands' => $commands,
            ],
            'dead_letters' => [
                'visible' => true,
                'total' => $shown->count(),
                'shown' => $shown->count(),
                'truncated' => $truncated,
                'rows' => $shown->all(),
                'note' => $truncated
                    ? 'Showing the latest 200 permission-scoped failures. Exact signed bytes remain immutable and private.'
                    : 'Only safe metadata is shown. Exact signed bytes remain immutable and private.',
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
    private function mapMonitor(
        Monitor $monitor,
        ?Site $site,
        Collection $observations,
        ?array $correlation,
        bool $canManage,
    ): array {
        $nativeDirect = $monitor->collector_id === null
            && in_array(
                $monitor->kind?->value ?? (string) $monitor->kind,
                NativeMonitoringDefinitionService::directKindValues(),
                true,
            );
        $reported = $monitor->current_state?->value ?? 'unknown';
        $collectorAvailable = $monitor->collector_id === null || $this->collectorState($monitor->collector) === 'available';
        $policyEffective = $monitor->effective_state?->value ?? $reported;
        $suppressed = $policyEffective === 'suppressed' || ($monitor->suppressed_until?->isFuture() ?? false);
        $paused = ! $monitor->is_enabled || ! ($monitor->profile?->is_active ?? true);
        $effective = $paused
            ? 'paused'
                : ($suppressed
                ? 'suppressed'
                : ($collectorAvailable ? $policyEffective : 'collection_unavailable'));
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
            'profile' => $monitor->profile ? [
                'id' => (int) $monitor->profile->id,
                'name' => $monitor->profile->name,
            ] : null,
            'suppressed_until' => $monitor->suppressed_until?->toIso8601String(),
            'suppression_reason' => $monitor->suppression_reason,
            'root_cause' => $monitor->rootCauseMonitor ? [
                'monitor_id' => $monitor->rootCauseMonitor->id,
                'monitor_name' => $monitor->rootCauseMonitor->name,
                'device_id' => $monitor->rootCauseMonitor->device_id,
                'href' => "/security-devices/devices/{$monitor->rootCauseMonitor->device_id}",
            ] : null,
            'correlation' => $correlation,
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
                'last_seen_at' => $this->collectorAvailability->lastHeartbeat($monitor->collector)?->toIso8601String(),
            ] : [
                'mode' => 'direct',
                'label' => 'Main application over site connectivity',
                'state' => 'available',
            ],
            'actions' => [
                'can_manage' => $canManage && $nativeDirect,
                'update_url' => $canManage && $nativeDirect
                    ? "/security-devices/monitoring/native-monitors/{$monitor->id}"
                    : null,
                'deactivate_url' => $canManage && $nativeDirect && $monitor->is_enabled
                    ? "/security-devices/monitoring/native-monitors/{$monitor->id}/deactivate"
                    : null,
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

    /** @param Collection<int, Monitor> $monitors @param Collection<int, Site|null> $sitesByDevice @return Collection<int, array<string, mixed>> */
    private function correlations(Collection $monitors, Collection $sitesByDevice, User $viewer): Collection
    {
        $canViewControlRoom = $viewer->canDo('controlRoom.viewAny') || $viewer->canDo('controlRoom.alerts.view');
        $canViewIt = $viewer->canDo('it.view') || $viewer->canDo('it.manage');
        if ((! $canViewControlRoom && ! $canViewIt) || $monitors->isEmpty()) {
            return collect();
        }

        $byId = $monitors->keyBy('id');
        $keysByRoot = $monitors
            ->map(fn (Monitor $monitor): int => (int) ($monitor->root_cause_monitor_id ?? $monitor->id))
            ->unique()
            ->mapWithKeys(function (int $rootId) use ($byId, $sitesByDevice): array {
                /** @var Monitor|null $root */
                $root = $byId->get($rootId);
                $site = $root ? $sitesByDevice->get($root->device_id) : null;
                if (! $root || ! $site) {
                    return [];
                }

                return [$rootId => hash('sha256', "site:{$site->id}:device:{$root->device_id}:root:{$rootId}:condition:availability")];
            });
        if ($keysByRoot->isEmpty()) {
            return collect();
        }

        $signals = Signal::query()
            ->whereIn('normalized_data->monitor_correlation_key', $keysByRoot->values())
            ->where(fn ($query) => $query->whereNotNull('alert_id')->orWhereNotNull('correlated_alert_id'))
            ->orderByDesc('id')
            ->get(['id', 'alert_id', 'correlated_alert_id', 'normalized_data'])
            ->unique(fn (Signal $signal): ?string => data_get($signal->normalized_data, 'monitor_correlation_key'))
            ->keyBy(fn (Signal $signal): ?string => data_get($signal->normalized_data, 'monitor_correlation_key'));
        $alertIds = $signals
            ->map(fn (Signal $signal): ?int => $signal->alert_id ?? $signal->correlated_alert_id)
            ->filter()
            ->unique()
            ->values();
        if ($alertIds->isEmpty()) {
            return collect();
        }

        $alerts = $canViewControlRoom
            ? ControlRoomAlert::query()->whereKey($alertIds)->get(['id', 'reference_number', 'status'])->keyBy('id')
            : collect();
        $tickets = collect();
        if ($canViewIt) {
            $tickets = $this->itAccess
                ->applyViewScope(ItTicket::query(), $viewer)
                ->whereHas('links', fn ($links) => $links
                    ->where('relationship', 'source_alert')
                    ->where('linkable_type', (new ControlRoomAlert)->getMorphClass())
                    ->whereIn('linkable_id', $alertIds))
                ->with(['links' => fn ($links) => $links
                    ->where('relationship', 'source_alert')
                    ->where('linkable_type', (new ControlRoomAlert)->getMorphClass())
                    ->whereIn('linkable_id', $alertIds)])
                ->get(['id', 'reference', 'title', 'status', 'monitoring_recovered_at'])
                ->reduce(function (Collection $indexed, ItTicket $ticket): Collection {
                    foreach ($ticket->links as $link) {
                        $indexed->put((int) $link->linkable_id, $ticket);
                    }

                    return $indexed;
                }, collect());
        }

        return $monitors->mapWithKeys(function (Monitor $monitor) use ($alerts, $keysByRoot, $signals, $tickets): array {
            $rootId = (int) ($monitor->root_cause_monitor_id ?? $monitor->id);
            $key = $keysByRoot->get($rootId);
            /** @var Signal|null $signal */
            $signal = $key ? $signals->get($key) : null;
            $alertId = $signal?->alert_id ?? $signal?->correlated_alert_id;
            /** @var ControlRoomAlert|null $alert */
            $alert = $alertId ? $alerts->get($alertId) : null;
            /** @var ItTicket|null $ticket */
            $ticket = $alertId ? $tickets->get($alertId) : null;

            return [$monitor->id => [
                'control_room' => $alert ? [
                    'id' => $alert->id,
                    'reference' => $alert->reference_number,
                    'status' => $alert->status,
                    'href' => "/control-room/alerts/{$alert->id}",
                ] : null,
                'it_incident' => $ticket ? [
                    'id' => $ticket->id,
                    'reference' => $ticket->reference,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'monitoring_recovered_at' => $ticket->monitoring_recovered_at?->toIso8601String(),
                    'href' => "/it/tickets/{$ticket->id}",
                ] : null,
            ]];
        });
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
                $lastHeartbeat = $this->collectorAvailability->lastHeartbeat($collector);

                return [
                    'collector_id' => $collector?->id,
                    'collector_name' => $collector?->name ?? 'Collector record unavailable',
                    'state' => $this->collectorState($collector),
                    'reported_status' => $collector?->status ?? 'unknown',
                    'last_seen_at' => $lastHeartbeat?->toIso8601String(),
                    'heartbeat_lag_seconds' => $lastHeartbeat?->diffInSeconds(now()),
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

    /** @param Collection<int, Monitor> $monitors @param Collection<int, array<string, mixed>> $mapped @return array<string, mixed> */
    private function dependencies(Collection $monitors, Collection $mapped): array
    {
        $monitorIds = $monitors->pluck('id');
        if ($monitorIds->isEmpty()) {
            return [
                'canonical_model_available' => true,
                'records' => [],
                'suppressed_symptoms' => 0,
                'note' => 'No dependency records exist in the visible monitoring scope.',
            ];
        }

        $rows = MonitorDependency::query()
            ->where('is_active', true)
            ->whereIn('upstream_monitor_id', $monitorIds)
            ->whereIn('downstream_monitor_id', $monitorIds)
            ->with([
                'site:id,name',
                'upstreamMonitor:id,name,device_id,current_state,effective_state',
                'downstreamMonitor:id,name,device_id,current_state,effective_state,root_cause_monitor_id,suppression_reason',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (MonitorDependency $dependency): array => [
                'id' => $dependency->id,
                'policy' => $dependency->policy,
                'source' => $dependency->source,
                'confidence' => (float) $dependency->confidence,
                'review_state' => $dependency->source === 'manual' ? 'reviewed' : 'inferred',
                'site' => $dependency->site ? [
                    'id' => $dependency->site->id,
                    'name' => $dependency->site->name,
                    'href' => "/security-devices/sites/{$dependency->site->id}",
                ] : null,
                'upstream' => [
                    'id' => $dependency->upstreamMonitor?->id,
                    'name' => $dependency->upstreamMonitor?->name,
                    'state' => $dependency->upstreamMonitor?->effective_state?->value,
                    'device_href' => $dependency->upstreamMonitor
                        ? "/security-devices/devices/{$dependency->upstreamMonitor->device_id}"
                        : null,
                ],
                'downstream' => [
                    'id' => $dependency->downstreamMonitor?->id,
                    'name' => $dependency->downstreamMonitor?->name,
                    'state' => $dependency->downstreamMonitor?->effective_state?->value,
                    'suppression_reason' => $dependency->downstreamMonitor?->suppression_reason,
                    'device_href' => $dependency->downstreamMonitor
                        ? "/security-devices/devices/{$dependency->downstreamMonitor->device_id}"
                        : null,
                ],
            ])
            ->values();

        return [
            'canonical_model_available' => true,
            'records' => $rows,
            'suppressed_symptoms' => $mapped->where('effective_state', 'suppressed')->count(),
            'note' => 'Evidence-backed dependencies identify root causes and keep suppressed symptoms inspectable without duplicating findings.',
        ];
    }

    /** @param Collection<int, int> $deviceIds @return array<string, mixed> */
    private function storage(Collection $deviceIds): array
    {
        $series = $deviceIds->isEmpty()
            ? collect()
            : MetricSeries::query()
                ->whereIn('device_id', $deviceIds)
                ->with(['currentSummary', 'device:id,name'])
                ->orderByDesc('last_point_at')
                ->limit(self::ROW_LIMIT)
                ->get();
        $siteIds = $series->pluck('site_id')->filter()->unique()->values();
        $states = $series->map(fn (MetricSeries $metric): string => $metric->currentSummary?->storage_state ?? 'unknown')->countBy();
        $state = match (true) {
            (int) ($states['unavailable'] ?? 0) > 0 => 'unavailable',
            (int) ($states['missing'] ?? 0) > 0 => 'degraded',
            $series->isNotEmpty() && (int) ($states['available'] ?? 0) === $series->count() => 'available',
            $series->isNotEmpty() => 'unknown',
            blank(config('monitoring.storage.timeseries.url')) => 'not_configured',
            default => 'not_observed',
        };
        $policies = MonitoringRetentionPolicy::query()
            ->where('is_active', true)
            ->where(function ($query) use ($deviceIds, $siteIds): void {
                $query->where('scope_kind', 'application');
                if ($siteIds->isNotEmpty()) {
                    $query->orWhereIn('site_id', $siteIds);
                }
                if ($deviceIds->isNotEmpty()) {
                    $query->orWhereIn('device_id', $deviceIds);
                }
            })
            ->orderBy('id')
            ->get()
            ->map(fn (MonitoringRetentionPolicy $policy): array => [
                'id' => $policy->id,
                'name' => $policy->name,
                'scope' => $policy->scope_kind,
                'data_class' => $policy->data_class,
                'privacy_class' => $policy->privacy_class,
                'raw_days' => $policy->raw_days,
                'hourly_days' => $policy->hourly_days,
                'daily_days' => $policy->daily_days,
                'legal_hold' => $policy->legal_hold,
            ])
            ->values();

        return [
            'time_series' => [
                'state' => $state,
                'series' => $series->count(),
                'available' => (int) ($states['available'] ?? 0),
                'missing' => (int) ($states['missing'] ?? 0),
                'unavailable' => (int) ($states['unavailable'] ?? 0),
                'capacity_evidence' => $series->map(function (MetricSeries $metric): array {
                    /** @var MetricCurrentSummary|null $summary */
                    $summary = $metric->currentSummary;

                    return [
                        'series_id' => $metric->id,
                        'metric' => $metric->metric,
                        'unit' => $metric->unit,
                        'tier' => $metric->retention_tier,
                        'device' => [
                            'id' => $metric->device_id,
                            'name' => $metric->device?->name,
                            'href' => "/security-devices/devices/{$metric->device_id}",
                        ],
                        'value' => $summary?->value,
                        'p95' => data_get($summary?->statistics, 'p95'),
                        'sample_count' => $summary?->sample_count ?? 0,
                        'observed_at' => $summary?->observed_at?->toIso8601String(),
                        'storage_state' => $summary?->storage_state ?? 'unknown',
                        'projection' => $this->capacityProjection($metric),
                    ];
                })->values(),
            ],
            'retention' => [
                'policies' => $policies,
                'explanation' => 'The most restrictive matching application, Site, Device, data-class, and privacy policy applies. Legal hold preserves matching evidence.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function capacityProjection(MetricSeries $series): array
    {
        if (! in_array($series->unit, ['percent', 'percentage', '%'], true)) {
            return [
                'state' => 'not_applicable',
                'threshold' => null,
                'measured_from' => null,
                'measured_to' => null,
                'sample_count' => 0,
                'p95' => null,
                'slope_per_day' => null,
                'confidence' => 0,
                'threshold_at' => null,
            ];
        }

        if (blank(config('monitoring.storage.timeseries.url'))) {
            return [
                'state' => 'not_configured',
                'threshold' => 90.0,
                'measured_from' => null,
                'measured_to' => null,
                'sample_count' => 0,
                'p95' => null,
                'slope_per_day' => null,
                'confidence' => 0,
                'threshold_at' => null,
            ];
        }

        try {
            $projection = $this->capacityProjection->project($series, 90.0);

            return [
                'state' => $projection->state,
                'threshold' => $projection->threshold,
                'measured_from' => $projection->measuredFrom?->toIso8601String(),
                'measured_to' => $projection->measuredTo?->toIso8601String(),
                'sample_count' => $projection->sampleCount,
                'p95' => $projection->p95,
                'slope_per_day' => $projection->slopePerDay,
                'confidence' => $projection->confidence,
                'threshold_at' => $projection->thresholdAt?->toIso8601String(),
            ];
        } catch (Throwable) {
            return [
                'state' => 'unavailable',
                'threshold' => 90.0,
                'measured_from' => null,
                'measured_to' => null,
                'sample_count' => 0,
                'p95' => null,
                'slope_per_day' => null,
                'confidence' => 0,
                'threshold_at' => null,
            ];
        }
    }

    private function collectorState(?MonitoringCollector $collector): string
    {
        return $this->collectorAvailability->state($collector);
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
        $directSiteIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_SITE)->pluck('assignable_id')->unique();
        $roomIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_ROOM)->pluck('assignable_id')->unique();
        $clientIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_CLIENT)->pluck('assignable_id')->unique();
        $staffIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_STAFF)->pluck('assignable_id')->unique();
        $vehicleIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_VEHICLE)->pluck('assignable_id')->unique();

        $roomSiteIds = $roomIds->isEmpty()
            ? collect()
            : SiteRoom::query()->whereIn('id', $roomIds)->pluck('site_id', 'id');
        $clientSiteIds = $clientIds->isEmpty()
            ? collect()
            : Client::query()
                ->whereIn('id', $clientIds)
                ->where('status', 'active')
                ->whereNotNull('site_id')
                ->pluck('site_id', 'id');
        $staffSiteIds = $staffIds->isEmpty()
            ? collect()
            : HrEmployeeProfile::query()
                ->whereIn('user_id', $staffIds)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
                ->whereNotNull('primary_site_id')
                ->get(['user_id', 'primary_site_id'])
                ->groupBy('user_id')
                ->map(function (Collection $profiles): ?int {
                    $siteIds = $profiles->pluck('primary_site_id')->map(fn (mixed $id): int => (int) $id)->unique();

                    return $siteIds->count() === 1 ? $siteIds->first() : null;
                })
                ->filter();
        $vehicleSiteIds = $vehicleIds->isEmpty()
            ? collect()
            : Asset::query()
                ->whereIn('id', $vehicleIds)
                ->where('status', 'active')
                ->with(['categoryRef:id,slug', 'client:id,site_id,status'])
                ->get(['id', 'category', 'asset_category_id', 'site_id', 'home_site_id', 'client_id'])
                ->filter(fn (Asset $asset): bool => strcasecmp((string) $asset->category, 'vehicle') === 0
                    || $asset->categoryRef?->slug === 'vehicle')
                ->mapWithKeys(function (Asset $asset): array {
                    $siteIds = collect([
                        $asset->site_id,
                        $asset->home_site_id,
                        $asset->client?->status === 'active' ? $asset->client?->site_id : null,
                    ])->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                        ->map(fn (mixed $id): int => (int) $id)
                        ->unique();

                    return $siteIds->count() === 1 ? [$asset->id => $siteIds->first()] : [];
                });
        $siteIds = collect([
            $directSiteIds,
            $roomSiteIds->values(),
            $clientSiteIds->values(),
            $staffSiteIds->values(),
            $vehicleSiteIds->values(),
        ])->flatten()->filter()->map(fn (mixed $id): int => (int) $id)->unique();

        return [
            'sites' => $siteIds->isEmpty()
                ? collect()
                : Site::query()
                    ->whereIn('id', $siteIds)
                    ->where('is_active', true)
                    ->where('archived', false)
                    ->whereNull('archived_at')
                    ->get(['id', 'name'])
                    ->keyBy('id'),
            'rooms' => $roomSiteIds,
            'clients' => $clientSiteIds,
            'staff' => $staffSiteIds,
            'vehicles' => $vehicleSiteIds,
        ];
    }

    /** @param array<string, Collection> $context */
    private function siteForDevice(Device $device, array $context): ?Site
    {
        $status = $device->status instanceof DeviceStatus ? $device->status->value : (string) $device->status;
        if (! in_array($status, [
            DeviceStatus::Active->value,
            DeviceStatus::Degraded->value,
            DeviceStatus::Offline->value,
        ], true) || $device->assignments->isEmpty()) {
            return null;
        }

        $siteIds = $device->assignments->map(fn (DeviceAssignment $assignment): ?int => match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => (int) $assignment->assignable_id,
            DeviceAssignment::TARGET_ROOM => $context['rooms']->get($assignment->assignable_id),
            DeviceAssignment::TARGET_CLIENT => $context['clients']->get($assignment->assignable_id),
            DeviceAssignment::TARGET_STAFF => $context['staff']->get($assignment->assignable_id),
            DeviceAssignment::TARGET_VEHICLE => $context['vehicles']->get($assignment->assignable_id),
            default => null,
        });
        if ($siteIds->contains(null)) {
            return null;
        }

        $siteIds = $siteIds->map(fn (mixed $id): int => (int) $id)->unique();

        return $siteIds->count() === 1 ? $context['sites']->get($siteIds->first()) : null;
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
