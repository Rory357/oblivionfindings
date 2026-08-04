<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

class NetworkItWorkspacePresenter
{
    private const DEVICE_LIMIT = 100;

    /** @return array<string, mixed> */
    public function present(User $viewer, Builder $networkScope, array $activeTab): array
    {
        $candidates = (clone $networkScope)
            ->with([
                'assignments' => fn ($query) => $query->active(),
                'monitors' => fn ($query) => $query
                    ->with('collector:id,name,status,last_seen_at')
                    ->orderBy('name'),
                'latestConfigurationSnapshot',
            ])
            ->orderBy('name')
            ->limit(self::DEVICE_LIMIT + 1)
            ->get();
        $inventoryTruncated = $candidates->count() > self::DEVICE_LIMIT;
        $devices = $candidates->take(self::DEVICE_LIMIT)->values();
        $deviceIds = $devices->pluck('id');
        $monitorIds = $devices->flatMap->monitors->pluck('id');
        $latestObservations = $this->latestObservations($viewer, $monitorIds);
        $siteContext = $this->siteContext($devices);
        $mappedDevices = $devices
            ->map(fn (Device $device): array => $this->mapDevice($device, $siteContext))
            ->values();
        $relationships = $this->relationships($deviceIds);
        $topology = $this->topology($mappedDevices, $relationships, $this->latestTopologySnapshots($mappedDevices));
        $interfaces = $this->interfaces($devices, $latestObservations);
        $services = $this->services($devices, $relationships);
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
            ->map(fn (Device $device): array => $this->configuration($device, $viewer))
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
                'managementNote' => 'Configuration and firmware are read-only until governed command, approval, execution, and reconciliation workflows are enabled.',
            ],
            'overview' => $overview,
            'activeTab' => [
                'key' => $activeTab['key'],
                'label' => $activeTab['label'],
                'description' => $activeTab['description'],
                'inventoryTotal' => $inventoryTotal,
                'inventoryShown' => match ($activeTab['key']) {
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
    private function mapDevice(Device $device, array $context): array
    {
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
            ->whereIn('parent_device_id', $deviceIds)
            ->whereIn('child_device_id', $deviceIds)
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, array<string, mixed>> $devices @return Collection<int, TopologySnapshot> */
    private function latestTopologySnapshots(Collection $devices): Collection
    {
        $siteIds = $devices->pluck('site.id')->filter()->unique()->values();
        if ($siteIds->isEmpty()) {
            return collect();
        }

        return TopologySnapshot::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', 'completed')
            ->with([
                'site:id,name',
                'nodes.canonicalDevice:id,name,category,subcategory,health_status',
                'edges.fromNode.canonicalDevice:id,name',
                'edges.toNode.canonicalDevice:id,name',
                'changes:id,current_snapshot_id,change_type,edge_hash,created_at',
            ])
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get()
            ->reject(fn (TopologySnapshot $snapshot): bool => preg_match(
                '/^native:snmp:(?:lldp|cdp|arp|forwarding_table|route):device:\d+$/',
                $snapshot->source,
            ) === 1)
            ->unique(fn (TopologySnapshot $snapshot): string => $snapshot->site_id.':'.$snapshot->source)
            ->values();
    }

    /** @return array<string, mixed> */
    private function topology(Collection $devices, Collection $relationships, Collection $snapshots): array
    {
        if ($snapshots->isNotEmpty()) {
            return $this->runtimeTopology($devices, $snapshots);
        }

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
            'changes' => ['added' => 0, 'removed' => 0, 'changed' => 0],
        ];
    }

    /** @param Collection<int, array<string, mixed>> $devices @param Collection<int, TopologySnapshot> $snapshots @return array<string, mixed> */
    private function runtimeTopology(Collection $devices, Collection $snapshots): array
    {
        $visible = $devices->keyBy('id');
        $nodes = $snapshots->flatMap->nodes
            ->filter(fn ($node): bool => $node->canonical_device_id !== null && $visible->has((int) $node->canonical_device_id))
            ->unique('canonical_device_id')
            ->map(function ($node) use ($visible): array {
                $device = $visible->get((int) $node->canonical_device_id);

                return [
                    'id' => (int) $node->canonical_device_id,
                    'name' => $device['name'],
                    'category' => $device['category'],
                    'subcategory' => $device['subcategory'],
                    'health' => $device['health'],
                    'site' => $device['site']['name'] ?? null,
                    'href' => $device['href'],
                ];
            })
            ->values();
        $visibleIds = $nodes->pluck('id');
        $edges = $snapshots->flatMap->edges
            ->filter(function (TopologyEdge $edge) use ($visibleIds): bool {
                $from = $edge->fromNode?->canonical_device_id;
                $to = $edge->toNode?->canonical_device_id;

                return $from !== null && $to !== null
                    && $visibleIds->contains((int) $from)
                    && $visibleIds->contains((int) $to);
            })
            ->map(function (TopologyEdge $edge): array {
                $from = $edge->fromNode?->canonicalDevice;
                $to = $edge->toNode?->canonicalDevice;
                $confidence = (float) $edge->confidence;
                $provider = is_array($edge->evidence) && is_string($edge->evidence['provider'] ?? null)
                    ? $edge->evidence['provider']
                    : null;
                $providerLabel = $provider === 'unifi' ? 'UniFi' : Str::headline((string) $provider);
                $evidenceSource = $edge->source === 'provider' && $provider !== null
                    ? $providerLabel.' provider'
                    : Str::headline($edge->source);

                return [
                    'id' => 'runtime-'.$edge->id,
                    'parentId' => $from?->id,
                    'parentName' => $from?->name ?? 'Unknown source Device',
                    'childId' => $to?->id,
                    'childName' => $to?->name ?? 'Unknown destination Device',
                    'type' => $edge->kind,
                    'label' => Str::headline($edge->kind),
                    'port' => collect([$edge->local_port, $edge->remote_port])->filter()->implode(' → ') ?: null,
                    'source' => $edge->source,
                    'confidence' => $confidence,
                    'reviewState' => in_array($edge->source, ['manual', 'reviewed'], true) ? 'reviewed' : 'inferred',
                    'evidenceLabel' => $evidenceSource.' '.Str::headline($edge->kind).' evidence',
                    'firstSeenAt' => $edge->first_seen_at?->toIso8601String(),
                    'lastSeenAt' => $edge->last_seen_at?->toIso8601String(),
                ];
            })
            ->values();
        $linkedIds = $edges->flatMap(fn (array $edge): array => [(int) $edge['parentId'], (int) $edge['childId']])->unique();
        $unlinked = $nodes->pluck('id')->diff($linkedIds)->count();
        $changes = $snapshots->flatMap->changes->countBy('change_type');

        return [
            'source' => 'runtime_topology',
            'state' => $edges->isEmpty() ? 'partial' : ($unlinked === 0 ? 'known' : 'partial'),
            'label' => $edges->isEmpty()
                ? 'Latest topology snapshots have no visible relationships'
                : ($unlinked === 0 ? 'Topology evidence covers every visible Device' : 'Topology evidence is incomplete'),
            'nodeCount' => $nodes->count(),
            'edgeCount' => $edges->count(),
            'unlinkedCount' => $unlinked,
            'nodes' => $nodes,
            'edges' => $edges,
            'snapshots' => $snapshots->map(fn (TopologySnapshot $snapshot): array => [
                'id' => $snapshot->id,
                'site' => $snapshot->site ? [
                    'id' => $snapshot->site->id,
                    'name' => $snapshot->site->name,
                    'href' => "/security-devices/sites/{$snapshot->site->id}",
                ] : null,
                'source' => $snapshot->source,
                'capturedAt' => $snapshot->captured_at?->toIso8601String(),
                'nodeCount' => $snapshot->node_count,
                'edgeCount' => $snapshot->edge_count,
                'changeCount' => $snapshot->change_count,
            ])->values(),
            'changes' => [
                'added' => (int) ($changes['added'] ?? 0),
                'removed' => (int) ($changes['removed'] ?? 0),
                'changed' => (int) ($changes['changed'] ?? 0),
            ],
        ];
    }

    /** @param Collection<int, Device> $devices @param Collection<int, MonitorObservation> $observations */
    private function interfaces(Collection $devices, Collection $observations): Collection
    {
        return $devices->flatMap(function (Device $device) use ($observations): Collection {
            return $device->monitors
                ->where('kind', MonitorKind::SnmpInterface)
                ->map(function (Monitor $monitor) use ($device, $observations): array {
                    $observation = $observations->get($monitor->id);
                    $metrics = $observation?->metrics ?? [];
                    $inUtilisation = $this->floatMetric($metrics, ['in_utilization_pct', 'in_utilisation_pct']);
                    $outUtilisation = $this->floatMetric($metrics, ['out_utilization_pct', 'out_utilisation_pct']);
                    $maximum = collect([$inUtilisation, $outUtilisation])->filter(fn ($value) => $value !== null)->max();

                    return [
                        'monitorId' => $monitor->id,
                        'deviceId' => $device->id,
                        'deviceName' => $device->name,
                        'deviceHref' => "/security-devices/devices/{$device->id}",
                        'name' => $this->stringMetric($metrics, ['interface_name', 'if_name']) ?: $monitor->name,
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
                    ];
                });
        })->values();
    }

    /** @param Collection<int, Device> $devices @param Collection<int, DeviceRelationship> $relationships */
    private function services(Collection $devices, Collection $relationships): Collection
    {
        return $devices->flatMap(function (Device $device) use ($relationships): Collection {
            $dependants = $relationships->filter(fn (DeviceRelationship $relationship): bool => (int) $relationship->parent_device_id === (int) $device->id
                || (int) $relationship->child_device_id === (int) $device->id)->count();

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
    private function configuration(Device $device, User $viewer): array
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
        $snapshot = $device->latestConfigurationSnapshot;
        $canDownload = $snapshot !== null
            && $snapshot->storage_state === 'available'
            && ($snapshot->source_kind !== 'provider' || $viewer->canDo('securityDevices.integrations.view'));

        return [
            'deviceId' => $device->id,
            'deviceName' => $device->name,
            'deviceHref' => "/security-devices/devices/{$device->id}",
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
            'latestSnapshot' => $snapshot === null ? null : [
                'id' => $snapshot->id,
                'sourceKind' => $snapshot->source_kind,
                'source' => $snapshot->source,
                'capturedAt' => $snapshot->captured_at?->toISOString(),
                'contentHash' => $snapshot->content_hash,
                'configurationHash' => $snapshot->configuration_hash,
                'contentSize' => $snapshot->content_size,
                'mimeType' => $snapshot->mime_type,
                'storageState' => $snapshot->storage_state,
                'diff' => $snapshot->diff_summary,
                'downloadHref' => $canDownload
                    ? "/security-devices/devices/{$device->id}/configuration-snapshots/{$snapshot->id}"
                    : null,
            ],
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
