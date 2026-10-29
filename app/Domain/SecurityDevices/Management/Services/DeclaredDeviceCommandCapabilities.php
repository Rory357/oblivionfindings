<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Drivers\Contracts\AlarmPanelDriver;
use App\Domain\SecurityDevices\Drivers\Contracts\NetworkConfigBackupDriver;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareAuthority;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Queclink\QueclinkDevice;
use Illuminate\Support\Collection;

final class DeclaredDeviceCommandCapabilities
{
    /** Port actions with no real vendor driver behind them yet (see the reject list in forDevice()). */
    public const array UNBUILT_PORT_ACTIONS = [
        'network.port.enable',
        'network.port.disable',
        'network.port.bounce',
    ];

    /** @return Collection<int, string> */
    public function forDevice(Device $device): Collection
    {
        $declared = collect([$device->config ?? [], $device->meta ?? []])
            ->flatMap(function (array $source): array {
                $declared = data_get($source, 'management.capabilities', []);
                if (! is_array($declared)) {
                    return [];
                }

                if (array_is_list($declared)) {
                    return $declared;
                }

                return collect($declared)
                    ->filter(fn (mixed $enabled): bool => $enabled === true || $enabled === 'enabled')
                    ->keys()
                    ->all();
            })
            ->filter(fn (mixed $capability): bool => is_string($capability) && trim($capability) !== '')
            ->map(fn (string $capability): string => trim($capability))
            ->unique()
            ->values();

        if ($this->hasNativeQueclinkManagement($device)) {
            $declared->push('tracking.location_refresh', 'configuration.refresh', 'configuration.apply', 'device.reboot');
        }

        // Gate driver-backed & endpoint-backed capabilities on real verified authorities/drivers.
        // A device record may list any capability in its saved settings, so these are never taken
        // from the record; each is added back below only when real code can carry it out.
        $declared = $declared->reject(fn (string $key): bool => in_array($key, [
            'network.config.backup',
            'endpoint.service.manage',
            'endpoint.computer.restart',
            'endpoint.software.install',
            'endpoint.script.run',
            // Arm and disarm only with a real alarm driver (added back below). Report-only panels
            // such as Ajax are view only (Phase A, docs/security-devices/GOAL.md).
            'alarm.arm',
            'alarm.disarm',
            // Port on/off/restart: NetworkDeviceCommandAdapter calls a made-up controller path that
            // no switch or controller has, so it is never offered until a real driver is built and
            // walked (Phase G).
            ...self::UNBUILT_PORT_ACTIONS,
        ], true));

        $driverRegistry = app(DeviceDriverRegistry::class);
        if ($this->hasDirectSshTarget($device)
            && $driverRegistry->resolveNetworkConfigBackupDriver($device) instanceof NetworkConfigBackupDriver) {
            $declared->push('network.config.backup');
        }
        if ($this->hasDirectAlarmApiTarget($device)
            && $driverRegistry->resolveAlarmDriver($device) instanceof AlarmPanelDriver) {
            $declared->push('alarm.arm', 'alarm.disarm');
        }
        if (app(EndpointScriptAuthority::class)->supports($device)) {
            $declared->push('endpoint.script.run');
        }
        if (app(EndpointServiceAuthority::class)->supports($device)) {
            $declared->push('endpoint.service.manage');
        }

        if (app(EndpointRestartAuthority::class)->supports($device)) {
            $declared->push('endpoint.computer.restart');
        }

        if (app(EndpointSoftwareAuthority::class)->supports($device)) {
            $declared->push('endpoint.software.install');
        }

        return $declared->unique()->values();
    }

    public function supports(Device $device, string $capability): bool
    {
        return $this->forDevice($device)->containsStrict($capability);
    }

    private function hasNativeQueclinkManagement(Device $device): bool
    {
        if (strtolower(trim((string) $device->provider)) !== 'queclink'
            || $device->domain !== 'tracking') {
            return false;
        }

        return QueclinkDevice::query()
            ->where('device_id', $device->id)
            ->where('status', QueclinkDevice::STATUS_PAIRED)
            ->where(function ($query) use ($device): void {
                $identifiers = collect([$device->imei, $device->device_uid])
                    ->filter(fn (mixed $identifier): bool => is_string($identifier) && trim($identifier) !== '')
                    ->map(fn (string $identifier): string => trim($identifier))
                    ->values();
                if ($identifiers->isEmpty()) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $query->whereIn('imei', $identifiers->all());
            })
            ->exists();
    }

    private function hasDirectSshTarget(Device $device): bool
    {
        $host = trim((string) ($device->ip_address ?? data_get($device->config, 'ssh.host') ?? ''));

        return $host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private function hasDirectAlarmApiTarget(Device $device): bool
    {
        $host = trim((string) (
            $device->ip_address
            ?? data_get($device->config, 'alarm_api.host')
            ?? data_get($device->config, 'endpoint.host')
            ?? ''
        ));

        return $host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
