<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Paradox Security Systems EVO / Spectra / Magellan IP150 descriptor.
 *
 * Official vendor site:
 * - https://www.paradox.com/
 */
final class ParadoxAlarmDriver implements DeviceDriver
{
    public const string KEY = 'alarm.paradox_native';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Paradox Security Systems',
            name: 'EVO / Magellan / Spectra (PRT3 / IP150)',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'Alarm reports (SIA DC-09) to Oblivion’s alarm receiver · no direct control',
            models: ['Paradox EVO192 / EVOHD', 'Paradox SP5500 / SP6000', 'Paradox MG5050', 'IP150+'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
            ],
            summary: 'A Paradox panel with an IP150 or PCS module can send its alarm reports to Oblivion’s alarm receiver. View only: Oblivion can’t arm or disarm it. Not tested on real hardware.',
            officialDocs: [
                ['label' => 'Paradox Security Systems', 'url' => 'https://www.paradox.com/'],
            ],
            limitationsNote: 'Newer IP150 firmware restricts local third-party sessions to Swan cloud unless PRT3 integration hardware is installed.',
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

        return str_contains($haystack, 'paradox') || str_contains($haystack, 'evo192') || str_contains($haystack, 'mg5050');
    }
}
