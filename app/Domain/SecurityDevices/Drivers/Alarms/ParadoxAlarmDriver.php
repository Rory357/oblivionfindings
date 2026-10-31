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
            transportSummary: 'SIA DC-09 / Contact ID IP (available now) · Paradox PRT3 ASCII protocol (planned)',
            models: ['Paradox EVO192 / EVOHD', 'Paradox SP5500 / SP6000', 'Paradox MG5050', 'IP150+'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
                DriverCapability::AlarmArm,
                DriverCapability::AlarmDisarm,
            ],
            summary: 'Live IP event reporting works today via Paradox IP150 / PCS modules configured for SIA DC-09 Contact ID. Direct PRT3 serial/IP control is planned.',
            officialDocs: [
                ['label' => 'Paradox Security Systems', 'url' => 'https://www.paradox.com/'],
            ],
            limitationsNote: 'Newer IP150 firmware restricts local third-party sessions to Swan cloud unless PRT3 integration hardware is installed.',
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
