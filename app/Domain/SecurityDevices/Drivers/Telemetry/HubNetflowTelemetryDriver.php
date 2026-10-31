<?php

namespace App\Domain\SecurityDevices\Drivers\Telemetry;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * NetFlow v5, NetFlow v9 (RFC 3954), IPFIX (RFC 7011), and sFlow v5 through the local Site hub.
 */
final class HubNetflowTelemetryDriver implements DeviceDriver
{
    public const string KEY = 'telemetry.hub_netflow_ipfix';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'IETF IPFIX & Cisco NetFlow Standard',
            name: 'NetFlow v5 / v9, IPFIX & sFlow v5 via Site Hub',
            deviceClass: DriverDeviceClass::Telemetry,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'Private-LAN UDP NetFlow / IPFIX into Site hub -> XChaCha20-Poly1305 encrypted spool -> HTTPS upload',
            models: ['Cisco Catalyst / ISR', 'FortiGate (NetFlow v9 / IPFIX)', 'MikroTik Traffic Flow (v5 / v9 / IPFIX)', 'HPE Aruba (sFlow v5 / IPFIX)'],
            capabilities: [
                DriverCapability::TelemetryNetflow,
                DriverCapability::NetworkHealth,
            ],
            summary: 'Collects NetFlow v5, NetFlow v9, IPFIX, and sFlow v5 flow exports from private-LAN routers and switches through the Site hub, validates exporter sequence health, and aggregates Site traffic metrics.',
            officialDocs: [
                ['label' => 'RFC 7011 — IPFIX Protocol Specification', 'url' => 'https://datatracker.ietf.org/doc/html/rfc7011'],
                ['label' => 'RFC 3954 — Cisco Systems NetFlow Services Export v9', 'url' => 'https://datatracker.ietf.org/doc/html/rfc3954'],
            ],
            limitationsNote: 'Aggregates flow metadata (bytes, packets, ports, sequence gaps); deep packet inspection payloads are never captured.',
            setupHref: '/security-devices/collectors',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return data_get($device->config, 'telemetry.flow_enabled') === true;
    }
}
