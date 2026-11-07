<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Integrated Control Technology (Auckland, New Zealand) Protege GX & WX native SOAP/API descriptor.
 *
 * Official vendor site:
 * - https://www.ict.co/
 */
final class IctProtegeAlarmDriver implements DeviceDriver
{
    public const string KEY = 'alarm.ict_protege_native';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'ICT (Integrated Control Technology NZ)',
            name: 'Protege GX & WX Direct Integration',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'Alarm reports (SIA DC-09) to Oblivion’s alarm receiver · no direct control',
            models: ['ICT Protege GX', 'ICT Protege WX (PRT-WX-DIN)'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
            ],
            summary: 'A Protege panel can send its alarm reports to Oblivion’s alarm receiver. View only: Oblivion can’t arm, disarm or unlock doors on it. Not tested on real hardware.',
            officialDocs: [
                ['label' => 'Integrated Control Technology (ICT)', 'url' => 'https://www.ict.co/'],
            ],
            limitationsNote: 'ICT Protege GX SOAP/Automation API documentation and licensing require ICT partner registration; Protege WX does not expose a third-party REST API.',
            viewOnly: true,
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if ($device->domain !== 'security' || ! in_array($device->category, ['alarm', 'perimeter', 'access_control'], true)) {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $device->manufacturer,
            (string) $device->model,
            (string) $device->provider,
        ])));

        return str_contains($haystack, 'protege') || str_contains($haystack, 'ict ');
    }
}
