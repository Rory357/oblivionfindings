<?php

namespace App\Domain\SecurityDevices\Drivers\Sensors;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Milesight Development Platform (Cloud OpenAPI v2) descriptor.
 *
 * Official documentation:
 * - https://www.milesight.com/iot/resources/download-center/
 */
final class MilesightCloudSensorDriver implements DeviceDriver
{
    public const string KEY = 'sensor.milesight_development_platform';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Milesight IoT',
            name: 'Milesight Development Platform Cloud OpenAPI',
            deviceClass: DriverDeviceClass::Sensor,
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'Milesight OpenAPI v2 OAuth2 & Webhook (planned)',
            models: ['WT101', 'TS302 Cellular Temperature Sensor'],
            capabilities: [
                DriverCapability::SensorReadings,
                DriverCapability::SensorBattery,
                DriverCapability::SensorContactAlert,
            ],
            summary: 'Local Milesight UG56/UG65/UG67 LoRaWAN gateways work today over MQTT & HTTPS Push. Direct OAuth2 cloud polling against Milesight Development Platform is planned for Sites using cellular-only sensors without a local gateway.',
            officialDocs: [
                ['label' => 'Milesight IoT Documentation Center', 'url' => 'https://www.milesight.com/iot/resources/download-center/'],
            ],
            limitationsNote: 'For LoRaWAN sensors at a house with a UG56/UG65/UG67 gateway, use the local Milesight LoRaWAN Gateway Push driver instead.',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return strtolower(trim((string) $device->provider)) === 'milesight_cloud';
    }
}
