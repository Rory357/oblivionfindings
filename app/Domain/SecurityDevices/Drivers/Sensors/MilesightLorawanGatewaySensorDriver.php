<?php

namespace App\Domain\SecurityDevices\Drivers\Sensors;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Milesight LoRaWAN Gateway (UG56 / UG65 / UG67) local MQTT & HTTP(S) Push sensor driver.
 *
 * Official documentation:
 * - https://support.milesight-iot.com/kb/guide-1065.html
 * - https://support.milesight-iot.com/kb/guide-1282.html
 * - https://support.milesight-iot.com/kb/guide-1262.html
 */
final class MilesightLorawanGatewaySensorDriver implements DeviceDriver
{
    public const string KEY = 'sensor.milesight_lorawan_gateway';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Milesight IoT',
            name: 'LoRaWAN Gateway Push & Clean-Room TLV Decoder',
            deviceClass: DriverDeviceClass::Sensor,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'LoRaWAN via UG56 / UG65 / UG67 · HTTPS webhook with the Site’s secret (Bearer, token header or password) & MQTT v3.1.1 · Binary TLV',
            models: [
                'AM103 / AM307 / AM319 (Temp, Humidity, CO2)',
                'EM300-TH / EM300-SLD (Leak) / EM300-MCS (Door)',
                'WS101 Smart Button',
                'WS202 PIR & Light',
                'WS301 Magnetic Contact',
                'VS121 People Counter',
            ],
            capabilities: [
                DriverCapability::SensorReadings,
                DriverCapability::SensorBattery,
                DriverCapability::SensorContactAlert,
                DriverCapability::SensorButtonAlert,
            ],
            summary: 'Receives LoRaWAN sensor uplinks directly from Milesight UG56, UG65, and UG67 gateways over authenticated HTTPS webhook or MQTT and decodes binary Milesight TLV payloads without cloud dependency.',
            officialDocs: [
                ['label' => 'Milesight Gateway HTTP/HTTPS & MQTT Integration', 'url' => 'https://support.milesight-iot.com/kb/guide-1065.html'],
                ['label' => 'Milesight Payload Codec & TLV Format Guide', 'url' => 'https://support.milesight-iot.com/kb/guide-1282.html'],
                ['label' => 'Milesight LoRaWAN Sensor Uplink Guide', 'url' => 'https://support.milesight-iot.com/kb/guide-1262.html'],
            ],
            limitationsNote: 'Uplink telemetry and event intake only; LoRaWAN Class A/C downlink configuration frames are not sent from Oblivion Findings.',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if (! in_array($device->domain, ['iot_healthcare', 'security', 'facilities'], true)) {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $device->manufacturer,
            (string) $device->model,
            (string) $device->provider,
        ])));

        if (str_contains($haystack, 'milesight')) {
            return true;
        }

        $devEui = trim((string) (data_get($device->config, 'lorawan.dev_eui') ?? data_get($device->external_ref, 'dev_eui') ?? ''));

        return $devEui !== '' && strlen((string) preg_replace('/[^0-9a-fA-F]/', '', $devEui)) === 16;
    }
}
