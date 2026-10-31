<?php

namespace App\Domain\SecurityDevices\Drivers\Telemetry;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * RFC 5424 & RFC 3164 Syslog forwarding through the local Site hub (`collector/`).
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
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'Private-LAN UDP Syslog into Site hub -> XChaCha20-Poly1305 encrypted spool -> HTTPS upload',
            models: ['FortiGate', 'MikroTik RouterOS', 'Cisco IOS / Catalyst', 'Ubiquiti UniFi', 'HPE Aruba', 'Linux / NAS appliances'],
            capabilities: [
                DriverCapability::TelemetrySyslog,
                DriverCapability::NetworkHealth,
            ],
            summary: 'Receives RFC 5424 and RFC 3164 Syslog messages on a private house LAN through the Site hub, verifies the sender IP against the signed Site network scope, and uploads structured events to Central.',
            officialDocs: [
                ['label' => 'RFC 5424 — The Syslog Protocol', 'url' => 'https://datatracker.ietf.org/doc/html/rfc5424'],
                ['label' => 'RFC 3164 — BSD Syslog Protocol', 'url' => 'https://datatracker.ietf.org/doc/html/rfc3164'],
            ],
            limitationsNote: 'Sender IPs outside the signed Site CIDR scope are dropped at the Site hub before spooling.',
            setupHref: '/security-devices/collectors',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return data_get($device->config, 'telemetry.syslog_enabled') === true;
    }
}
