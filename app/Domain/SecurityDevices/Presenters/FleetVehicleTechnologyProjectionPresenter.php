<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Read-only technology context for the canonical Fleet vehicle profile.
 *
 * Fleet owns the vehicle, driver, journey, booking, inspection, and compliance
 * workflows. Security & Devices owns installed technology and its technical
 * lifecycle; IT owns linked service work. No shadow device register is created.
 */
final class FleetVehicleTechnologyProjectionPresenter
{
    private const DEVICE_LIMIT = 50;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function canView(User $viewer, Asset $vehicle): bool
    {
        return $viewer->canDo('fleet.viewAny')
            && $viewer->canDo('securityDevices.devices.view')
            && $this->access->assignableVehicle($viewer, (int) $vehicle->getKey()) !== null;
    }

    /** @return array<string, mixed>|null */
    public function present(User $viewer, Asset $vehicle): ?array
    {
        if (! $this->canView($viewer, $vehicle)) {
            return null;
        }

        $canViewMonitoring = $viewer->canDo('securityDevices.events.view');
        $canViewMaintenance = $viewer->canDo('securityDevices.maintenance.view');
        $canViewIt = $viewer->canDo('it.view');

        $candidates = $this->access->visibleDevices($viewer)
            ->where(function (Builder $device) use ($vehicle): void {
                $device->whereHas('activeAssetLinks', fn (Builder $link): Builder => $link
                    ->where('asset_id', $vehicle->getKey()))
                    ->orWhereHas('assignments', fn (Builder $assignment): Builder => $assignment
                        ->active()
                        ->where('assignable_type', DeviceAssignment::TARGET_VEHICLE)
                        ->where('assignable_id', $vehicle->getKey()));
            })
            ->with([
                'activeAssetLinks' => fn ($query) => $query
                    ->where('asset_id', $vehicle->getKey())
                    ->orderByDesc('linked_at'),
                'assignments' => fn ($query) => $query
                    ->active()
                    ->where('assignable_type', DeviceAssignment::TARGET_VEHICLE)
                    ->where('assignable_id', $vehicle->getKey())
                    ->orderByDesc('assigned_at'),
                'monitors' => fn ($query) => $query
                    ->with(['profile:id,name,interval_seconds,stale_after_seconds', 'collector:id,name,status,last_seen_at'])
                    ->orderBy('name'),
                'maintenanceRecords' => fn ($query) => $query->orderByDesc('scheduled_for')->orderByDesc('id'),
                'latestConfigurationSnapshot',
            ])
            ->orderBy('name')
            ->limit(self::DEVICE_LIMIT + 1)
            ->get();

        $truncated = $candidates->count() > self::DEVICE_LIMIT;
        $devices = $candidates->take(self::DEVICE_LIMIT)->values();
        $ticketLinks = $canViewIt ? $this->ticketLinks($viewer, $devices->pluck('id')) : collect();

        $mapped = $devices
            ->map(fn (Device $device): array => $this->mapDevice(
                $device,
                $ticketLinks->get((int) $device->id, collect()),
                $canViewMonitoring,
                $canViewMaintenance,
                $canViewIt,
            ))
            ->sortBy(fn (array $device): string => sprintf(
                '%02d-%s',
                $this->priority($device),
                strtolower((string) $device['name']),
            ))
            ->values();

        return [
            'boundary' => [
                'title' => 'Technology here, vehicle operations in Fleet',
                'description' => 'This is a read-only projection of installed trackers, cameras, gateways, and sensors. Driver, journey, booking, inspection, compliance, and vehicle maintenance workflows remain in Fleet & Assets.',
                'management' => 'Device configuration and commands continue through governed Security & Devices workflows.',
            ],
            'summary' => [
                'total' => $mapped->count(),
                'offline' => $mapped->where('connectivity.state', 'offline')->count(),
                'attention' => $mapped->filter(fn (array $device): bool => $this->priority($device) === 0)->count(),
                'unmonitored' => $canViewMonitoring
                    ? $mapped->where('monitoring.enabled', 0)->count()
                    : null,
                'monitor_alerts' => $canViewMonitoring
                    ? $mapped->sum('monitoring.attention')
                    : null,
                'configuration_drift' => $mapped->where('configuration.state', 'drifted')->count(),
                'firmware_updates' => $mapped->where('firmware.state', 'update_available')->count(),
                'overdue_maintenance' => $canViewMaintenance
                    ? $mapped->sum('maintenance.overdue')
                    : null,
                'open_it_work' => $canViewIt
                    ? $mapped->sum('it_work.open')
                    : null,
            ],
            'devices' => $mapped,
            'truncated' => $truncated,
            'permissions' => [
                'monitoring' => $canViewMonitoring,
                'maintenance' => $canViewMaintenance,
                'it_work' => $canViewIt,
            ],
            'links' => [
                'tracking' => '/security-devices/tracking?tab=fleet',
                'devices' => '/security-devices/devices',
                'maintenance' => $canViewMaintenance ? '/security-devices/maintenance' : null,
                'it_work' => $canViewIt ? '/it?tab=tickets' : null,
            ],
        ];
    }

    /** @param Collection<int, ItTicketLink> $ticketLinks @return array<string, mixed> */
    private function mapDevice(
        Device $device,
        Collection $ticketLinks,
        bool $canViewMonitoring,
        bool $canViewMaintenance,
        bool $canViewIt,
    ): array {
        $monitors = $canViewMonitoring
            ? $device->monitors->where('is_enabled', true)->values()
            : collect();
        $maintenance = $canViewMaintenance
            ? $device->maintenanceRecords->whereIn('status', ['scheduled', 'in_progress'])->values()
            : collect();
        $overdueMaintenance = $maintenance->filter(fn (DeviceMaintenanceRecord $record): bool => $record->status === 'scheduled'
            && $record->scheduled_for?->isPast());
        $nextMaintenance = $maintenance
            ->filter(fn (DeviceMaintenanceRecord $record): bool => $record->scheduled_for !== null)
            ->sortBy('scheduled_for')
            ->first();
        $configuration = $this->configuration($device);
        $assignment = $device->activeAssetLinks->first();
        $vehicleAssignment = $device->assignments->first();
        $tickets = $canViewIt
            ? $ticketLinks->pluck('ticket')->filter()->unique('id')->values()
            : collect();

        return [
            'id' => $device->id,
            'name' => $device->name,
            'domain' => $device->domain,
            'category' => $device->category,
            'subcategory' => $device->subcategory,
            'provider' => $device->provider,
            'status' => $device->status?->value,
            'health' => $device->health_status?->value,
            'battery' => $device->battery_level,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'href' => "/security-devices/devices/{$device->id}",
            'installation' => [
                'type' => $assignment?->link_type?->value ?? ($vehicleAssignment ? 'assigned_to_vehicle' : 'unknown'),
                'installed_at' => $assignment?->linked_at?->toISOString()
                    ?? $vehicleAssignment?->assigned_at?->toISOString(),
            ],
            'connectivity' => $this->connectivity($device),
            'monitoring' => $canViewMonitoring ? [
                'enabled' => $monitors->count(),
                'attention' => $monitors->whereIn('current_state', [MonitorState::Failed, MonitorState::Degraded])->count(),
                'uncertain' => $monitors->whereIn('current_state', [MonitorState::Unknown, MonitorState::Stale, MonitorState::Pending])->count(),
                'states' => $monitors->take(6)->map(fn ($monitor): array => [
                    'id' => $monitor->id,
                    'name' => $monitor->name,
                    'kind' => $monitor->kind?->value,
                    'state' => $monitor->current_state?->value,
                    'last_observation_at' => $monitor->last_observation_at?->toISOString(),
                ])->values(),
            ] : null,
            'configuration' => $configuration['configuration'],
            'firmware' => $configuration['firmware'],
            'maintenance' => $canViewMaintenance ? [
                'open' => $maintenance->count(),
                'overdue' => $overdueMaintenance->count(),
                'next' => $nextMaintenance ? [
                    'type' => $nextMaintenance->type,
                    'status' => $nextMaintenance->status,
                    'scheduled_for' => $nextMaintenance->scheduled_for?->toDateString(),
                ] : null,
                'href' => "/security-devices/maintenance?device_id={$device->id}",
            ] : null,
            'it_work' => $canViewIt ? [
                'open' => $tickets->whereIn('status', ItTicket::OPEN_STATUSES)->count(),
                'items' => $tickets->take(4)->map(fn (ItTicket $ticket): array => [
                    'id' => $ticket->id,
                    'reference' => $ticket->reference,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'href' => "/it/tickets/{$ticket->id}",
                ])->values(),
            ] : null,
        ];
    }

    /** @return array{state: string, label: string} */
    private function connectivity(Device $device): array
    {
        [$state, $label] = match (true) {
            $device->status === DeviceStatus::Offline => ['offline', 'Offline'],
            in_array($device->status, [DeviceStatus::Degraded, DeviceStatus::Lost], true) => ['attention', 'Needs attention'],
            $device->last_seen_at === null => ['unknown', 'Never observed'],
            $device->last_seen_at->lt(now()->subMinutes(15)) => ['stale', 'Last contact is stale'],
            default => ['online', 'Recently observed'],
        };

        return compact('state', 'label');
    }

    /** @return array<string, array<string, mixed>> */
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
            'configuration' => [
                'state' => match (true) {
                    $observedHash === null && $desiredHash === null => 'not_observed',
                    $observedHash !== null && $desiredHash !== null && ! hash_equals($observedHash, $desiredHash) => 'drifted',
                    $observedHash !== null && $desiredHash !== null => 'aligned',
                    $observedHash !== null => 'observed',
                    default => 'desired_only',
                },
                'observed_at' => $this->evidenceDate($device, [
                    'observed.configuration_at',
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
                'current_version' => $device->firmware_version,
                'desired_version' => $desiredFirmware,
                'observed_at' => $this->evidenceDate($device, [
                    'observed.firmware_at',
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

    private function evidenceDate(Device $device, array $paths): ?string
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

    /** @param Collection<int, int> $deviceIds @return Collection<int, Collection<int, ItTicketLink>> */
    private function ticketLinks(User $viewer, Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty()) {
            return collect();
        }

        return ItTicketLink::query()
            ->where('relationship', 'affected_device')
            ->where('linkable_type', Device::class)
            ->whereIn('linkable_id', $deviceIds)
            ->with('ticket')
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (ItTicketLink $link): bool => $link->ticket !== null
                && Gate::forUser($viewer)->allows('view', $link->ticket))
            ->groupBy(fn (ItTicketLink $link): int => (int) $link->linkable_id);
    }

    /** @param array<string, mixed> $device */
    private function priority(array $device): int
    {
        return match (true) {
            in_array($device['connectivity']['state'], ['offline', 'attention'], true),
            in_array($device['health'], ['critical', 'warning'], true),
            ($device['monitoring']['attention'] ?? 0) > 0,
            $device['configuration']['state'] === 'drifted',
            $device['firmware']['state'] === 'update_available',
            ($device['maintenance']['overdue'] ?? 0) > 0 => 0,
            $device['connectivity']['state'] === 'stale',
            ($device['monitoring']['uncertain'] ?? 0) > 0 => 1,
            default => 2,
        };
    }
}
