<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ControlRoomAlert;
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

class DeviceProfilePresenter
{
    public function __construct(
        private readonly SecurityDevicesAccessService $deviceAccess,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, Device $device, ?DeviceAssignment $activeAssignment): array
    {
        $canViewMonitoring = $viewer->canDo('securityDevices.events.view');
        $canViewMaintenance = $viewer->canDo('securityDevices.maintenance.view');
        $canViewIt = $viewer->canDo('it.view');
        $canViewAudit = $viewer->canDo('securityDevices.reports.view');
        $canViewControlRoom = $viewer->canDo('controlRoom.viewAny');
        $monitors = $canViewMonitoring && $device->relationLoaded('monitors')
            ? $device->monitors
            : collect();
        $observations = $canViewMonitoring
            ? $this->latestObservations($monitors->pluck('id'))
            : collect();
        $tickets = $canViewIt ? $this->tickets($viewer, $device) : collect();
        $audit = $canViewAudit ? $this->audit($device) : collect();
        $controlRoomAlerts = $canViewControlRoom
            ? $this->controlRoomAlerts($viewer, $device)
            : collect();

        return [
            'header' => $this->header(
                $viewer,
                $device,
                $activeAssignment,
                $monitors,
                $canViewMonitoring,
                $canViewMaintenance,
            ),
            'sections' => $this->sections(
                $device,
                $canViewMonitoring,
                $canViewIt,
                $canViewMaintenance,
                $canViewAudit,
                $canViewControlRoom,
                $monitors,
                $tickets,
                $audit,
                $controlRoomAlerts,
            ),
            'health' => $this->health($device, $monitors, $canViewMonitoring),
            'monitors' => $canViewMonitoring
                ? $monitors->map(fn (Monitor $monitor): array => $this->monitor($monitor))->values()
                : [],
            'interfacesSensors' => $canViewMonitoring
                ? $this->interfacesAndSensors($monitors, $observations)
                : [],
            'configuration' => $this->configuration($device),
            'tickets' => $tickets->values(),
            'controlRoomAlerts' => $controlRoomAlerts->values(),
            'audit' => $audit->values(),
            'capabilities' => $this->capabilities($viewer, $device),
        ];
    }

    /** @return array<string, mixed> */
    private function header(
        User $viewer,
        Device $device,
        ?DeviceAssignment $assignment,
        Collection $monitors,
        bool $canViewMonitoring,
        bool $canViewMaintenance,
    ): array {
        $enabledMonitors = $monitors->where('is_enabled', true);
        $latestMonitor = $enabledMonitors
            ->filter(fn (Monitor $monitor): bool => $monitor->last_observation_at !== null)
            ->sortByDesc(fn (Monitor $monitor): int => $monitor->last_observation_at->getTimestamp())
            ->first();
        $lastMonitorObservation = $latestMonitor?->last_observation_at;
        $observedAt = collect([$lastMonitorObservation, $device->last_seen_at])
            ->filter()
            ->sortDesc()
            ->first();
        $observationSource = $observedAt !== null && $lastMonitorObservation !== null
            && $observedAt->equalTo($lastMonitorObservation)
            ? 'native_monitoring'
            : 'device_registry';
        $staleAfterSeconds = $observationSource === 'native_monitoring' && $latestMonitor
            ? $this->monitorStaleAfterSeconds($latestMonitor)
            : 900;
        $hasStaleMonitor = $enabledMonitors->contains(
            fn (Monitor $monitor): bool => $this->monitorIsStale($monitor),
        );
        $monitoringSupported = $this->monitoringCapabilityEvidence($device) === true;
        $location = $this->location($viewer, $device, $assignment);

        return [
            'identity' => [
                'id' => $device->id,
                'name' => $device->name,
                'uid' => $device->device_uid,
                'type' => collect([$device->category, $device->subcategory])->filter()->join(' / '),
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
            ],
            'location' => $location,
            'assignment' => $assignment ? [
                'type' => $assignment->assignable_type,
                'name' => $location['name'] ?? null,
                'assignedAt' => $assignment->assigned_at?->toISOString(),
                'expectedReturnAt' => $assignment->expected_return_at?->toISOString(),
            ] : null,
            'health' => [
                'state' => $device->health_status?->value ?? HealthStatus::Unknown->value,
                'label' => $device->health_status?->label() ?? HealthStatus::Unknown->label(),
                'deviceState' => $device->status?->value,
                'deviceStateLabel' => $device->status?->label(),
            ],
            'freshness' => [
                'state' => $hasStaleMonitor
                    ? 'stale'
                    : $this->freshnessState($observedAt, $staleAfterSeconds),
                'observedAt' => $observedAt?->toISOString(),
                'staleAfterSeconds' => $staleAfterSeconds,
            ],
            'providerObservation' => [
                'provider' => $device->provider ?: 'oblivion_native',
                'label' => Str::headline($device->provider ?: 'Oblivion native'),
                'observedAt' => $observedAt?->toISOString(),
                'source' => $observationSource,
            ],
            'requiredAction' => $this->requiredAction(
                $device,
                $assignment,
                $enabledMonitors,
                $canViewMonitoring,
                $canViewMaintenance,
                $staleAfterSeconds,
                $observedAt,
                $hasStaleMonitor,
                $monitoringSupported,
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    private function location(User $viewer, Device $device, ?DeviceAssignment $assignment): ?array
    {
        if (! $assignment) {
            return null;
        }

        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => $this->siteLocation($device, $assignment),
            DeviceAssignment::TARGET_ROOM => $this->roomLocation($device, $assignment),
            DeviceAssignment::TARGET_CLIENT => $this->clientLocation($device, $assignment),
            DeviceAssignment::TARGET_VEHICLE => $this->vehicleLocation($viewer, $assignment),
            DeviceAssignment::TARGET_STAFF => $this->staffLocation($device, $assignment),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function siteLocation(Device $device, DeviceAssignment $assignment): ?array
    {
        $site = Site::query()
            ->find($assignment->assignable_id, ['id', 'name']);

        return $site ? [
            'id' => $site->id,
            'type' => 'site',
            'name' => $site->name,
            'href' => "/security-devices/sites/{$site->id}",
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function clientLocation(Device $device, DeviceAssignment $assignment): ?array
    {
        $client = Client::query()
            ->find($assignment->assignable_id, ['id', 'first_name', 'last_name']);

        return $client ? [
            'id' => $client->id,
            'type' => 'client',
            'name' => $client->full_name,
            'href' => "/operations/clients/{$client->id}",
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function roomLocation(Device $device, DeviceAssignment $assignment): ?array
    {
        $room = SiteRoom::query()
            ->with('site:id,name')
            ->find($assignment->assignable_id);

        return $room?->site ? [
            'id' => $room->id,
            'type' => 'room',
            'name' => "{$room->name}, {$room->site->name}",
            'href' => "/security-devices/sites/{$room->site->id}",
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function vehicleLocation(User $viewer, DeviceAssignment $assignment): ?array
    {
        if (! in_array((int) $assignment->assignable_id, $this->deviceAccess->accessibleAssetIds($viewer), true)) {
            return null;
        }

        $asset = Asset::query()->find($assignment->assignable_id, ['id', 'name', 'registration_number']);

        return $asset ? [
            'id' => $asset->id,
            'type' => 'vehicle',
            'name' => $asset->name ?: $asset->registration_number,
            'href' => null,
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function staffLocation(Device $device, DeviceAssignment $assignment): ?array
    {
        $staff = User::query()
            ->find($assignment->assignable_id, ['id', 'name']);

        return $staff ? [
            'id' => $staff->id,
            'type' => 'staff',
            'name' => $staff->name,
            'href' => null,
        ] : null;
    }

    /** @return array<string, mixed> */
    private function requiredAction(
        Device $device,
        ?DeviceAssignment $assignment,
        Collection $monitors,
        bool $canViewMonitoring,
        bool $canViewMaintenance,
        int $staleAfterSeconds,
        mixed $observedAt,
        bool $hasStaleMonitor,
        bool $monitoringSupported,
    ): array {
        if ($device->health_status === HealthStatus::Critical || $device->status === DeviceStatus::Offline) {
            return $this->action('critical', 'Investigate this device', 'The device is offline or in a critical health state.', 'health');
        }

        if ($canViewMonitoring && ($hasStaleMonitor || $monitors->contains(fn (Monitor $monitor): bool => in_array(
            $monitor->current_state?->value,
            ['failed', 'degraded'],
            true,
        )))) {
            return $this->action('warning', 'Investigate the monitor warning', 'A native check is failed, degraded, or stale.', 'monitors');
        }

        if ($observedAt === null || $observedAt->lt(now()->subSeconds($staleAfterSeconds))) {
            return $this->action('warning', 'Check collection freshness', 'No recent device observation is available.', 'health');
        }

        if ($canViewMaintenance && $device->relationLoaded('maintenanceRecords') && $device->maintenanceRecords->contains(
            fn ($record): bool => $record->status === 'scheduled' && $record->scheduled_for?->isPast(),
        )) {
            return $this->action('warning', 'Complete overdue maintenance', 'Scheduled maintenance is overdue.', 'maintenance');
        }

        if ($canViewMonitoring && $monitoringSupported && $monitors->isEmpty()) {
            return $this->action('attention', 'Add monitoring coverage', 'No enabled native monitor is assigned.', 'monitors');
        }

        if (! $assignment) {
            return $this->action('attention', 'Assign this device', 'The device has no current site, room, person, client, or vehicle assignment.', 'assignments');
        }

        return $this->action('none', 'No immediate action', 'The current device evidence does not require intervention.', 'health');
    }

    /** @return array<string, string> */
    private function action(string $state, string $label, string $description, string $section): array
    {
        return compact('state', 'label', 'description', 'section');
    }

    private function freshnessState(mixed $observedAt, int $staleAfterSeconds): string
    {
        if ($observedAt === null) {
            return 'never_observed';
        }

        return $observedAt->lt(now()->subSeconds($staleAfterSeconds)) ? 'stale' : 'fresh';
    }

    private function monitorStaleAfterSeconds(Monitor $monitor): int
    {
        $threshold = $monitor->profile?->stale_after_seconds;

        return is_numeric($threshold) && (int) $threshold > 0 ? (int) $threshold : 900;
    }

    private function monitorIsStale(Monitor $monitor): bool
    {
        if ($monitor->current_state?->value === 'stale') {
            return true;
        }

        return $monitor->last_observation_at === null
            || $monitor->last_observation_at->lt(now()->subSeconds($this->monitorStaleAfterSeconds($monitor)));
    }

    /** @return array<int, array<string, mixed>> */
    private function sections(
        Device $device,
        bool $canViewMonitoring,
        bool $canViewIt,
        bool $canViewMaintenance,
        bool $canViewAudit,
        bool $canViewControlRoom,
        Collection $monitors,
        Collection $tickets,
        Collection $audit,
        Collection $controlRoomAlerts,
    ): array {
        return collect([
            ['key' => 'health', 'label' => 'Health', 'group' => 'status'],
            $canViewMonitoring ? ['key' => 'monitors', 'label' => 'Monitors', 'group' => 'status', 'count' => $monitors->count()] : null,
            ['key' => 'topology', 'label' => 'Topology', 'group' => 'technical'],
            $canViewMonitoring ? ['key' => 'interfaces-sensors', 'label' => 'Interfaces & sensors', 'group' => 'technical'] : null,
            ['key' => 'configuration', 'label' => 'Configuration', 'group' => 'technical'],
            ['key' => 'assignments', 'label' => 'Assignments', 'group' => 'operations', 'count' => $device->assignments->count()],
            $canViewIt ? ['key' => 'tickets', 'label' => 'Tickets', 'group' => 'operations', 'count' => $tickets->count()] : null,
            $canViewMonitoring || $canViewControlRoom ? [
                'key' => 'events',
                'label' => 'Events',
                'group' => 'operations',
                'count' => ($canViewMonitoring ? $device->events->count() : 0) + $controlRoomAlerts->count(),
            ] : null,
            $canViewMaintenance ? ['key' => 'maintenance', 'label' => 'Maintenance', 'group' => 'operations', 'count' => $device->maintenanceRecords->count()] : null,
            ['key' => 'documents', 'label' => 'Documents', 'group' => 'records', 'count' => $device->documents->count()],
            $canViewAudit ? ['key' => 'audit', 'label' => 'Audit', 'group' => 'records', 'count' => $audit->count()] : null,
        ])->filter()->values()->all();
    }

    /** @return array<string, mixed> */
    private function health(Device $device, Collection $monitors, bool $canViewMonitoring): array
    {
        $enabled = $monitors->where('is_enabled', true);

        return [
            'state' => $device->health_status?->value ?? 'unknown',
            'deviceState' => $device->status?->value,
            'lastSeenAt' => $device->last_seen_at?->toISOString(),
            'lastSignalAt' => $device->last_signal_at?->toISOString(),
            'batteryLevel' => $device->battery_level,
            'batteryUpdatedAt' => $device->battery_updated_at?->toISOString(),
            'monitoring' => $canViewMonitoring ? [
                'enabled' => $enabled->count(),
                'healthy' => $enabled->filter(fn (Monitor $monitor): bool => $monitor->current_state?->value === 'healthy')->count(),
                'attention' => $enabled->filter(fn (Monitor $monitor): bool => in_array($monitor->current_state?->value, ['failed', 'degraded'], true))->count(),
                'uncertain' => $enabled->filter(fn (Monitor $monitor): bool => in_array($monitor->current_state?->value, ['unknown', 'stale', 'pending'], true))->count(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function monitor(Monitor $monitor): array
    {
        return [
            'id' => $monitor->id,
            'name' => $monitor->name,
            'kind' => $monitor->kind?->value,
            'kindLabel' => $this->monitorKindLabel($monitor->kind),
            'state' => $monitor->current_state?->value,
            'enabled' => (bool) $monitor->is_enabled,
            'affectsAvailability' => (bool) $monitor->affects_availability,
            'lastObservationAt' => $monitor->last_observation_at?->toISOString(),
            'lastStateChangedAt' => $monitor->last_state_changed_at?->toISOString(),
            'suppressedUntil' => $monitor->suppressed_until?->toISOString(),
            'profile' => $monitor->profile ? [
                'name' => $monitor->profile->name,
                'intervalSeconds' => $monitor->profile->interval_seconds,
                'staleAfterSeconds' => $monitor->profile->stale_after_seconds,
            ] : null,
            'collector' => $monitor->collector ? [
                'name' => $monitor->collector->name,
                'status' => $monitor->collector->status,
                'lastSeenAt' => $monitor->collector->last_seen_at?->toISOString(),
            ] : null,
        ];
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

    /** @param Collection<int, int> $monitorIds @return Collection<int, MonitorObservation> */
    private function latestObservations(Collection $monitorIds): Collection
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

    /** @return array<int, array<string, mixed>> */
    private function interfacesAndSensors(Collection $monitors, Collection $observations): array
    {
        return $monitors->filter(function (Monitor $monitor) use ($observations): bool {
            if ($monitor->kind === MonitorKind::SnmpInterface) {
                return true;
            }

            $metrics = $observations->get($monitor->id)?->metrics;

            return $monitor->kind === MonitorKind::Snmp
                && is_array($metrics)
                && collect(['interface_name', 'if_name', 'sensor_name', 'sensor_type'])
                    ->contains(fn (string $key): bool => data_get($metrics, $key) !== null);
        })->map(function (Monitor $monitor) use ($observations): array {
            $observation = $observations->get($monitor->id);
            $metrics = is_array($observation?->metrics) ? $observation->metrics : [];

            return [
                'monitorId' => $monitor->id,
                'name' => $this->identifierMetric($metrics, ['interface_name', 'if_name', 'sensor_name']) ?: $monitor->name,
                'kind' => $monitor->kind?->value,
                'state' => $monitor->current_state?->value,
                'value' => $this->numeric($observation?->value),
                'unit' => $this->safeUnit($observation?->unit),
                'index' => $this->boundedIntegerMetric($metrics, ['if_index', 'interface_index']),
                'adminStatus' => $this->statusMetric($metrics, ['admin_status']),
                'operationalStatus' => $this->statusMetric($metrics, ['operational_status', 'oper_status']),
                'speedBps' => $this->boundedIntegerMetric($metrics, ['speed_bps', 'interface_speed_bps']),
                'inBps' => $this->boundedIntegerMetric($metrics, ['in_bps', 'inbound_bps']),
                'outBps' => $this->boundedIntegerMetric($metrics, ['out_bps', 'outbound_bps']),
                'inUtilisation' => $this->percentageMetric($metrics, ['in_utilization_pct', 'in_utilisation_pct']),
                'outUtilisation' => $this->percentageMetric($metrics, ['out_utilization_pct', 'out_utilisation_pct']),
                'errors' => $this->boundedIntegerMetric($metrics, ['errors', 'error_count']),
                'discards' => $this->boundedIntegerMetric($metrics, ['discards', 'discard_count']),
                'observedAt' => $observation?->observed_at?->toISOString(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function configuration(Device $device): array
    {
        $observedHash = $this->scalarEvidence($device, [
            'observed.configuration_hash',
            'monitoring.configuration.observed_hash',
            'configuration.observed_hash',
        ]);
        $desiredHash = $this->scalarEvidence($device, [
            'desired.configuration_hash',
            'monitoring.configuration.desired_hash',
            'configuration.desired_hash',
        ]);
        $desiredFirmware = $this->scalarEvidence($device, [
            'desired.firmware_version',
            'monitoring.firmware.desired_version',
            'firmware.desired_version',
        ]);

        return [
            'registry' => [
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'serialNumber' => $device->serial_number,
                'assetTag' => $device->asset_tag,
                'ipAddress' => $device->ip_address,
                'macAddress' => $device->mac_address,
                'imei' => $device->imei,
                'commissionedAt' => $device->commissioned_at?->toDateString(),
                'warrantyExpiresAt' => $device->warranty_expires_at?->toDateString(),
                'nextServiceDue' => $device->next_service_due?->toDateString(),
                'expectedLifespanMonths' => $device->expected_lifespan_months,
                'purchasePrice' => $device->purchase_price,
                'notes' => $device->notes,
                'groups' => $device->relationLoaded('groups')
                    ? $device->groups
                        ->map(fn ($group): array => ['id' => $group->id, 'name' => $group->name])
                        ->values()
                    : [],
                'createdBy' => $device->relationLoaded('createdBy')
                    && $device->createdBy
                        ? ['id' => $device->createdBy->id, 'name' => $device->createdBy->name]
                        : null,
                'createdAt' => $device->created_at?->toISOString(),
            ],
            'configuration' => [
                'state' => match (true) {
                    $observedHash === null && $desiredHash === null => 'not_observed',
                    $observedHash !== null && $desiredHash !== null && ! hash_equals($observedHash, $desiredHash) => 'drifted',
                    $observedHash !== null && $desiredHash !== null => 'aligned',
                    $observedHash !== null => 'observed',
                    default => 'desired_only',
                },
                'observedHash' => $observedHash,
                'desiredHash' => $desiredHash,
                'observedAt' => $this->dateEvidence($device, [
                    'observed.configuration_at',
                    'observed.configuration_observed_at',
                    'monitoring.configuration.observed_at',
                ]),
            ],
            'firmware' => [
                'state' => match (true) {
                    $device->firmware_version === null && $desiredFirmware === null => 'not_observed',
                    $device->firmware_version !== null && $desiredFirmware !== null && $device->firmware_version !== $desiredFirmware => 'update_available',
                    $device->firmware_version !== null && $desiredFirmware !== null => 'aligned',
                    $device->firmware_version !== null => 'observed',
                    default => 'desired_only',
                },
                'currentVersion' => $device->firmware_version,
                'desiredVersion' => $desiredFirmware,
                'observedAt' => $this->dateEvidence($device, [
                    'observed.firmware_at',
                    'observed.firmware_observed_at',
                    'monitoring.firmware.observed_at',
                ]),
            ],
        ];
    }

    private function scalarEvidence(Device $device, array $paths): ?string
    {
        foreach ([$device->meta ?? [], $device->config ?? []] as $source) {
            foreach ($paths as $path) {
                $value = data_get($source, $path);
                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    private function dateEvidence(Device $device, array $paths): ?string
    {
        $value = $this->scalarEvidence($device, $paths);
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private function tickets(User $viewer, Device $device): Collection
    {
        return ItTicketLink::query()
            ->where('relationship', 'affected_device')
            ->where('linkable_type', Device::class)
            ->where('linkable_id', $device->id)
            ->with('ticket')
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (ItTicketLink $link): bool => $link->ticket !== null && Gate::forUser($viewer)->allows('view', $link->ticket))
            ->map(fn (ItTicketLink $link): array => [
                'id' => $link->ticket->id,
                'reference' => $link->ticket->reference,
                'title' => $link->ticket->title,
                'status' => $link->ticket->status,
                'priority' => $link->ticket->priority,
                'workType' => $link->ticket->work_type,
                'nextAction' => $link->ticket->next_action,
                'updatedAt' => $link->ticket->updated_at?->toISOString(),
                'href' => "/it/tickets/{$link->ticket->id}",
            ])
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function controlRoomAlerts(User $viewer, Device $device): Collection
    {
        $query = ControlRoomAlert::query()
            ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
            ->whereHas('device', fn (Builder $projection): Builder => $projection
                ->where('canonical_device_id', $device->id))
            ->with('device:id,canonical_device_id')
            ->latest('triggered_at')
            ->limit(50);

        $siteIds = $this->deviceAccess->accessibleSiteIds($viewer);
        $clientIds = $this->deviceAccess->authorizedClientIds($viewer);
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');
        } else {
            // Control Room remains the owner of alert visibility. Within the
            // device profile, preserve its direct Site/client precedence using
            // the same canonical records as the device access boundary.
            $query->where(function (Builder $privacy) use ($clientIds): void {
                $privacy->whereNull('client_id');
                if ($clientIds !== []) {
                    $privacy->orWhereIn('client_id', $clientIds);
                }
            })->where(function (Builder $scope) use ($siteIds, $clientIds): void {
                $scope->whereIn('site_id', $siteIds)
                    ->orWhere(function (Builder $fallback) use ($siteIds, $clientIds): void {
                        $fallback->whereNull('site_id')
                            ->where(function (Builder $context) use ($siteIds, $clientIds): void {
                                if ($clientIds !== []) {
                                    $context->whereIn('client_id', $clientIds);
                                } else {
                                    $context->whereRaw('1 = 0');
                                }
                                $context->orWhere(function (Builder $projection) use ($siteIds): void {
                                    $projection->whereNull('client_id')
                                        ->whereHas('device', fn (Builder $device): Builder => $device
                                            ->whereIn('site_id', $siteIds));
                                });
                            });
                    });
            });
        }

        return $query->get()->map(fn (ControlRoomAlert $alert): array => [
            'id' => $alert->id,
            'reference' => $alert->reference_number,
            'type' => $alert->alert_type,
            'severity' => $alert->getRawOriginal('severity'),
            'status' => $alert->status,
            'triggeredAt' => $alert->triggered_at?->toISOString(),
            'href' => "/control-room/alerts/{$alert->id}",
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function audit(Device $device): Collection
    {
        return AuditLog::query()
            ->where('auditable_type', Device::class)
            ->where('auditable_id', $device->id)
            ->with('user:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'actor' => $entry->user?->name,
                'fields' => $this->auditFields($entry->meta ?? []),
                'createdAt' => $entry->created_at?->toISOString(),
            ]);
    }

    /** @return array<int, string> */
    private function auditFields(array $meta): array
    {
        $fields = $meta['fields'] ?? [];
        if (! is_array($fields)) {
            return [];
        }

        if (! array_is_list($fields)) {
            $fields = array_keys($fields);
        }

        return collect($fields)
            ->filter(fn ($field): bool => is_string($field) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $field) === 1)
            ->take(12)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function capabilities(User $viewer, Device $device): array
    {
        $canUpdate = $viewer->canDo('securityDevices.devices.update');
        $canAssign = $viewer->canDo('securityDevices.devices.assign');
        $canViewMonitoring = $viewer->canDo('securityDevices.events.view');
        $canManageMaintenance = $viewer->canDo('securityDevices.maintenance.manage');
        $active = $device->status !== DeviceStatus::Decommissioned;
        $monitoringEvidence = $this->monitoringCapabilityEvidence($device);
        $maintenanceEvidence = $this->capabilityEvidence($device, 'maintenance');
        if ($maintenanceEvidence === null && (
            ($device->relationLoaded('maintenanceRecords') && $device->maintenanceRecords->isNotEmpty())
            || $device->next_service_due !== null
            || $device->commissioned_at !== null
            || $device->warranty_expires_at !== null
        )) {
            $maintenanceEvidence = true;
        }
        $controlEvidence = $this->capabilityEvidence($device, 'control');

        return [
            'registry' => $this->capability(true, $canUpdate && $active, 'supported'),
            'assignment' => $this->capability(true, $canAssign && $active, 'supported'),
            'monitoring' => $this->capabilityFromEvidence($monitoringEvidence, $canViewMonitoring && $active),
            'maintenance' => $this->capabilityFromEvidence($maintenanceEvidence, $canManageMaintenance && $active),
            'documents' => $this->capability(true, $canUpdate && $active, 'supported'),
            'control' => [
                'supported' => $controlEvidence === true,
                'allowed' => false,
                'available' => false,
                'state' => match ($controlEvidence) {
                    true => 'supported_governance_unavailable',
                    false => 'unsupported',
                    default => 'unknown_not_configured',
                },
                'reason' => 'Remote control remains unavailable until governed command planning, approval, execution, and reconciliation are enabled for this device capability.',
            ],
        ];
    }

    private function monitoringCapabilityEvidence(Device $device): ?bool
    {
        $evidence = $this->capabilityEvidence($device, 'monitoring');
        if ($evidence === null && $device->relationLoaded('monitors') && $device->monitors->isNotEmpty()) {
            return true;
        }

        return $evidence;
    }

    /** @return array{supported: bool, allowed: bool, available: bool, state: string} */
    private function capability(bool $supported, bool $allowed, string $state): array
    {
        return [
            'supported' => $supported,
            'allowed' => $allowed,
            'available' => $supported && $allowed,
            'state' => $state,
        ];
    }

    /** @return array{supported: bool, allowed: bool, available: bool, state: string} */
    private function capabilityFromEvidence(?bool $evidence, bool $allowed): array
    {
        return $this->capability(
            $evidence === true,
            $allowed,
            match ($evidence) {
                true => 'supported',
                false => 'unsupported',
                default => 'unknown_not_configured',
            },
        );
    }

    private function capabilityEvidence(Device $device, string $capability): ?bool
    {
        foreach ([$device->meta ?? [], $device->config ?? []] as $source) {
            foreach (["capabilities.{$capability}", "observed.capabilities.{$capability}"] as $path) {
                $value = data_get($source, $path);
                if (is_bool($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    $normalized = strtolower(trim($value));
                    if (in_array($normalized, ['supported', 'enabled', 'available', 'true'], true)) {
                        return true;
                    }
                    if (in_array($normalized, ['unsupported', 'disabled', 'unavailable', 'false'], true)) {
                        return false;
                    }
                }
            }
        }

        return null;
    }

    private function identifierMetric(array $metrics, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($metrics, $key);
            if (! is_scalar($value)) {
                continue;
            }

            $candidate = trim((string) $value);
            if (preg_match('/^[\pL\pN][\pL\pN ._:\/\-]{0,79}$/u', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    private function statusMetric(array $metrics, array $keys): ?string
    {
        $status = strtolower($this->identifierMetric($metrics, $keys) ?? '');

        return in_array($status, [
            'up', 'down', 'testing', 'unknown', 'dormant', 'not present',
            'lower layer down', 'enabled', 'disabled', 'active', 'inactive',
            'ok', 'warning', 'critical', 'healthy', 'degraded', 'failed',
        ], true) ? $status : null;
    }

    private function safeUnit(mixed $unit): ?string
    {
        if (! is_string($unit)) {
            return null;
        }

        $unit = trim($unit);

        return in_array($unit, [
            '%', 'bps', 'Kbps', 'Mbps', 'Gbps', 'Bps', 'KBps', 'MBps',
            'ms', 's', 'Hz', 'kHz', 'MHz', 'GHz', 'V', 'mV', 'A', 'mA',
            'W', 'kW', 'dBm', 'C', '°C', 'F', '°F', 'ppm', 'lux',
        ], true) ? $unit : null;
    }

    private function boundedIntegerMetric(array $metrics, array $keys): ?int
    {
        $value = $this->firstNumericMetric($metrics, $keys);

        return $value === null || $value < 0 || $value > PHP_INT_MAX ? null : (int) $value;
    }

    private function percentageMetric(array $metrics, array $keys): ?float
    {
        $value = $this->firstNumericMetric($metrics, $keys);

        return $value === null || $value < 0 || $value > 100 ? null : (float) $value;
    }

    private function firstNumericMetric(array $metrics, array $keys): int|float|null
    {
        foreach ($keys as $key) {
            $value = data_get($metrics, $key);
            if (is_numeric($value)) {
                return $value + 0;
            }
        }

        return null;
    }

    private function numeric(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && abs($number) <= 1_000_000_000_000 ? $number : null;
    }
}
