<?php

namespace App\Domain\SecurityDevices\Drivers\Telemetry;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Syslog received by the Site hub. Not built yet (S&D Phase B): the hub runs short work
 * cycles and has no network listener, so the hub-side receiver was removed. The server can
 * still accept Syslog items a hub uploads, and has its own Syslog listener.
 */
final class HubSyslogTelemetryDriver implements DeviceDriver
{
    public const string KEY = 'telemetry.hub_syslog';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'IETF Syslog Standard',
            name: 'Syslog (RFC 5424 & RFC 3164) via Site Hub',
            deviceClass: DriverDeviceClass::Telemetry,
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'Not built yet: the Site hub does not receive Syslog',
            models: ['FortiGate', 'MikroTik RouterOS', 'Cisco IOS / Catalyst', 'Ubiquiti UniFi', 'HPE Aruba', 'Linux / NAS appliances'],
            capabilities: [
                DriverCapability::TelemetrySyslog,
            ],
            summary: 'Not built yet. The Site hub has no Syslog receiver, so devices at a house cannot send Syslog to Oblivion through their hub.',
            officialDocs: [
                ['label' => 'RFC 5424 — The Syslog Protocol', 'url' => 'https://datatracker.ietf.org/doc/html/rfc5424'],
                ['label' => 'RFC 3164 — BSD Syslog Protocol', 'url' => 'https://datatracker.ietf.org/doc/html/rfc3164'],
            ],
            limitationsNote: 'Planned for a later phase. Nothing is collected from Syslog through a hub today.',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return data_get($device->config, 'telemetry.syslog_enabled') === true;
    }
}
