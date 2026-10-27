<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareAuthority;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Queclink\QueclinkDevice;
use Illuminate\Support\Collection;

final class DeclaredDeviceCommandCapabilities
{
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

        // Network configuration backup is hidden until real vendor SSH drivers exist in Phase 5.
        $declared = $declared->reject(fn (string $key): bool => in_array($key, [
            'network.config.backup',
            'endpoint.service.manage',
            'endpoint.computer.restart',
            'endpoint.software.install',
            'endpoint.script.run',
        ], true));
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
}
