<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Every device the viewer may see, worked out once: its kind (which Devices tab), its state in
 * plain words, its Site and what watches it. Scoped by SecurityDevicesAccessService::visibleDevices,
 * so another Site's devices never reach a screen. Retired devices are left out (they leave lists).
 */
final class DeviceDirectory
{
    /** Monitor kinds in plain words, for "Watching". */
    private const array WATCH_WORDS = [
        'icmp' => 'online',
        'tcp' => 'a port',
        'http' => 'web page',
        'tls' => 'certificate',
        'dns' => 'name lookup',
        'snmp' => 'readings',
        'snmp_interface' => 'ports',
        'ssh_inventory' => 'settings backup',
        'winrm_inventory' => 'computer details',
        'provider' => 'service reports',
        'collector' => 'hub',
    ];

    /** Providers that are Oblivion itself or a person, not a connected service. */
    private const array LOCAL_PROVIDERS = ['', 'manual', 'native', 'oblivion'];

    /** @var array<int, bool> */
    private array $clientVisibility = [];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /**
     * @return Collection<int, DeviceEntry>
     */
    public function entries(User $viewer, ?int $siteId = null): Collection
    {
        $query = $siteId === null
            ? $this->access->visibleDevices($viewer)
            : $this->access->visibleDevicesForSite($viewer, $siteId);

        $devices = $query
            ->select([
                'id', 'name', 'domain', 'category', 'subcategory', 'manufacturer', 'model', 'ip_address',
                'status', 'health_status', 'last_seen_at', 'last_signal_at', 'battery_level', 'battery_updated_at',
                'provider', 'meta', 'location_description', 'firmware_version', 'notes',
            ])
            ->with([
                'assignments' => fn ($assignment) => $assignment->active()
                    ->select(['id', 'device_id', 'assignable_type', 'assignable_id', 'custody_site_id', 'assigned_at']),
                'monitors' => fn ($monitor) => $monitor->where('is_enabled', true)
                    ->select([
                        'id', 'device_id', 'collector_id', 'kind', 'name', 'current_state', 'effective_state',
                        'suppression_reason', 'root_cause_monitor_id', 'affects_availability', 'is_enabled',
                        'last_observation_at', 'last_state_changed_at',
                    ]),
            ])
            ->get();

        $siteIds = $devices
            ->flatMap(fn (Device $device) => $device->assignments->pluck('custody_site_id'))
            ->filter()
            ->unique()
            ->values();
        $siteNames = $siteIds->isEmpty()
            ? collect()
            : Site::query()->whereIn('id', $siteIds)->pluck('name', 'id');

        $deviceNames = $devices->pluck('name', 'id');
        $rootMonitorIds = $devices
            ->flatMap(fn (Device $device) => $device->monitors->pluck('root_cause_monitor_id'))
            ->filter()
            ->unique()
            ->values();
        $rootDeviceIds = $rootMonitorIds->isEmpty()
            ? collect()
            : Monitor::query()->whereIn('id', $rootMonitorIds)->pluck('device_id', 'id');

        $accessibleSites = $this->access->accessibleSiteIds($viewer);
        $collectorIds = $devices
            ->flatMap(fn (Device $device) => $device->monitors->pluck('collector_id'))
            ->filter()
            ->unique()
            ->values();
        $hubs = $collectorIds->isEmpty()
            ? collect()
            : MonitoringCollector::query()->whereIn('id', $collectorIds)->get(['id', 'name', 'site_id'])->keyBy('id');

        return $devices
            ->map(fn (Device $device): ?DeviceEntry => $this->entry(
                $device,
                $siteNames,
                $deviceNames,
                $rootDeviceIds,
                $hubs,
                $accessibleSites,
            ))
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int|string, string>  $siteNames
     * @param  Collection<int|string, string>  $deviceNames
     * @param  Collection<int|string, int>  $rootDeviceIds
     * @param  Collection<int|string, MonitoringCollector>  $hubs
     * @param  list<int>  $accessibleSites
     */
    private function entry(
        Device $device,
        Collection $siteNames,
        Collection $deviceNames,
        Collection $rootDeviceIds,
        Collection $hubs,
        array $accessibleSites,
    ): ?DeviceEntry {
        $assignment = $device->assignments->sortByDesc('assigned_at')->first();
        $siteId = $assignment?->custody_site_id ? (int) $assignment->custody_site_id : null;
        $monitors = $device->monitors;

        $suppressed = $monitors->first(fn (Monitor $monitor): bool => in_array($monitor->suppression_reason, ['dependency', 'collector_path'], true));
        $availability = $monitors->filter(fn (Monitor $monitor): bool => (bool) $monitor->affects_availability);
        $availabilityFailed = $availability->first(fn (Monitor $monitor): bool => $monitor->current_state?->value === 'failed'
            && $monitor->suppression_reason === null);
        $failing = $monitors->first(fn (Monitor $monitor): bool => (bool) $monitor->current_state?->isFailure()
            && $monitor->suppression_reason === null);

        $provider = strtolower(trim((string) $device->provider));
        $watched = $monitors->isNotEmpty() || ! in_array($provider, self::LOCAL_PROVIDERS, true);

        $status = $availabilityFailed !== null ? 'offline' : $device->status?->value;
        $health = $device->health_status?->value;
        if ($failing !== null && $availabilityFailed === null && $health !== 'critical') {
            $health = 'warning';
        }

        $state = DeviceState::resolve($status, $health, $watched, $suppressed !== null);
        if ($state === DeviceState::Retired) {
            return null;
        }

        $kind = DeviceKind::of($device);

        // What is in front of it, in words the viewer may see.
        $behind = null;
        if ($suppressed !== null) {
            if ($suppressed->suppression_reason === 'collector_path') {
                $hub = $suppressed->collector_id ? $hubs->get($suppressed->collector_id) : null;
                $behind = $hub !== null && in_array((int) $hub->site_id, $accessibleSites, true)
                    ? CoveringHubResolver::label($hub)
                    : 'Its hub';
            } else {
                $rootDeviceId = $suppressed->root_cause_monitor_id ? $rootDeviceIds->get($suppressed->root_cause_monitor_id) : null;
                $behind = $rootDeviceId !== null && $deviceNames->has($rootDeviceId)
                    ? (string) $deviceNames->get($rootDeviceId)
                    : null;
            }
        }

        $watching = $monitors
            ->map(fn (Monitor $monitor): string => self::WATCH_WORDS[$monitor->kind?->value ?? ''] ?? 'a check')
            ->unique()
            ->values()
            ->all();
        if ($monitors->isEmpty() && $watched) {
            $watching = ['reports from '.$this->providerName($provider)];
        }

        $since = null;
        if ($availabilityFailed !== null) {
            $since = $availabilityFailed->last_state_changed_at;
        }

        $readings = data_get($device->meta, 'observed.sensor_readings');

        return new DeviceEntry(
            device: $device,
            kind: $kind,
            state: $state,
            siteId: $siteId,
            siteName: $siteId !== null ? ($siteNames->get($siteId) ?? null) : null,
            person: $assignment !== null && in_array($assignment->assignable_type, [DeviceAssignment::TARGET_CLIENT, DeviceAssignment::TARGET_STAFF], true)
                ? $this->personName($assignment)
                : null,
            monitorIds: $monitors->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            watching: $watching,
            behind: $behind,
            behindHub: $suppressed?->suppression_reason === 'collector_path',
            failingCheck: $failing?->name,
            offlineSince: $since instanceof CarbonInterface ? $since : null,
            lastCheckedAt: $monitors->max('last_observation_at'),
            readings: is_array($readings) ? $readings : [],
        );
    }

    private function providerName(string $provider): string
    {
        return match ($provider) {
            'unifi', 'ubiquiti' => 'UniFi',
            'milesight' => 'Milesight',
            'queclink' => 'Queclink',
            'intune' => 'Intune',
            'mqtt' => 'its sensor feed',
            default => 'a connected service',
        };
    }

    /** A client is named only to someone who may view that client. */
    private function personName(DeviceAssignment $assignment): ?string
    {
        $person = $assignment->assignable();
        if ($person === null) {
            return null;
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_CLIENT) {
            $viewer = auth()->user();
            $allowed = $viewer !== null
                && ($this->clientVisibility[(int) $person->getKey()] ??= Gate::forUser($viewer)->allows('view', $person));

            // Same words as the device record: preferred or first name only.
            return $allowed
                ? (trim((string) ($person->preferred_name ?: $person->first_name)) ?: null)
                : null;
        }

        $name = trim((string) ($person->name ?? ''));

        return $name !== '' ? $name : null;
    }
}
