<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Inner Range Integriti enterprise security server descriptor.
 *
 * Official vendor documentation:
 * - https://www.innerrange.com/products/integriti/
 */
final class InnerRangeIntegritiDriver implements DeviceDriver
{
    public const string KEY = 'alarm.innerrange_integriti';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Inner Range',
            name: 'Integriti Enterprise XML/REST API',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'Alarm reports (SIA DC-09) to Oblivion’s alarm receiver · no direct control',
            models: ['Integriti ISC Controller', 'Integriti IAC Controller'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
            ],
            summary: 'An Integriti controller can send its alarm reports to Oblivion’s alarm receiver. View only: Oblivion can’t arm, disarm or unlock doors on it. (Inner Range Inception is a separate driver.) Not tested on real hardware.',
            officialDocs: [
                ['label' => 'Inner Range Integriti Platform', 'url' => 'https://www.innerrange.com/products/integriti/'],
            ],
            limitationsNote: 'Requires an Integriti Professional/Business server licence with REST/XML Inbound Integration enabled.',
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

        return str_contains($haystack, 'integriti');
    }
}
