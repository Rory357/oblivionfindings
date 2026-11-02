<?php

namespace App\Domain\SecurityDevices\Drivers\Telemetry;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * NetFlow / IPFIX / sFlow received by the Site hub. Not built yet (S&D Phase B): the hub runs
 * short work cycles and has no network listener, so the hub-side receiver was removed. The
 * server can still accept flow items a hub uploads, and has its own flow listener.
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
            supportLevel: DriverSupportLevel::Planned,
            transportSummary: 'Not built yet: the Site hub does not receive NetFlow, IPFIX or sFlow',
            models: ['Cisco Catalyst / ISR', 'FortiGate (NetFlow v9 / IPFIX)', 'MikroTik Traffic Flow (v5 / v9 / IPFIX)', 'HPE Aruba (sFlow v5 / IPFIX)'],
            capabilities: [
                DriverCapability::TelemetryNetflow,
            ],
            summary: 'Not built yet. The Site hub has no flow receiver, so routers and switches at a house cannot send traffic flows to Oblivion through their hub.',
            officialDocs: [
                ['label' => 'RFC 7011 — IPFIX Protocol Specification', 'url' => 'https://datatracker.ietf.org/doc/html/rfc7011'],
                ['label' => 'RFC 3954 — Cisco Systems NetFlow Services Export v9', 'url' => 'https://datatracker.ietf.org/doc/html/rfc3954'],
            ],
            limitationsNote: 'Planned for a later phase. No traffic flows are collected through a hub today.',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return data_get($device->config, 'telemetry.flow_enabled') === true;
    }
}
