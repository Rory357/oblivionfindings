<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Arrowhead Alarm Products (Auckland, New Zealand) AAP Elite S & ESL-2 native control descriptor.
 *
 * Official vendor site:
 * - https://www.aap.co.nz/
 */
final class ArrowheadEliteAlarmDriver implements DeviceDriver
{
    public const string KEY = 'alarm.arrowhead_elite_native';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Arrowhead Alarm Products (NZ)',
            name: 'AAP Elite S & ESL-2 Direct Control',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'Alarm reports (SIA DC-09) to Oblivion’s alarm receiver · no direct control',
            models: ['AAP Elite S', 'AAP ESL-2', 'ESL-APP POD'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
            ],
            summary: 'An Arrowhead panel with an IP module can send its alarm reports to Oblivion’s alarm receiver. View only: Oblivion can’t arm or disarm it. Not tested on real hardware.',
            officialDocs: [
                ['label' => 'Arrowhead Alarm Products NZ', 'url' => 'https://www.aap.co.nz/'],
            ],
            limitationsNote: 'Arrowhead does not publish an open local REST API; configure SIA DC-09 IP reporting on the ESL IP module for immediate live event intake.',
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

        return str_contains($haystack, 'arrowhead')
            || str_contains($haystack, 'esl-2')
            || str_contains($haystack, 'elite s');
    }
}
