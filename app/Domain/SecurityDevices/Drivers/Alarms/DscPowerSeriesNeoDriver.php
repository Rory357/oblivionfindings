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
            transportSummary: 'Alarm reports (SIA DC-09) to Oblivion’s alarm receiver · no direct control',
            models: ['HS2016', 'HS2032', 'HS2064', 'HS2128', 'TL280E'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
            ],
            summary: 'A PowerSeries Neo panel with a TL280 module can send its alarm reports to Oblivion’s alarm receiver. View only: Oblivion can’t arm or disarm it. Not tested on real hardware.',
            officialDocs: [
                ['label' => 'DSC Security Products', 'url' => 'https://www.dsc.com/'],
            ],
            limitationsNote: 'PowerSeries Neo encrypts keypad bus traffic (unlike legacy PowerSeries PC1864) and requires Johnson Controls Connected Partner Programme integration keys for direct control.',
            viewOnly: true,
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
