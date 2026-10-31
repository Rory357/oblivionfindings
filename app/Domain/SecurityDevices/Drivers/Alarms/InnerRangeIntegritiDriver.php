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
            transportSummary: 'SIA DC-09 IP (available now) · Integriti Integration API (planned)',
            models: ['Integriti ISC Controller', 'Integriti IAC Controller'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
                DriverCapability::AlarmArm,
                DriverCapability::AlarmDisarm,
                DriverCapability::AccessDoorUnlock,
            ],
            summary: 'Live IP alarm reporting works today via SIA DC-09, and Inner Range Inception is supported via local REST. Direct Integriti Integration Server XML/REST control is planned pending licensed Integriti SDK schema validation.',
            officialDocs: [
                ['label' => 'Inner Range Integriti Platform', 'url' => 'https://www.innerrange.com/products/integriti/'],
            ],
            limitationsNote: 'Requires an Integriti Professional/Business server licence with REST/XML Inbound Integration enabled.',
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
