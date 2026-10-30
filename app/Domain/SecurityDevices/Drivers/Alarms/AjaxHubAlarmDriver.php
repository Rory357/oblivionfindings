<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Ajax Systems Hub / Hub 2 / Hub Plus descriptor.
 *
 * Official SIA DC-09 setup guide:
 * - https://support.ajax.systems/en/manuals/hub/connect-to-cms-via-sia/
 */
final class AjaxHubAlarmDriver implements DeviceDriver
{
    public const string KEY = 'alarm.ajax_hub_native';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Ajax Systems',
            name: 'Ajax Hub 2 / Hub Plus Enterprise API',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'SIA DC-09 over TCP/UDP with AES (available now) · Ajax Enterprise API (planned)',
            models: ['Ajax Hub 2 (4G)', 'Ajax Hub Plus', 'Ajax Hub Hybrid'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
                DriverCapability::AlarmArm,
                DriverCapability::AlarmDisarm,
            ],
            summary: 'Local SIA DC-09 IP alarm & AES-encrypted Contact ID reporting works today directly from every Ajax Hub without cloud dependency. Direct Ajax Enterprise API remote arming is planned pending Ajax partner portal credentials.',
            officialDocs: [
                ['label' => 'Ajax Hub SIA DC-09 Receiver Setup', 'url' => 'https://support.ajax.systems/en/manuals/hub/connect-to-cms-via-sia/'],
            ],
            limitationsNote: 'Ajax Hubs do not expose a local REST API for arming; SIA DC-09 local IP reporting is built in and supported now, while remote arm/disarm requires Ajax Enterprise API approval.',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if ($device->domain !== 'security' || ! in_array($device->category, ['alarm', 'perimeter'], true)) {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $device->manufacturer,
            (string) $device->model,
            (string) $device->provider,
        ])));

        return str_contains($haystack, 'ajax');
    }
}
