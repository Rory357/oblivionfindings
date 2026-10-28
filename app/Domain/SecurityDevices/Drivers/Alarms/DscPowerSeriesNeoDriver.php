<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Johnson Controls DSC PowerSeries Neo (TL280) descriptor.
 *
 * Official vendor site:
 * - https://www.dsc.com/
 */
final class DscPowerSeriesNeoDriver implements DeviceDriver
{
    public const string KEY = 'alarm.dsc_powerseries_neo_native';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'DSC (Johnson Controls)',
            name: 'PowerSeries Neo (TL280)',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'SIA DC-09 IP (available now) · DSC Neo Integration Protocol v2 (planned)',
            models: ['HS2016', 'HS2032', 'HS2064', 'HS2128', 'TL280E'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
                DriverCapability::AlarmArm,
                DriverCapability::AlarmDisarm,
            ],
            summary: 'Live IP event reporting works today via TL280 SIA DC-09 reporting. Direct TL280 integration protocol arming & disarming is planned pending Johnson Controls Connected Partner certification.',
            officialDocs: [
                ['label' => 'DSC Security Products', 'url' => 'https://www.dsc.com/'],
            ],
            limitationsNote: 'PowerSeries Neo encrypts keypad bus traffic (unlike legacy PowerSeries PC1864) and requires Johnson Controls Connected Partner Programme integration keys for direct control.',
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

        return str_contains($haystack, 'dsc') || str_contains($haystack, 'powerseries');
    }
}
