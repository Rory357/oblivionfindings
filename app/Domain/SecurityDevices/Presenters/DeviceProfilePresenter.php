<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandChangeEligibilityService;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\CommandObservationFreshnessService;
use App\Domain\SecurityDevices\Management\Services\CommandParameterValidator;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandBreakGlassService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandParameterPolicyService;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
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
use App\Services\Queclink\QueclinkConfigurationProfileService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

class DeviceProfilePresenter
{
    public function __construct(
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly DeviceManagementAuthorizationService $managementAuthorization,
        private readonly CommandCapabilityRegistry $commandCapabilities,
        private readonly CommandAssignmentFingerprint $commandAssignments,
        private readonly DeclaredDeviceCommandCapabilities $declaredCommandCapabilities,
        private readonly CommandExecutionRouteResolver $commandExecutionRoutes,
        private readonly CommandObservationFreshnessService $commandFreshness,
        private readonly CommandParameterValidator $commandParameters,
        private readonly DeviceCommandParameterPolicyService $commandParameterPolicy,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly CommandChangeEligibilityService $changeEligibility,
        private readonly DeviceCommandBreakGlassService $breakGlass,
        private readonly ItWorkAccessService $itWorkAccess,
        private readonly QueclinkConfigurationProfileService $configurationProfiles,
        private readonly UserSiteAccessService $siteAccess,
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
        $management = $this->management($viewer, $device);

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
                (bool) $management['visible'],
                count($management['history']),
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
            'management' => $management,
            'capabilities' => $this->capabilities($viewer, $device, $management),
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
            DeviceAssignment::TARGET_STAFF => $this->staffLocation($viewer, $assignment),
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

        $asset = Asset::query()
            ->with('categoryRef:id,slug')
            ->find($assignment->assignable_id, [
                'id',
                'name',
                'registration_number',
                'category',
                'asset_category_id',
                'site_id',
                'client_id',
            ]);

        return $asset ? [
            'id' => $asset->id,
            'type' => 'vehicle',
            'name' => $asset->name ?: $asset->registration_number,
            'href' => $this->assetHref($viewer, $asset),
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function staffLocation(User $viewer, DeviceAssignment $assignment): ?array
    {
        $staff = User::query()->find($assignment->assignable_id, ['id', 'name']);

        return $staff ? [
            'id' => $staff->id,
            'type' => 'staff',
            'name' => $staff->name,
            'href' => $this->staffProfileHref($viewer, $staff->id),
        ] : null;
    }

    private function staffProfileHref(User $viewer, int $staffId): ?string
    {
        if (! $viewer->canDo('hr.employees.viewAny')) {
            return null;
        }

        $visibleStaff = User::query()->whereKey($staffId)->select('users.id');
        $this->siteAccess->applyStaffScope($visibleStaff, $viewer);
        $profileId = HrEmployeeProfile::query()
            ->whereIn('user_id', $visibleStaff)
            ->value('id');

        return $profileId ? "/hr/people/{$profileId}" : null;
    }

    private function assetHref(User $viewer, Asset $asset): ?string
    {
        $isVehicle = strtolower(trim((string) $asset->category)) === 'vehicle'
            || $asset->categoryRef?->slug === 'vehicle';
        if ($isVehicle && $viewer->canDo('fleet.viewAny')) {
            return "/fleet-assets/vehicles/{$asset->id}";
        }

        $canUseAssetRoute = ($viewer->canDo('assets.viewAny') || $viewer->canDo('assets.viewAssigned'))
            && Gate::forUser($viewer)->allows('view', $asset);

        return $canUseAssetRoute ? "/fleet-assets/assets/{$asset->id}" : null;
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
        bool $canViewManagement,
        int $commandCount,
    ): array {
        return collect([
            ['key' => 'health', 'label' => 'Health', 'group' => 'status'],
            $canViewMonitoring ? ['key' => 'monitors', 'label' => 'Monitors', 'group' => 'status', 'count' => $monitors->count()] : null,
            ['key' => 'topology', 'label' => 'Topology', 'group' => 'technical'],
            $canViewMonitoring ? ['key' => 'interfaces-sensors', 'label' => 'Interfaces & sensors', 'group' => 'technical'] : null,
            ['key' => 'configuration', 'label' => 'Configuration', 'group' => 'technical'],
            $canViewManagement ? ['key' => 'management', 'label' => 'Management', 'group' => 'technical', 'count' => $commandCount] : null,
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
    private function capabilities(User $viewer, Device $device, array $management): array
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
        $controlActions = collect($management['actions'])->where('level', 'control');
        $controlSupported = $controlActions->isNotEmpty();
        $controlAllowed = $controlActions->contains(fn (array $action): bool => $action['allowed']);
        $controlAvailable = $controlActions->contains(fn (array $action): bool => $action['available']);

        return [
            'registry' => $this->capability(true, $canUpdate && $active, 'supported'),
            'assignment' => $this->capability(true, $canAssign && $active, 'supported'),
            'monitoring' => $this->capabilityFromEvidence($monitoringEvidence, $canViewMonitoring && $active),
            'maintenance' => $this->capabilityFromEvidence($maintenanceEvidence, $canManageMaintenance && $active),
            'documents' => $this->capability(true, $canUpdate && $active, 'supported'),
            'control' => [
                'supported' => $controlSupported,
                'allowed' => $controlAllowed,
                'available' => $controlAvailable,
                'state' => $controlAvailable
                    ? 'governed_management_available'
                    : ($controlSupported ? 'supported_but_blocked' : 'unknown_not_configured'),
                'reason' => $controlAvailable
                    ? 'Supported controls use reason, step-up, approval, signed dispatch, and fresh-state reconciliation.'
                    : ($controlSupported
                        ? 'This device declares management support, but your permission or an approved provider execution adapter is missing.'
                        : 'No exact provider management capability has been declared for this device.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function management(User $viewer, Device $device): array
    {
        $this->managementAuthorization->resetMemoizedState();
        $canObserve = $this->managementAuthorization->allowsLevel($viewer, ManagementLevel::Observe);
        $freshness = $this->commandFreshness->inspect($device);
        $compatibleConfigurationProfiles = $this->configurationProfiles->compatibleProfiles($device);
        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
            $assignmentFingerprint = $this->commandAssignments->forDevice($device);
            $eligibleChanges = $this->changeEligibility->eligibleFor($viewer, $device, $siteId);
        } catch (UnexpectedValueException) {
            $siteId = null;
            $assignmentFingerprint = null;
            $eligibleChanges = collect();
        }
        $actions = $this->declaredCommandCapabilities->forDevice($device)
            ->map(function (string $key) use ($viewer, $device, $eligibleChanges, $freshness, $siteId, $compatibleConfigurationProfiles): ?array {
                try {
                    $definition = $this->commandCapabilities->definition($key);
                } catch (\DomainException) {
                    return null;
                }

                $authorization = $this->managementAuthorization->evaluate(
                    $viewer,
                    $device,
                    $definition,
                );
                if (! $authorization->allowed && $authorization->concealed) {
                    return null;
                }
                $allowed = $authorization->allowed;
                $route = $siteId === null
                    ? null
                    : $this->commandExecutionRoutes->resolve($device, $siteId, $key);
                $adapterAvailable = $route?->available === true;
                $deviceStateAllowed = in_array($device->status?->value, $definition->allowedCurrentStates, true);
                $governanceAvailable = ! $definition->requiresChange || $eligibleChanges->isNotEmpty();
                $observationCurrent = ! $definition->requiresFreshObservation || $freshness->isFresh();
                $mfaCurrent = ! $definition->requiresMfa || $viewer->two_factor_confirmed_at !== null;
                $parameterOptionsAvailable = $key !== 'configuration.apply' || $compatibleConfigurationProfiles->isNotEmpty();
                $available = $deviceStateAllowed
                    && $allowed
                    && $adapterAvailable
                    && $governanceAvailable
                    && $observationCurrent
                    && $mfaCurrent
                    && $parameterOptionsAvailable;

                return [
                    'key' => $key,
                    'label' => $definition->label,
                    'domain' => $definition->domain,
                    'group' => str_starts_with($key, 'diagnostics.')
                        ? 'diagnostics'
                        : ($definition->isHighRisk() ? 'high_risk_control' : 'standard_management'),
                    'level' => $definition->level->value,
                    'risk' => $definition->risk->value,
                    'workspace' => $authorization->workspace,
                    'sensitivity' => $authorization->sensitivity,
                    'impact' => $definition->impact,
                    'expectedResult' => $definition->expectedResult,
                    'confirmationMode' => $definition->confirmationMode->value,
                    'executionMode' => $route?->mode === 'collector'
                        ? 'collector_runtime'
                        : ($adapterAvailable ? 'central_runtime' : 'unavailable'),
                    'executionGuidance' => $route?->reason
                        ?? 'No approved central or remote collector execution route is available.',
                    'allowed' => $allowed,
                    'adapterAvailable' => $adapterAvailable,
                    'available' => $available,
                    'state' => $available
                        ? 'available'
                        : (! $allowed
                            ? $authorization->code
                            : (! $adapterAvailable
                                ? 'provider_adapter_required'
                                : (! $governanceAvailable
                                    ? 'change_workflow_required'
                                    : (! $deviceStateAllowed
                                        ? 'device_state_blocked'
                                        : (! $observationCurrent
                                            ? ($freshness->state === 'never_observed' ? 'observation_required' : 'observation_stale')
                                            : (! $mfaCurrent ? 'mfa_required' : 'configuration_profile_required')))))),
                    'reason' => $available
                        ? 'Governed request available.'
                        : (! $allowed
                            ? $authorization->reason
                            : (! $adapterAvailable
                                ? 'The provider has not registered an approved execution and reconciliation adapter for this action.'
                                : (! $governanceAvailable
                                    ? 'No current approved IT Change is linked to this Device and Site within its maintenance window.'
                                    : (! $deviceStateAllowed
                                        ? 'The action is blocked by the current Device state.'
                                        : (! $observationCurrent
                                            ? ($freshness->state === 'never_observed'
                                                ? 'A current Device observation is required before this action can be requested.'
                                                : 'The last confirmed Device observation is stale. Refresh monitoring evidence before requesting this action.')
                                            : (! $mfaCurrent
                                                ? 'Configured multi-factor authentication is required for this critical action.'
                                                : 'No active desired configuration profile is approved for this provider and Device class.')))))),
                    'requiresStepUp' => $definition->requiresStepUp,
                    'requiresMfa' => $definition->requiresMfa,
                    'requiresFreshObservation' => $definition->requiresFreshObservation,
                    'freshness' => [
                        'state' => $freshness->state,
                        'observedAt' => $freshness->observedAt?->toISOString(),
                        'staleAfterSeconds' => $freshness->staleAfterSeconds,
                    ],
                    'requiresApproval' => $definition->requiresApproval,
                    'requiresChange' => $definition->requiresChange,
                    'allowsBreakGlass' => $definition->allowsBreakGlass,
                    'expiresAfterSeconds' => $definition->expiresAfterSeconds,
                    'parameters' => collect($definition->parameters)
                        ->map(function (array $schema, string $name) use ($compatibleConfigurationProfiles): array {
                            $profileSource = ($schema['source'] ?? null) === 'compatible_configuration_profiles';

                            return [
                                'name' => $name,
                                'label' => $profileSource ? 'Approved configuration profile' : Str::headline($name),
                                'type' => $schema['type'],
                                'min' => $schema['min'] ?? null,
                                'max' => $schema['max'] ?? $schema['max_length'] ?? null,
                                'options' => $profileSource
                                    ? $compatibleConfigurationProfiles->pluck('id')->map(fn (int $id): string => (string) $id)->all()
                                    : ($schema['enum'] ?? []),
                                'optionLabels' => $profileSource
                                    ? $compatibleConfigurationProfiles->mapWithKeys(fn ($profile): array => [
                                        (string) $profile->id => $profile->name.' · v'.$profile->version,
                                    ])->all()
                                    : [],
                            ];
                        })->values()->all(),
                ];
            })
            ->filter()
            ->sortBy(fn (array $action): string => $action['level'].'|'.$action['label'])
            ->values();
        $breakGlassReviewers = $viewer->canDo('securityDevices.commands.admin')
            && $viewer->two_factor_confirmed_at !== null
            && $actions->contains(fn (array $action): bool => $action['allowsBreakGlass'])
                ? $this->breakGlass->eligibleReviewers(
                    $viewer,
                    $device,
                    $actions
                        ->where('allowsBreakGlass', true)
                        ->pluck('key')
                        ->values()
                        ->all(),
                )
                : collect();

        $now = Carbon::now('UTC')->startOfSecond();
        $history = $canObserve
            ? DeviceCommandRequest::query()
                ->where('device_id', $device->id)
                ->with([
                    'change.ticket:id,reference,title',
                    'device',
                    'requestedBy:id,name,approved_at,two_factor_confirmed_at',
                    'approvedBy:id,name',
                    'breakGlassReviewer:id,name,approved_at,two_factor_confirmed_at',
                    'breakGlassReviewedBy:id,name',
                    'attempts',
                    'reconciliations',
                ])
                ->latest('id')
                ->limit(30)
                ->get()
                ->filter(function (DeviceCommandRequest $command) use ($viewer): bool {
                    try {
                        $definition = $this->commandCapabilities->definition($command->capability);
                    } catch (\DomainException) {
                        return false;
                    }

                    return $this->managementAuthorization->evaluate(
                        $viewer,
                        $command->device,
                        $definition,
                        ManagementLevel::Observe,
                    )->allowed;
                })
                ->map(function (DeviceCommandRequest $command) use (
                    $viewer,
                    $now,
                    $siteId,
                    $assignmentFingerprint,
                    $freshness,
                ): array {
                    try {
                        $definition = $this->commandCapabilities->definition($command->capability);
                        $label = $definition->label;
                    } catch (\DomainException) {
                        $definition = null;
                        $label = Str::headline($command->capability);
                    }
                    $latestAttempt = $command->attempts->sortByDesc('attempt_number')->first();
                    $latestReconciliation = $command->reconciliations->sortByDesc('observed_at')->first();
                    $canViewChange = $command->change?->ticket !== null
                        && $this->itWorkAccess->canView($viewer, $command->change->ticket);
                    $changeRequired = $definition?->requiresChange ?? $command->it_change_id !== null;
                    $changeGovernanceCurrent = $command->is_break_glass
                        || ! $changeRequired
                        || ($command->it_change_id !== null
                            && $command->requestedBy !== null
                            && $this->changeEligibility->isEligible(
                                (int) $command->it_change_id,
                                $command->requestedBy,
                                $command->device,
                                (int) $command->site_id,
                                $now->toImmutable(),
                            )
                            && $this->changeEligibility->isEligible(
                                (int) $command->it_change_id,
                                $viewer,
                                $command->device,
                                (int) $command->site_id,
                                $now->toImmutable(),
                            ));
                    $deviceState = $command->device->status?->value ?? (string) $command->device->status;
                    $parameterPolicyCurrent = false;
                    if ($definition !== null) {
                        try {
                            $parameters = $this->commandParameters->validate(
                                $definition,
                                $command->encrypted_parameters ?? [],
                            );
                            $this->commandParameterPolicy->assertAllowed(
                                $command->device,
                                $definition,
                                $parameters,
                            );
                            $parameterPolicyCurrent = true;
                        } catch (Throwable) {
                            $parameterPolicyCurrent = false;
                        }
                    }
                    $stepUpCurrent = $definition === null
                        || ! $definition->requiresStepUp
                        || ($command->step_up_confirmed_at !== null
                            && ! $command->step_up_confirmed_at->isFuture()
                            && $command->step_up_confirmed_at->greaterThanOrEqualTo(
                                $now->copy()->subSeconds(max(60, (int) config('security_devices.step_up_max_age_seconds', 900))),
                            ));
                    $requesterAuthorizationCurrent = $definition !== null
                        && $command->requestedBy !== null
                        && $this->managementAuthorization->evaluate(
                            $command->requestedBy,
                            $command->device,
                            $definition,
                        )->allowed;
                    $viewerActionAuthorized = $definition !== null
                        && $this->managementAuthorization->evaluate(
                            $viewer,
                            $command->device,
                            $definition,
                        )->allowed;
                    $commandPreconditionsCurrent = $definition !== null
                        && $command->expires_at?->isAfter($now)
                        && $command->risk === $definition->risk
                        && $command->management_level === $definition->level
                        && $command->confirmation_mode === $definition->confirmationMode
                        && (! $definition->isHighRisk() || $command->impact_acknowledged_at !== null)
                        && $siteId !== null
                        && (int) $command->site_id === $siteId
                        && is_string($assignmentFingerprint)
                        && is_string($command->assignment_fingerprint)
                        && hash_equals($command->assignment_fingerprint, $assignmentFingerprint)
                        && $command->provider === $command->device->provider
                        && in_array($deviceState, $definition->allowedCurrentStates, true)
                        && $this->declaredCommandCapabilities->supports($command->device, $command->capability)
                        && $changeGovernanceCurrent
                        && $parameterPolicyCurrent
                        && $stepUpCurrent
                        && $requesterAuthorizationCurrent
                        && (! $definition->requiresFreshObservation || $freshness->isFresh())
                        && (! $definition->requiresMfa || $command->requestedBy?->two_factor_confirmed_at !== null);
                    $canViewBreakGlassDetail = $command->is_break_glass
                        && ((int) $command->requested_by_user_id === (int) $viewer->id
                            || (int) $command->break_glass_reviewer_user_id === (int) $viewer->id
                            || $viewer->canDo('securityDevices.commands.admin'));

                    return [
                        'id' => $command->id,
                        'uuid' => $command->command_uuid,
                        'capability' => $command->capability,
                        'label' => $label,
                        'status' => $command->status->value,
                        'risk' => $command->risk->value,
                        'confirmationMode' => $command->confirmation_mode?->value,
                        'impactAcknowledgedAt' => $command->impact_acknowledged_at?->toISOString(),
                        'reason' => $command->reason,
                        'safeParameters' => $command->safe_parameter_summary ?? [],
                        'expectedState' => $command->expected_state ?? [],
                        'requestedBy' => $command->requestedBy?->name,
                        'approvedBy' => $command->approvedBy?->name,
                        'isBreakGlass' => $command->is_break_glass,
                        'breakGlass' => $command->is_break_glass ? [
                            'reviewer' => $command->breakGlassReviewer?->name,
                            'emergencyReason' => $canViewBreakGlassDetail ? $command->break_glass_reason : null,
                            'declaredAt' => $command->break_glass_declared_at?->toISOString(),
                            'notificationSentAt' => $command->break_glass_notification_sent_at?->toISOString(),
                            'reviewDueAt' => $command->break_glass_review_due_at?->toISOString(),
                            'reviewedBy' => $command->breakGlassReviewedBy?->name,
                            'reviewedAt' => $command->break_glass_reviewed_at?->toISOString(),
                            'outcome' => $command->break_glass_review_outcome?->value,
                            'reviewSummary' => $canViewBreakGlassDetail ? $command->break_glass_review_summary : null,
                            'overdue' => $command->break_glass_reviewed_at === null
                                && $command->break_glass_review_due_at?->lessThanOrEqualTo($now),
                        ] : null,
                        'requestedAt' => $command->created_at?->toISOString(),
                        'expiresAt' => $command->expires_at?->toISOString(),
                        'reconciledAt' => $command->reconciled_at?->toISOString(),
                        'safeFailureReason' => $command->safe_failure_reason,
                        'blockedReasonCode' => $command->blocked_reason_code,
                        'blockedAt' => $command->blocked_at?->toISOString(),
                        'change' => $canViewChange ? [
                            'id' => $command->change->id,
                            'reference' => $command->change->ticket->reference,
                            'title' => $command->change->ticket->title,
                        ] : null,
                        'nextAction' => $this->commandNextAction($command->status, $commandPreconditionsCurrent),
                        'evidenceExportHref' => "/security-devices/devices/{$command->device_id}/commands/{$command->id}/evidence",
                        'executionRoute' => $command->execution_route,
                        'latestAttempt' => $latestAttempt ? [
                            'number' => $latestAttempt->attempt_number,
                            'status' => $latestAttempt->status->value,
                            'runtime' => $latestAttempt->runtime,
                            'safeResult' => $latestAttempt->safe_result_summary ?? [],
                            'safeFailureReason' => $latestAttempt->safe_failure_reason,
                            'completedAt' => $latestAttempt->completed_at?->toISOString(),
                        ] : null,
                        'latestReconciliation' => $latestReconciliation ? [
                            'outcome' => $latestReconciliation->outcome->value,
                            'observedState' => $latestReconciliation->observed_state ?? [],
                            'safeEvidenceSummary' => $latestReconciliation->safe_evidence_summary,
                            'observedAt' => $latestReconciliation->observed_at?->toISOString(),
                        ] : null,
                        'canDecide' => $command->status->value === 'awaiting_approval'
                            && (int) $command->requested_by_user_id !== (int) $viewer->id
                            && $viewer->canDo('securityDevices.commands.approve')
                            && $commandPreconditionsCurrent,
                        'canDispatch' => $command->status->value === 'ready'
                            && ((int) $command->requested_by_user_id === (int) $viewer->id
                                || $viewer->canDo('securityDevices.commands.admin'))
                            && $viewerActionAuthorized,
                        'dispatchPreconditionsCurrent' => $commandPreconditionsCurrent
                            && $this->breakGlass->isDispatchable($command)
                            && $this->commandExecutionRoutes->matches(
                                $command->device,
                                (int) $command->site_id,
                                $command->capability,
                                $command->collector_id === null ? null : (int) $command->collector_id,
                            ),
                        'canReviewBreakGlass' => $command->is_break_glass
                            && $command->execution_completed_at !== null
                            && $command->break_glass_reviewed_at === null
                            && (int) $command->break_glass_reviewer_user_id === (int) $viewer->id
                            && $viewer->canDo('securityDevices.commands.admin'),
                    ];
                })->values()->all()
            : [];

        $canUseAnyAction = $actions->contains(fn (array $action): bool => $action['allowed']);

        return [
            'visible' => $canObserve || $canUseAnyAction,
            'actions' => $actions->all(),
            'history' => $history,
            'canObserve' => $canObserve,
            'canApprove' => $viewer->canDo('securityDevices.commands.approve'),
            'stepUpCurrent' => $this->stepUpCurrent($now),
            'changeOptions' => $eligibleChanges->map(fn ($change): array => [
                'id' => $change->id,
                'reference' => $change->ticket->reference,
                'title' => $change->ticket->title,
                'workflowState' => $change->ticket->workflow_state,
                'maintenanceEndsAt' => $change->maintenance_ends_at?->toISOString(),
            ])->values()->all(),
            'breakGlassReviewers' => $breakGlassReviewers->map(fn (User $reviewer): array => [
                'id' => (int) $reviewer->id,
                'name' => $reviewer->name,
            ])->values()->all(),
            'summary' => [
                'declared' => $actions->count(),
                'available' => $actions->where('available', true)->count(),
                'awaitingApproval' => collect($history)->where('status', 'awaiting_approval')->count(),
                'uncertain' => collect($history)->where('status', 'uncertain')->count(),
                'blocked' => collect($history)->where('status', 'blocked')->count(),
                'breakGlassReviewDue' => collect($history)
                    ->where('isBreakGlass', true)
                    ->filter(fn (array $command): bool => $command['breakGlass']['reviewedAt'] === null)
                    ->count(),
            ],
        ];
    }

    private function commandNextAction(CommandStatus $status, bool $preconditionsCurrent = true): string
    {
        if ($status === CommandStatus::Ready && ! $preconditionsCurrent) {
            return 'Approved conditions changed. Recheck the request; it will close safely without execution unless every condition is current.';
        }

        return match ($status) {
            CommandStatus::Requested => 'Review the governance requirements before the request expires.',
            CommandStatus::AwaitingStepUp => 'The requester must confirm their identity and safely resume this request before it expires.',
            CommandStatus::AwaitingApproval => 'An independent reviewer must verify the Device, Site, reason, timing, and expected result.',
            CommandStatus::AwaitingChange => 'Link an eligible approved IT Change whose maintenance window and Device/Site scope are current.',
            CommandStatus::Ready => 'The requester or a command administrator can add this request to the governed execution queue.',
            CommandStatus::Queued => 'Execution is queued. Do not create a duplicate request while this command is pending.',
            CommandStatus::Dispatching,
            CommandStatus::Accepted,
            CommandStatus::Running => 'Execution is in progress. Wait for a provider result and fresh-state verification.',
            CommandStatus::Succeeded,
            CommandStatus::Reconciling => 'Execution reported success; the application is verifying the actual Device state.',
            CommandStatus::Uncertain => 'Confirm the actual Device state before any retry. High-risk commands must not be repeated blindly.',
            CommandStatus::Mismatch => 'Investigate the state mismatch and record the operational response before considering another command.',
            CommandStatus::Failed => 'Review the safe failure evidence, correct the cause, and create a new request only if still required.',
            CommandStatus::Rejected => 'The request was rejected. Create a new request only after addressing the reviewer decision.',
            CommandStatus::Expired => 'The request expired without execution. Reconfirm the need and create a new short-lived request.',
            CommandStatus::Cancelled => 'The request was cancelled and cannot be resumed.',
            CommandStatus::Blocked => 'Resolve the recorded governance condition and create a new request. This request cannot execute or be resumed.',
            CommandStatus::Reconciled => 'No further action is required; the fresh observed state matched the expected result.',
        };
    }

    private function stepUpCurrent(Carbon $now): bool
    {
        if (! request()->hasSession()) {
            return false;
        }
        $confirmedAt = request()->session()->get('auth.password_confirmed_at');
        if (! is_numeric($confirmedAt)) {
            return false;
        }
        $confirmed = Carbon::createFromTimestampUTC((int) $confirmedAt);
        $maxAge = max(60, (int) config('security_devices.step_up_max_age_seconds', 900));

        return ! $confirmed->isFuture() && $confirmed->greaterThanOrEqualTo($now->copy()->subSeconds($maxAge));
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
