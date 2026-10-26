<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\SecurityDevices\Data\NetworkMapFilters;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Presenters\EndpointDevicePosturePresenter;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class NetworkItWorkspacePresenter
{
    private const DEVICE_LIMIT = 100;

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly TopologyWorkspacePresenter $topologyPresenter,
        private readonly ?ConfigurationSnapshotService $snapshots = null,
        private readonly ?DeclaredDeviceCommandCapabilities $declaredCapabilities = null,
        private readonly ?CommandExecutionRouteResolver $executionRoutes = null,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, Builder $networkScope, array $activeTab, ?Cursor $topologyCursor = null, ?NetworkMapFilters $mapFilters = null, ?Collection $mapSites = null): array
    {
        $includeHistory = $activeTab['key'] === 'map';
        $loadedScope = (clone $networkScope)
            ->with([
                'assignments' => fn ($query) => $query
                    ->active()
                    ->where('assigned_at', '<=', now())
                    ->orderBy('id'),
                'activeAssetLinks' => fn ($query) => $query->orderBy('id'),
                'monitors' => fn ($query) => $query
                    ->with('collector:id,name,status,last_seen_at')
                    ->orderBy('name'),
                'configurationSnapshots' => fn ($query) => $query
                    ->latest('captured_at')
                    ->latest('id')
                    ->limit(12),
            ]);
        $mapInventory = null;
        $exploration = null;
        if ($includeHistory && $mapFilters?->anchorId !== null) {
            $exploration = (new NetworkMapExplorationQuery($this->siteResolver, $this->topologyPresenter))->page(clone $networkScope, $mapFilters, $mapSites ?? collect());
            $mapFilters = $exploration->filters;
            $devices = $loadedScope->whereKey($exploration->deviceIds)->reorder('devices.id')->get();
            $inventoryTruncated = true;
        } elseif ($includeHistory) {
            [$devices, $mapInventory, $mapFilters] = (new NetworkMapInventoryQuery)->page($loadedScope, $mapFilters ?? new NetworkMapFilters, $mapSites ?? collect());
            $inventoryTruncated = $mapInventory['isPartial'];
        } else {
            $candidates = $loadedScope->orderBy('name')->limit(self::DEVICE_LIMIT + 1)->get();
            $inventoryTruncated = $candidates->count() > self::DEVICE_LIMIT;
            $devices = $candidates->take(self::DEVICE_LIMIT)->values();
        }
        $deviceIds = $devices->pluck('id');
        $canonicalSiteIds = $devices->mapWithKeys(function (Device $device): array {
            try {
                return [$device->id => $this->siteResolver->resolveLoadedForContext($device)];
            } catch (Throwable) {
                return [$device->id => null];
            }
        });
        if ($exploration !== null) {
            $siteId = $exploration->metadata['siteId'];
            if ($canonicalSiteIds->get($exploration->metadata['anchorId']) !== $siteId) {
                throw (new ModelNotFoundException)->setModel(Device::class);
            }
            $devices = $devices->filter(fn (Device $device): bool => $canonicalSiteIds->get($device->id) === $siteId)->values();
            $deviceIds = $devices->pluck('id');
            $canonicalSiteIds = $canonicalSiteIds->intersectByKeys($deviceIds->flip());
        }
        $monitorIds = $devices->flatMap->monitors->pluck('id');
        $latestObservations = $this->latestObservations($viewer, $monitorIds);
        $siteContext = $this->siteContext($devices);
        $maintenanceWindows = $this->maintenanceWindows($canonicalSiteIds->filter()->unique()->values());
        $computerIds = $devices
            ->filter(fn (Device $device): bool => EndpointDevicePosturePresenter::isComputer($device))
            ->pluck('id');
        $endpointAgents = $computerIds->isNotEmpty() && Schema::hasTable('endpoint_agents')
            ? EndpointAgent::query()
                ->whereIn('device_id', $computerIds)
                ->get([
                    'id',
                    'device_id',
                    'site_id',
                    'assignment_fingerprint',
                    'enrolled_at',
                    'enrolment_expires_at',
                    'revoked_at',
                    'last_seen_at',
                    'observed_at',
                    'agent_version',
                ])
                ->keyBy('device_id')
            : collect();
        $mappedDevices = $devices
            ->map(fn (Device $device): array => $this->mapDevice(
                $device,
                $siteContext,
                $maintenanceWindows,
                $canonicalSiteIds->get($device->id),
                $endpointAgents->get($device->id),
            ))
            ->values();
        $relationships = $exploration !== null ? ($exploration->runtime ? collect() : DeviceRelationship::query()->active()->whereKey($exploration->edgeIds)->whereIn('parent_device_id', $deviceIds)->whereIn('child_device_id', $deviceIds)->orderBy('id')->get()) : $this->relationships($deviceIds);
        $topologySiteIds = $canonicalSiteIds;
        $siteMismatch = false;
        if ($includeHistory && $mapFilters?->siteId !== null) {
            $siteMismatch = $canonicalSiteIds->contains(fn ($siteId): bool => $siteId !== $mapFilters->siteId);
            $topologySiteIds = $canonicalSiteIds->map(fn ($siteId) => $siteId === $mapFilters->siteId ? $siteId : null);
            $relationshipIds = $topologySiteIds->filter()->keys();
            $relationships = $relationships->filter(fn ($relationship): bool => $relationshipIds->contains($relationship->parent_device_id) && $relationshipIds->contains($relationship->child_device_id))->values();
        }
        $topology = $this->topologyPresenter->present($mappedDevices, $topologySiteIds, $includeHistory && $exploration === null, $includeHistory ? $topologyCursor : null, $mapFilters, $exploration)
            ?? $this->topology($mappedDevices, $relationships, $includeHistory && $topologyCursor !== null, $mapFilters);
        $topology['nodes'] = collect($topology['nodes'])->map(function (array $node) use ($canonicalSiteIds): array {
            $siteId = $canonicalSiteIds->get($node['id']);
            $node['exploreHref'] = $siteId === null ? null : NetworkMapFilters::exploreHref($node['id'], $siteId);

            return $node;
        })->values();
        if ($exploration !== null) {
            $topology['exploration'] = [...$exploration->metadata, 'neighborsShown' => max(0, $devices->count() - 1)];
            $topology['state'] = 'partial';
            $topology['label'] = 'Showing immediate connections from retained evidence';
        }
        if ($mapInventory !== null) {
            $mapInventory['isPartial'] = $mapInventory['isPartial'] || $siteMismatch;
            $topology['inventory'] = $mapInventory;
            if ($mapInventory['isPartial']) {
                $topology['state'] = 'partial';
                $topology['label'] = 'Showing topology evidence for a limited device view';
            }
        }
        $interfaces = $this->interfaces($devices, $latestObservations, $viewer, $canonicalSiteIds);
        $services = $this->services($devices, $relationships, $maintenanceWindows, $canonicalSiteIds);
        $traffic = $interfaces
            ->filter(fn (array $row): bool => $row['observedAt'] !== null
                && collect([
                    $row['inBps'],
                    $row['outBps'],
                    $row['inUtilisation'],
                    $row['outUtilisation'],
                ])->contains(fn ($value): bool => $value !== null))
            ->map(fn (array $row): array => [
                'monitorId' => $row['monitorId'],
                'deviceId' => $row['deviceId'],
                'deviceName' => $row['deviceName'],
                'deviceHref' => $row['deviceHref'],
                'interface' => $row['name'],
                'speedBps' => $row['speedBps'],
                'inBps' => $row['inBps'],
                'outBps' => $row['outBps'],
                'inUtilisation' => $row['inUtilisation'],
                'outUtilisation' => $row['outUtilisation'],
                'state' => $row['capacityState'],
                'observedAt' => $row['observedAt'],
                'source' => 'retained_native_observation',
            ])
            ->values();
        $configuration = $devices
            ->map(fn (Device $device): array => $this->configuration(
                $device,
                $viewer,
                $canonicalSiteIds->get($device->id),
            ))
            ->values();
        $permissions = [
            'viewItWork' => $viewer->canDo('it.view'),
        ];
        $ticketLinks = $permissions['viewItWork']
            ? $this->ticketLinks($viewer, $deviceIds)
            : collect();
        $gaps = $this->gaps($devices, $interfaces, $services, $traffic, $configuration);
        $overview = $this->overview(
            $mappedDevices,
            $devices,
            $interfaces,
            $traffic,
            $configuration,
            $topology,
            $ticketLinks,
            $permissions,
        );

        $activeDevices = in_array($activeTab['key'], ['overview', 'devices'], true)
            ? $mappedDevices
            : collect();
        $activeInterfaces = $activeTab['key'] === 'interfaces' ? $interfaces : collect();
        $activeServices = $activeTab['key'] === 'services' ? $services : collect();
        $activeTraffic = $activeTab['key'] === 'traffic-capacity' ? $traffic : collect();
        $activeConfiguration = $activeTab['key'] === 'configuration-firmware'
            ? $configuration
            : collect();
        $inventoryTotal = match ($activeTab['key']) {
            'map' => $exploration !== null ? $devices->count() : $mapInventory['total'],
            'interfaces' => $interfaces->count(),
            'services' => $services->count(),
            'traffic-capacity' => $traffic->count(),
            'configuration-firmware' => $configuration->count(),
            default => $mappedDevices->count(),
        };

        return [
            'permissions' => $permissions,
            'boundary' => [
                'title' => 'Native monitoring, honest evidence',
                'description' => 'Oblivion Findings presents canonical devices, retained native observations, and known relationship evidence without depending on a provider-shaped runtime or navigation model.',
                'collectionNote' => 'Missing discovery, protocol, interface, capacity, configuration, or firmware collection stays visible as not collected, not observed, partial, or unsupported.',
                'managementNote' => 'Configuration and firmware evidence here is read-only. Changes are requested from the Management tab, where each one is approved before it runs and then checked against fresh evidence.',
            ],
            'overview' => $overview,
            'activeTab' => [
                'key' => $activeTab['key'],
                'label' => $activeTab['label'],
                'description' => $activeTab['description'],
                'inventoryTotal' => $inventoryTotal,
                'inventoryShown' => match ($activeTab['key']) {
                    'map' => $devices->count(),
                    'interfaces' => $activeInterfaces->count(),
                    'services' => $activeServices->count(),
                    'traffic-capacity' => $activeTraffic->count(),
                    'configuration-firmware' => $activeConfiguration->count(),
                    default => $activeDevices->count(),
                },
                'inventoryTruncated' => $inventoryTruncated,
                'devices' => $activeDevices,
                'topology' => $topology,
                'interfaces' => $activeInterfaces,
                'services' => $activeServices,
                'traffic' => $activeTraffic,
                'configuration' => $activeConfiguration,
                'gaps' => $gaps,
            ],
        ];
    }

    /** @param Collection<int, int> $monitorIds @return Collection<int, MonitorObservation> */
    private function latestObservations(User $viewer, Collection $monitorIds): Collection
    {
        if ($monitorIds->isEmpty()) {
            return collect();
        }

        $ids = MonitorObservation::query()
            ->whereIn('monitor_id', $monitorIds)
            ->selectRaw('MAX(id)')
            ->groupBy('monitor_id');

        return MonitorObservation::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('monitor_id');
    }

    /** @param Collection<int, Device> $devices @return array<string, Collection> */
    private function siteContext(Collection $devices): array
    {
        $assignments = $devices->flatMap->assignments;
        $siteIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
            ->pluck('assignable_id')
            ->unique();
        $roomIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
            ->pluck('assignable_id')
            ->unique();
        $sites = $siteIds->isEmpty()
            ? collect()
            : Site::query()->whereIn('id', $siteIds)->get(['id', 'name'])->keyBy('id');
        $rooms = $roomIds->isEmpty()
            ? collect()
            : SiteRoom::query()->whereIn('id', $roomIds)->with('site:id,name')->get()->keyBy('id');

        return compact('sites', 'rooms');
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

    /** @param array<string, Collection> $context @return array<string, mixed> */
    private function mapDevice(
        Device $device,
        array $context,
        Collection $maintenanceWindows = new Collection,
        ?int $canonicalSiteId = null,
        ?EndpointAgent $endpointAgent = null,
    ): array {
        $site = $this->siteForDevice($device, $context);
        $enabledMonitors = $device->monitors->where('is_enabled', true);

        return [
            'id' => $device->id,
            'name' => $device->name,
            'category' => $device->category,
            'subcategory' => $device->subcategory,
            'status' => $device->status?->value,
            'health' => $device->health_status?->value,
            'lastSeenAt' => $device->last_seen_at?->toISOString(),
            'href' => "/security-devices/devices/{$device->id}",
            'site' => $site ? [
                'id' => $site->id,
                'name' => $site->name,
                'href' => "/security-devices/sites/{$site->id}",
            ] : null,
            'identifiers' => [
                'ipAddress' => $device->ip_address,
                'macAddress' => $device->mac_address,
                'serialNumber' => $device->serial_number,
            ],
            'firmwareVersion' => $device->firmware_version,
            'monitoring' => [
                'enabled' => $enabledMonitors->count(),
                'attention' => $enabledMonitors->whereIn('current_state', [
                    MonitorState::Failed,
                    MonitorState::Degraded,
                ])->count(),
                'uncertain' => $enabledMonitors->whereIn('current_state', [
                    MonitorState::Unknown,
                    MonitorState::Stale,
                    MonitorState::Pending,
                ])->count(),
            ],
            'maintenance' => $this->maintenanceFor($maintenanceWindows, $canonicalSiteId, (int) $device->id, null),
            'endpointAgent' => EndpointDevicePosturePresenter::isComputer($device)
                ? EndpointDevicePosturePresenter::agentBadge($endpointAgent, $canonicalSiteId, null, true)
                : null,
            'wanPath' => $this->isWanDevice($device),
        ];
    }

    private function isWanDevice(Device $device): bool
    {
        return Str::contains(
            Str::lower(implode(' ', [
                $device->name,
                $device->category,
                $device->subcategory,
            ])),
            ['wan', 'sd-wan', 'sd_wan', 'router', 'gateway', 'firewall', 'edge'],
        );
    }

    /** @param Collection<int, int> $deviceIds @return Collection<int, DeviceRelationship> */
    private function relationships(Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty()) {
            return collect();
        }

        return DeviceRelationship::query()
            ->active()
            ->whereIn('parent_device_id', $deviceIds)
            ->whereIn('child_device_id', $deviceIds)
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function topology(Collection $devices, Collection $relationships, bool $positionReset = false, ?NetworkMapFilters $mapFilters = null): array
    {
        $names = $devices->keyBy('id');
        $linkedIds = $relationships
            ->flatMap(fn (DeviceRelationship $relationship): array => [
                (int) $relationship->parent_device_id,
                (int) $relationship->child_device_id,
            ])
            ->unique();
        $unlinkedCount = $devices->pluck('id')->diff($linkedIds)->count();
        [$state, $label] = match (true) {
            $devices->isEmpty() => ['no_evidence', 'No network devices are currently visible'],
            $relationships->isEmpty() => ['no_evidence', 'No topology relationships have been recorded'],
            $unlinkedCount === 0 => ['known', 'Known topology evidence covers every visible device'],
            default => ['partial', 'Known topology is incomplete'],
        };

        return [
            'source' => 'manual_relationships',
            'state' => $state,
            'label' => $label,
            'nodeCount' => $devices->count(),
            'edgeCount' => $relationships->count(),
            'unlinkedCount' => $unlinkedCount,
            'nodes' => $devices->map(fn (array $device): array => [
                'id' => $device['id'],
                'name' => $device['name'],
                'category' => $device['category'],
                'subcategory' => $device['subcategory'],
                'health' => $device['health'],
                'healthBasis' => 'current_device_state',
                'currentState' => true,
                'siteId' => $device['site']['id'] ?? null,
                'site' => $device['site']['name'] ?? null,
                'href' => $device['href'],
            ])->values(),
            'edges' => $relationships->map(function (DeviceRelationship $relationship) use ($names): array {
                $parent = $names->get($relationship->parent_device_id);
                $child = $names->get($relationship->child_device_id);

                return [
                    'id' => $relationship->id,
                    'parentId' => $relationship->parent_device_id,
                    'parentName' => $parent['name'] ?? "Device #{$relationship->parent_device_id}",
                    'childId' => $relationship->child_device_id,
                    'childName' => $child['name'] ?? "Device #{$relationship->child_device_id}",
                    'type' => $relationship->relationship_type?->value,
                    'label' => $relationship->relationship_type?->label() ?? 'Related to',
                    'port' => $relationship->port,
                    'source' => 'manual',
                    'confidence' => 1.0,
                    'reviewState' => 'reviewed',
                    'evidenceLabel' => 'Reviewed canonical Device relationship',
                ];
            })->values(),
            'snapshots' => [],
            'visibleDeviceCount' => $devices->count(),
            'history' => TopologyWorkspacePresenter::emptyHistory($positionReset, $mapFilters),
            'changes' => ['added' => 0, 'removed' => 0, 'changed' => 0],
        ];
    }

    /** @param Collection<int, Device> $devices @param Collection<int, MonitorObservation> $observations */
    private function interfaces(
        Collection $devices,
        Collection $observations,
        User $viewer,
        Collection $canonicalSiteIds,
    ): Collection {
        $canManageCommands = $viewer->canDo('securityDevices.commands.manage');
        $canControlCommands = $viewer->canDo('securityDevices.commands.control');
        $declared = $this->declaredCapabilities ?? app(DeclaredDeviceCommandCapabilities::class);
        $routes = $this->executionRoutes ?? app(CommandExecutionRouteResolver::class);

        return $devices->flatMap(function (Device $device) use (
            $observations,
            $canonicalSiteIds,
            $canManageCommands,
            $canControlCommands,
            $declared,
            $routes,
        ): Collection {
            $siteId = $canonicalSiteIds->get($device->id);
            $supportsBounce = $canManageCommands
                && $siteId !== null
                && $declared->supports($device, 'network.port.bounce')
                && $routes->resolve($device, (int) $siteId, 'network.port.bounce')->available;
            $supportsEnable = $canManageCommands
                && $siteId !== null
                && $declared->supports($device, 'network.port.enable')
                && $routes->resolve($device, (int) $siteId, 'network.port.enable')->available;
            $supportsDisable = $canControlCommands
                && $siteId !== null
                && $declared->supports($device, 'network.port.disable')
                && $routes->resolve($device, (int) $siteId, 'network.port.disable')->available;

            return $device->monitors
                ->where('kind', MonitorKind::SnmpInterface)
                ->map(function (Monitor $monitor) use ($device, $observations, $supportsBounce, $supportsEnable, $supportsDisable): array {
                    $observation = $observations->get($monitor->id);
                    $metrics = $observation?->metrics ?? [];
                    $inUtilisation = $this->floatMetric($metrics, ['in_utilization_pct', 'in_utilisation_pct']);
                    $outUtilisation = $this->floatMetric($metrics, ['out_utilization_pct', 'out_utilisation_pct']);
                    $maximum = collect([$inUtilisation, $outUtilisation])->filter(fn ($value) => $value !== null)->max();
                    $interfaceName = $this->stringMetric($metrics, ['interface_name', 'if_name']) ?: $monitor->name;
                    $encodedInterface = rawurlencode($interfaceName);

                    return [
                        'monitorId' => $monitor->id,
                        'deviceId' => $device->id,
                        'deviceName' => $device->name,
                        'deviceHref' => "/security-devices/devices/{$device->id}",
                        'name' => $interfaceName,
                        'index' => $this->intMetric($metrics, ['if_index', 'interface_index']),
                        'state' => $monitor->current_state?->value,
                        'enabled' => (bool) $monitor->is_enabled,
                        'adminStatus' => $this->stringMetric($metrics, ['admin_status']),
                        'operationalStatus' => $this->stringMetric($metrics, ['operational_status', 'oper_status']),
                        'speedBps' => $this->intMetric($metrics, ['speed_bps', 'interface_speed_bps']),
                        'inBps' => $this->intMetric($metrics, ['in_bps', 'inbound_bps']),
                        'outBps' => $this->intMetric($metrics, ['out_bps', 'outbound_bps']),
                        'inUtilisation' => $inUtilisation,
                        'outUtilisation' => $outUtilisation,
                        'errors' => $this->intMetric($metrics, ['errors', 'error_count']),
                        'discards' => $this->intMetric($metrics, ['discards', 'discard_count']),
                        'capacityState' => $maximum === null
                            ? 'not_collected'
                            : ($maximum >= 90 ? 'critical' : ($maximum >= 80 ? 'warning' : 'normal')),
                        'observedAt' => $observation?->observed_at?->toISOString(),
                        'portActions' => ($supportsBounce || $supportsEnable || $supportsDisable) ? [
                            'bounceHref' => $supportsBounce
                                ? "/security-devices/devices/{$device->id}?section=management&action=network.port.bounce&command_interface={$encodedInterface}"
                                : null,
                            'enableHref' => $supportsEnable
                                ? "/security-devices/devices/{$device->id}?section=management&action=network.port.enable&command_interface={$encodedInterface}"
                                : null,
                            'disableHref' => $supportsDisable
                                ? "/security-devices/devices/{$device->id}?section=management&action=network.port.disable&command_interface={$encodedInterface}"
                                : null,
                        ] : null,
                    ];
                });
        })->values();
    }

    /** @param Collection<int, Device> $devices @param Collection<int, DeviceRelationship> $relationships */
    private function services(
        Collection $devices,
        Collection $relationships,
        Collection $maintenanceWindows,
        Collection $canonicalSiteIds,
    ): Collection {
        return $devices->flatMap(function (Device $device) use ($relationships, $maintenanceWindows, $canonicalSiteIds): Collection {
            $dependants = $relationships->filter(fn (DeviceRelationship $relationship): bool => (int) $relationship->parent_device_id === (int) $device->id
                || (int) $relationship->child_device_id === (int) $device->id)->count();
            $siteId = $canonicalSiteIds->get($device->id);

            return $device->monitors
                ->reject(fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::SnmpInterface)
                ->map(fn (Monitor $monitor): array => [
                    'id' => $monitor->id,
                    'deviceId' => $device->id,
                    'deviceName' => $device->name,
                    'deviceHref' => "/security-devices/devices/{$device->id}",
                    'name' => $monitor->name,
                    'kind' => $monitor->kind?->value,
                    'kindLabel' => $this->monitorKindLabel($monitor->kind),
                    'state' => $monitor->current_state?->value,
                    'enabled' => (bool) $monitor->is_enabled,
                    'affectsAvailability' => (bool) $monitor->affects_availability,
                    'lastObservationAt' => $monitor->last_observation_at?->toISOString(),
                    'dependentCount' => $dependants,
                    'maintenance' => $this->maintenanceFor($maintenanceWindows, $siteId, (int) $device->id, (int) $monitor->id),
                    'collector' => $monitor->collector ? [
                        'name' => $monitor->collector->name,
                        'status' => $monitor->collector->status,
                        'lastSeenAt' => $monitor->collector->last_seen_at?->toISOString(),
                    ] : null,
                ]);
        })->values();
    }

    private function monitorKindLabel(?MonitorKind $kind): string
    {
        return match ($kind) {
            MonitorKind::Icmp => 'ICMP',
            MonitorKind::Tcp => 'TCP',
            MonitorKind::Dns => 'DNS',
            MonitorKind::Http => 'HTTP',
            MonitorKind::Tls => 'TLS',
            MonitorKind::Snmp => 'SNMP',
            MonitorKind::SnmpInterface => 'SNMP interface',
            MonitorKind::Provider => 'Provider check',
            MonitorKind::Collector => 'Collector health',
            default => 'Monitor',
        };
    }

    /** @return array<string, mixed> */
    private function configuration(Device $device, User $viewer, ?int $canonicalSiteId): array
    {
        $observedHash = $this->evidenceValue($device, [
            'observed.configuration_hash',
            'monitoring.configuration.observed_hash',
            'configuration.observed_hash',
        ]);
        $desiredHash = $this->evidenceValue($device, [
            'desired.configuration_hash',
            'monitoring.configuration.desired_hash',
            'configuration.desired_hash',
        ]);
        $configurationAt = $this->evidenceDate($device, [
            'observed.configuration_at',
            'observed.configuration_observed_at',
            'monitoring.configuration.observed_at',
        ]);
        $desiredFirmware = $this->evidenceValue($device, [
            'desired.firmware_version',
            'monitoring.firmware.desired_version',
            'firmware.desired_version',
        ]);
        $firmwareAt = $this->evidenceDate($device, [
            'observed.firmware_at',
            'observed.firmware_observed_at',
            'monitoring.firmware.observed_at',
        ]);
        $observedHash = is_scalar($observedHash) ? (string) $observedHash : null;
        $desiredHash = is_scalar($desiredHash) ? (string) $desiredHash : null;
        $desiredFirmware = is_scalar($desiredFirmware) ? (string) $desiredFirmware : null;
        $configurationState = match (true) {
            $observedHash === null && $desiredHash === null => 'not_observed',
            $observedHash !== null && $desiredHash !== null && ! hash_equals($observedHash, $desiredHash) => 'drifted',
            $observedHash !== null && $desiredHash !== null => 'aligned',
            $observedHash !== null => 'observed',
            default => 'desired_only',
        };
        $currentFirmware = $device->firmware_version;
        $firmwareState = match (true) {
            $currentFirmware === null && $desiredFirmware === null => 'not_observed',
            $currentFirmware !== null && $desiredFirmware !== null && $currentFirmware !== $desiredFirmware => 'update_available',
            $currentFirmware !== null && $desiredFirmware !== null => 'aligned',
            $currentFirmware !== null => 'observed',
            default => 'desired_only',
        };
        $snapshotCandidates = $canonicalSiteId === null
            ? collect()
            : $device->configurationSnapshots
                ->where('site_id', $canonicalSiteId)
                ->values();
        /** @var ConfigurationSnapshot|null $latestSnapshot */
        $latestSnapshot = $snapshotCandidates->first();
        $snapshotHistory = $latestSnapshot === null
            ? collect()
            : $snapshotCandidates
                ->filter(fn (ConfigurationSnapshot $snapshot): bool => (int) $snapshot->site_id === (int) $latestSnapshot->site_id)
                ->take(11)
                ->values();
        $snapshotService = $this->snapshots ?? app(ConfigurationSnapshotService::class);
        $canViewSnapshots = $snapshotService->canViewSnapshots($viewer);
        $declared = $this->declaredCapabilities ?? app(DeclaredDeviceCommandCapabilities::class);
        $routes = $this->executionRoutes ?? app(CommandExecutionRouteResolver::class);
        $canBackup = $viewer->canDo('securityDevices.commands.manage')
            && $canonicalSiteId !== null
            && $declared->supports($device, 'network.config.backup')
            && $routes->resolve($device, $canonicalSiteId, 'network.config.backup')->available;

        $snapshotHistory = $snapshotHistory
            ->map(fn (ConfigurationSnapshot $snapshot): array => $this->configurationSnapshot($device, $snapshot, $canViewSnapshots))
            ->values();

        return [
            'deviceId' => $device->id,
            'deviceName' => $device->name,
            'deviceHref' => "/security-devices/devices/{$device->id}",
            'canViewSnapshots' => $canViewSnapshots,
            'backupActionHref' => $canBackup
                ? "/security-devices/devices/{$device->id}?section=management&action=network.config.backup"
                : null,
            'configuration' => [
                'state' => $configurationState,
                'observedHash' => $observedHash,
                'desiredHash' => $desiredHash,
                'observedAt' => $configurationAt,
            ],
            'firmware' => [
                'state' => $firmwareState,
                'currentVersion' => $currentFirmware,
                'desiredVersion' => $desiredFirmware,
                'observedAt' => $firmwareAt,
            ],
            'latestSnapshot' => $snapshotHistory->first(),
            'snapshotHistory' => $snapshotHistory,
            'snapshotHistoryTruncated' => $snapshotCandidates->count() > $snapshotHistory->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function configurationSnapshot(Device $device, ConfigurationSnapshot $snapshot, bool $canViewSnapshots): array
    {
        $diff = is_array($snapshot->diff_summary) ? $snapshot->diff_summary : [];
        $added = collect($diff['added'] ?? [])->filter(fn (mixed $path): bool => is_string($path))->values();
        $removed = collect($diff['removed'] ?? [])->filter(fn (mixed $path): bool => is_string($path))->values();
        $changed = collect($diff['changed'] ?? [])->filter(fn (mixed $path): bool => is_string($path))->values();
        $canAccessPayload = $snapshot->storage_state === 'available' && $canViewSnapshots;

        return [
            'id' => $snapshot->id,
            'sourceKind' => $snapshot->source_kind,
            'source' => $snapshot->source,
            'capturedAt' => $snapshot->captured_at?->toISOString(),
            'contentHash' => $snapshot->content_hash,
            'configurationHash' => $snapshot->configuration_hash,
            'contentSize' => $snapshot->content_size,
            'mimeType' => $snapshot->mime_type,
            'firmwareVersion' => $snapshot->firmware_version,
            'storageState' => $snapshot->storage_state,
            'previousSnapshotId' => $snapshot->previous_snapshot_id,
            'diff' => [
                'added' => $added->all(),
                'removed' => $removed->all(),
                'changed' => $changed->all(),
                'count' => $added->count() + $removed->count() + $changed->count(),
                'truncated' => (bool) ($diff['truncated'] ?? false),
            ],
            'downloadHref' => $canAccessPayload
                ? "/security-devices/devices/{$device->id}/configuration-snapshots/{$snapshot->id}"
                : null,
            'compareHref' => $canAccessPayload
                ? "/security-devices/devices/{$device->id}/configuration-snapshots/{$snapshot->id}/compare"
                : null,
        ];
    }

    /** @param Collection<int, int> $siteIds @return Collection<int, MonitoringMaintenanceWindow> */
    private function maintenanceWindows(Collection $siteIds): Collection
    {
        if ($siteIds->isEmpty()) {
            return collect();
        }

        return MonitoringMaintenanceWindow::query()
            ->where('status', 'active')
            ->whereIn('site_id', $siteIds)
            ->where('ends_at', '>', now())
            ->where('starts_at', '<=', now()->addDays(7))
            ->orderBy('starts_at')
            ->limit(150)
            ->get();
    }

    /** @return array{activeWindow: array<string, mixed>|null, upcomingWindow: array<string, mixed>|null} */
    private function maintenanceFor(
        Collection $windows,
        ?int $siteId,
        int $deviceId,
        ?int $monitorId,
    ): array {
        if ($siteId === null || $windows->isEmpty()) {
            return ['activeWindow' => null, 'upcomingWindow' => null];
        }

        $now = now();
        $matching = $windows->filter(function (MonitoringMaintenanceWindow $window) use ($siteId, $deviceId, $monitorId): bool {
            if ((int) $window->site_id !== $siteId) {
                return false;
            }
            if ($window->monitor_id !== null) {
                return $monitorId !== null && (int) $window->monitor_id === $monitorId;
            }
            if ($window->device_id !== null) {
                return (int) $window->device_id === $deviceId;
            }

            return true;
        });

        $active = $matching->first(fn (MonitoringMaintenanceWindow $w): bool => $w->starts_at !== null && $w->starts_at->lte($now));
        $upcoming = $matching->first(fn (MonitoringMaintenanceWindow $w): bool => $w->starts_at !== null && $w->starts_at->gt($now));

        $format = static fn (?MonitoringMaintenanceWindow $w): ?array => $w === null ? null : [
            'id' => $w->id,
            'name' => $w->name,
            'scopeType' => $w->monitor_id !== null ? 'monitor' : ($w->device_id !== null ? 'device' : 'site'),
            'startsAt' => $w->starts_at?->toISOString(),
            'endsAt' => $w->ends_at?->toISOString(),
        ];

        return [
            'activeWindow' => $format($active),
            'upcomingWindow' => $format($upcoming),
        ];
    }

    private function evidenceValue(Device $device, array $paths): mixed
    {
        foreach ([$device->meta ?? [], $device->config ?? []] as $source) {
            foreach ($paths as $path) {
                $value = data_get($source, $path);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function evidenceDate(Device $device, array $paths): ?string
    {
        $value = $this->evidenceValue($device, $paths);
        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param Collection<int, int> $deviceIds @return Collection<int, ItTicketLink> */
    private function ticketLinks(User $viewer, Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty()) {
            return collect();
        }

        return ItTicketLink::query()
            ->where('relationship', 'affected_device')
            ->where('linkable_type', Device::class)
            ->whereIn('linkable_id', $deviceIds)
            ->whereHas('ticket', fn (Builder $ticket) => $ticket
                ->whereIn('status', ItTicket::OPEN_STATUSES))
            ->with('ticket:id,reference,title,status,updated_at,requester_user_id')
            ->latest('id')
            ->get()
            ->filter(fn (ItTicketLink $link): bool => $link->ticket !== null
                && Gate::forUser($viewer)->allows('view', $link->ticket));
    }

    /** @return array<string, int> */
    private function gaps(
        Collection $devices,
        Collection $interfaces,
        Collection $services,
        Collection $traffic,
        Collection $configuration,
    ): array {
        $interfaceDeviceIds = $interfaces->pluck('deviceId')->unique();
        $serviceDeviceIds = $services->where('enabled', true)->pluck('deviceId')->unique();
        $trafficDeviceIds = $traffic->pluck('deviceId')->unique();

        return [
            'devicesWithoutMonitors' => $devices->filter(fn (Device $device): bool => $device->monitors->where('is_enabled', true)->isEmpty())->count(),
            'devicesWithoutInterfaceEvidence' => $devices->pluck('id')->diff($interfaceDeviceIds)->count(),
            'devicesWithoutCapacityEvidence' => $devices->pluck('id')->diff($trafficDeviceIds)->count(),
            'devicesWithoutConfigurationEvidence' => $configuration->where('configuration.state', 'not_observed')->count(),
            'devicesWithoutFirmwareEvidence' => $configuration->where('firmware.state', 'not_observed')->count(),
            'devicesWithoutServiceChecks' => $devices->pluck('id')->diff($serviceDeviceIds)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function overview(
        Collection $mappedDevices,
        Collection $devices,
        Collection $interfaces,
        Collection $traffic,
        Collection $configuration,
        array $topology,
        Collection $ticketLinks,
        array $permissions,
    ): array {
        $enabledMonitors = $devices->flatMap->monitors->where('is_enabled', true);
        $attentionMonitors = $enabledMonitors->whereIn('current_state', [MonitorState::Failed, MonitorState::Degraded]);
        $uncertainMonitors = $enabledMonitors->whereIn('current_state', [MonitorState::Unknown, MonitorState::Stale, MonitorState::Pending]);
        $sites = $mappedDevices
            ->pluck('site')
            ->filter()
            ->unique('id')
            ->map(function (array $site) use ($mappedDevices): array {
                $siteDevices = $mappedDevices->filter(fn (array $device): bool => ($device['site']['id'] ?? null) === $site['id']);

                return [
                    'id' => $site['id'],
                    'name' => $site['name'],
                    'href' => $site['href'],
                    'devices' => $siteDevices->count(),
                    'monitoredDevices' => $siteDevices->where('monitoring.enabled', '>', 0)->count(),
                    'attention' => $siteDevices->filter(fn (array $device): bool => $device['monitoring']['attention'] > 0
                        || in_array($device['status'], [DeviceStatus::Offline->value, DeviceStatus::Degraded->value], true))->count(),
                ];
            })
            ->values();
        $wanPaths = $mappedDevices
            ->where('wanPath', true)
            ->map(fn (array $device): array => [
                'id' => $device['id'],
                'name' => $device['name'],
                'site' => $device['site']['name'] ?? null,
                'state' => $device['monitoring']['attention'] > 0 ? 'attention' : ($device['health'] ?? 'unknown'),
                'lastSeenAt' => $device['lastSeenAt'],
                'href' => $device['href'],
            ])
            ->values();
        $itWork = $ticketLinks
            ->pluck('ticket')
            ->unique('id')
            ->map(fn (ItTicket $ticket): array => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'title' => $ticket->title,
                'status' => $ticket->status,
                'href' => "/it/tickets/{$ticket->id}",
            ])
            ->values();
        $deviceAttention = $mappedDevices->filter(fn (array $device): bool => in_array(
            $device['status'],
            [DeviceStatus::Offline->value, DeviceStatus::Degraded->value],
            true,
        ) || in_array($device['health'], ['warning', 'critical'], true))->count();
        $capacityAttention = $traffic->whereIn('state', ['warning', 'critical'])->count();
        $configurationAttention = $configuration->where('configuration.state', 'drifted')->count();
        $firmwareAttention = $configuration->where('firmware.state', 'update_available')->count();
        $attention = [
            'devices' => $deviceAttention,
            'monitoring' => $attentionMonitors->count(),
            'capacity' => $capacityAttention,
            'configuration' => $configurationAttention,
            'firmware' => $firmwareAttention,
            'open_work' => $permissions['viewItWork'] ? $itWork->count() : null,
        ];

        return [
            'inventory' => [
                'devices' => $mappedDevices->count(),
                'sites' => $sites->count(),
                'wan_paths' => $wanPaths->count(),
                'monitored_devices' => $devices->filter(fn (Device $device): bool => $device->monitors->where('is_enabled', true)->isNotEmpty())->count(),
                'unmonitored_devices' => $devices->filter(fn (Device $device): bool => $device->monitors->where('is_enabled', true)->isEmpty())->count(),
            ],
            'monitoring' => [
                'enabled' => $enabledMonitors->count(),
                'healthy' => $enabledMonitors->where('current_state', MonitorState::Healthy)->count(),
                'attention' => $attentionMonitors->count(),
                'uncertain' => $uncertainMonitors->count(),
            ],
            'evidence' => [
                'topology_edges' => $topology['edgeCount'],
                'interfaces' => $interfaces->count(),
                'capacity_series' => $traffic->count(),
                'configuration' => $configuration->whereNotIn('configuration.state', ['not_observed', 'desired_only'])->count(),
                'firmware' => $configuration->whereNotIn('firmware.state', ['not_observed', 'desired_only'])->count(),
            ],
            'attention' => $attention,
            'requiredActions' => collect([
                $this->action('devices', 'Devices requiring attention', $deviceAttention, 'Review offline, degraded, warning, or critical canonical device state.', 'devices'),
                $this->action('monitoring', 'Monitor failures or degradation', $attentionMonitors->count(), 'Review failed and degraded native checks.', 'services'),
                $this->action('capacity', 'Capacity warnings', $capacityAttention, 'Review retained interface observations above the configured display threshold.', 'traffic-capacity'),
                $this->action('configuration', 'Configuration drift', $configurationAttention, 'Review observed and desired configuration evidence before any governed change.', 'configuration-firmware'),
                $this->action('firmware', 'Firmware updates indicated', $firmwareAttention, 'Review observed and desired firmware evidence before any governed update.', 'configuration-firmware'),
                $permissions['viewItWork']
                    ? $this->action('open-work', 'Open technical work', $itWork->count(), 'Continue linked incidents and service work in IT & Support.', 'overview')
                    : null,
            ])->filter(fn (?array $action): bool => $action !== null && $action['count'] > 0)->values(),
            'sites' => $sites,
            'wanPaths' => $wanPaths,
            'itWork' => $permissions['viewItWork'] ? $itWork : [],
        ];
    }

    /** @return array<string, mixed> */
    private function action(string $key, string $label, int $count, string $description, string $tab): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'description' => $description,
            'href' => "/security-devices/network-it?tab={$tab}&attention={$key}",
        ];
    }

    private function stringMetric(array $metrics, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($metrics, $key);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function intMetric(array $metrics, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = data_get($metrics, $key);
            if (is_numeric($value)) {
                return (int) round((float) $value);
            }
        }

        return null;
    }

    private function floatMetric(array $metrics, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = data_get($metrics, $key);
            if (is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return null;
    }
}
