<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Bosch Security Solution 2000 / 3000 / 6000 & B/G Series alarm panel descriptor.
 *
 * Official vendor documentation:
 * - https://www.boschsecurity.com/
 */
final class BoschSolutionAlarmDriver implements DeviceDriver
{
    public const string KEY = 'alarm.bosch_solution_native';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Bosch Security',
            name: 'Solution 2000 / 3000 / 6000 & B-Series',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'Alarm reports (SIA DC-09) to Oblivion’s alarm receiver · no direct control',
            models: ['Bosch Solution 2000', 'Bosch Solution 3000', 'Bosch Solution 6000', 'Bosch B5512 / B8512G'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
            ],
            summary: 'A Bosch panel with an Ethernet module can send its alarm reports to Oblivion’s alarm receiver. View only: Oblivion can’t arm or disarm it. Not tested on real hardware.',
            officialDocs: [
                ['label' => 'Bosch Security Intrusion Alarm Systems', 'url' => 'https://www.boschsecurity.com/'],
            ],
            limitationsNote: 'Bosch Solution Mode 2 / RSC+ automation specifications require Bosch Integration Partner NDA access.',
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

        return str_contains($haystack, 'bosch') || str_contains($haystack, 'solution 6000') || str_contains($haystack, 'solution 3000');
    }
}
