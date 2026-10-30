<?php

namespace App\Domain\SecurityDevices\Drivers\Sensors;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Standard OASIS MQTT v3.1.1 IoT & environmental sensor driver.
 *
 * Official specification:
 * - http://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html
 */
final class MqttGenericSensorDriver implements DeviceDriver
{
    public const string KEY = 'sensor.mqtt_v311';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'OASIS MQTT Standard',
            name: 'MQTT v3.1.1 Sensor & Alert Subscriber',
            deviceClass: DriverDeviceClass::Sensor,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'OASIS MQTT v3.1.1 over TCP (1883) or TLS (8883) · JSON & scalar topic payloads',
            models: ['Shelly Plus H&T', 'Zigbee2MQTT Door/Leak/Temp bridges', 'Monnit / Dragino MQTT bridges'],
            capabilities: [
                DriverCapability::SensorReadings,
                DriverCapability::SensorBattery,
                DriverCapability::SensorContactAlert,
                DriverCapability::SensorButtonAlert,
            ],
            summary: 'Subscribes to Site-scoped MQTT / MQTTS broker topics (`oblivion/sensors/#`, Zigbee2MQTT, Shelly, Tasmota, or gateway bridges) for temperature, humidity, CO2, leak, door contact, call button, and battery telemetry.',
            officialDocs: [
                ['label' => 'OASIS MQTT v3.1.1 Standard', 'url' => 'https://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html'],
            ],
            limitationsNote: 'Read-only sensor telemetry and alert intake; outbound MQTT actuator publishing is intentionally disabled unless routed through a confirmed capability.',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if (! in_array($device->domain, ['iot_healthcare', 'security', 'facilities'], true)) {
            return false;
        }

        $topic = trim((string) (data_get($device->config, 'mqtt.topic') ?? data_get($device->meta, 'mqtt_topic') ?? ''));
        if ($topic !== '') {
            return true;
        }

        $protocol = strtolower(trim((string) (data_get($device->config, 'protocol') ?? $device->provider ?? '')));

        return in_array($protocol, ['mqtt', 'mqtts', 'zigbee2mqtt'], true);
    }
}
