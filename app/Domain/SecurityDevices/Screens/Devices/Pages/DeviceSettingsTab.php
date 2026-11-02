<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A network device's Settings tab (S&D v13, pg-sdr-3f), in Fleet's settings pattern: titled groups
 * of short rows and switches, a Review changes bar that flags a change loosening a safety check.
 * Everything saves through routes that already exist: the device update (name, room), Move
 * (pop-up 72), Update login (pop-up 63), watching on (the offline-alert route, pop-up 9) and off
 * (each check's own deactivate route, with its reason code), and Remove device (pop-up 11; its
 * history stays). Nothing here invents a setting Oblivion doesn't keep.
 */
final class DeviceSettingsTab
{
    /** Kinds others rely on: stopping watching them leaves the devices behind them unwatched. */
    private const array RELIED_ON = [DeviceKind::Switch, DeviceKind::Router, DeviceKind::SensorGateway, DeviceKind::NetworkDevice];

    /** Check kinds in words, for "Read through". */
    private const array READ_WORDS = [
        'icmp' => 'ping',
        'tcp' => 'a service port',
        'http' => 'its web page',
        'tls' => 'its certificate',
        'dns' => 'a name lookup',
        'snmp' => 'SNMP',
        'snmp_interface' => 'SNMP',
        'ssh_inventory' => 'SSH',
        'winrm_inventory' => 'Windows remote reads',
        'provider' => 'its connected service',
        'collector' => 'the hub',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CoveringHubResolver $hubs,
    ) {}

    /**
     * @param  Collection<int, Monitor>  $monitors  its enabled checks, with profile and collector
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, DeviceKind $kind, ?DeviceEntry $entry, Collection $monitors, ?int $siteId, ?string $siteName, string $noun): array
    {
        $direct = NativeMonitoringDefinitionService::directKindValues();
        $stoppable = $monitors->filter(fn (Monitor $monitor): bool => in_array($monitor->kind?->value, $direct, true))->values();
        $availability = $monitors->filter(fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Icmp && (bool) $monitor->affects_availability)->values();
        $backup = $monitors->first(fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::SshInventory);
        $hub = $siteId !== null ? $this->hubs->forSite($siteId) : null;
        $checkedBy = $hub !== null ? CoveringHubResolver::label($hub) : 'the Oblivion server';
        $every = $availability->map(fn (Monitor $monitor): ?int => $monitor->profile?->interval_seconds)->filter()->min();
        $dependents = $this->dependents($viewer, $device);
        $watched = $entry !== null && $entry->state !== DeviceState::NotWatched;
        $model = trim(trim((string) $device->manufacturer).' '.trim((string) $device->model));
        $provider = strtolower(trim((string) $device->provider));

        return [
            'noun' => $noun,
            'name' => (string) $device->name,
            'site' => $siteName,
            'site_id' => $siteId,
            'room' => trim((string) $device->location_description) ?: null,
            'checked_by' => $siteId === null ? null : $checkedBy,
            'address' => $device->ip_address ? (string) $device->ip_address : null,
            'read_through' => $this->readThrough($monitors, $provider),
            'watching' => [
                'on' => $watched,
                // Reports from a connected service (UniFi and the like) keep it watched without checks here.
                'by_service' => $watched && $monitors->isEmpty(),
                'every' => is_int($every) && $every > 0 ? self::every($every) : null,
                'checked_by' => $checkedBy,
                'offline_alert' => $availability->isNotEmpty(),
                'offline_ids' => $availability->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                'stop_ids' => $stoppable->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                // Checks this tab can't stop (ports, a connected service's reports): Checks & alerts lists them.
                'other_checks' => $monitors->count() - $stoppable->count(),
                'backup' => $backup === null ? null : [
                    'every' => $backup->profile?->interval_seconds ? self::every((int) $backup->profile->interval_seconds) : null,
                ],
                'relied_on' => in_array($kind, self::RELIED_ON, true) || $dependents > 0,
                'dependents' => $dependents,
            ],
            'about' => $this->about($device, $model, $kind),
            'it' => array_values(array_filter([
                $device->mac_address ? ['MAC', (string) $device->mac_address] : null,
                ['Software', $device->firmware_version ? (string) $device->firmware_version : 'Not available'],
                ! in_array($provider, ['', 'manual', 'native', 'oblivion', 'oblivion_native'], true)
                    ? ['Managed by', match ($provider) {
                        'unifi', 'ubiquiti' => 'UniFi Network',
                        'milesight' => 'Milesight',
                        'hikvision' => 'Hikvision',
                        default => 'A connected service',
                    }]
                    : null,
            ])),
            // The device update route needs its current kind sent back unchanged with a new name or room.
            'edit' => $viewer->canDo('securityDevices.devices.update') ? [
                'domain' => (string) ($device->getRawOriginal('domain') ?? ''),
                'category' => (string) ($device->getRawOriginal('category') ?? ''),
                'subcategory' => $device->getRawOriginal('subcategory') !== null ? (string) $device->getRawOriginal('subcategory') : null,
            ] : null,
        ];
    }

    private static function every(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => 'every '.$seconds.' s',
            $seconds === 60 => 'every minute',
            $seconds < 3600 => 'every '.intdiv($seconds, 60).' min',
            $seconds === 3600 => 'every hour',
            $seconds < 86_400 => 'every '.intdiv($seconds, 3600).' h',
            $seconds === 86_400 => 'every day',
            default => 'every '.intdiv($seconds, 86_400).' days',
        };
    }

    /** @param Collection<int, Monitor> $monitors */
    private function readThrough(Collection $monitors, string $provider): ?string
    {
        $words = $monitors->map(fn (Monitor $monitor): ?string => self::READ_WORDS[$monitor->kind?->value ?? ''] ?? null)->filter()->unique()->values()->all();
        if (in_array($provider, ['unifi', 'ubiquiti'], true)) {
            array_unshift($words, 'UniFi Network');
        }
        $words = array_values(array_unique($words));
        if ($words === []) {
            return null;
        }
        if (count($words) === 1) {
            return ucfirst($words[0]);
        }
        $last = array_pop($words);

        return ucfirst(implode(', ', $words).' and '.$last);
    }

    /** Devices the viewer can see that are wired behind it. */
    private function dependents(User $viewer, Device $device): int
    {
        $ids = $device->childRelationships()->pluck('child_device_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        return $ids === [] ? 0 : $this->access->visibleDevices($viewer)->whereKey($ids)->count();
    }

    /** @return array{model: string, added_at: ?string, added_by: ?string, from_scan: bool} */
    private function about(Device $device, string $model, DeviceKind $kind): array
    {
        $adopted = DiscoveryCandidate::query()
            ->where('canonical_device_id', $device->id)
            ->where('review_action', 'adopted')
            ->orderBy('reviewed_at')
            ->first(['reviewed_by_user_id', 'reviewed_at']);
        $byId = $adopted?->reviewed_by_user_id ?? $device->created_by_user_id;
        $by = $byId ? User::query()->whereKey($byId)->value('name') : null;
        $at = $adopted?->reviewed_at ?? $device->created_at;

        return [
            'model' => $model !== '' ? $model : $kind->label(),
            'added_at' => $at?->toIso8601String(),
            'added_by' => is_string($by) && $by !== '' ? $by : null,
            'from_scan' => $adopted !== null,
        ];
    }
}
