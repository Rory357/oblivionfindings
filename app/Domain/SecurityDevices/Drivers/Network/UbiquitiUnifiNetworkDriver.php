<?php

namespace App\Domain\SecurityDevices\Drivers\Network;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Ubiquiti UniFi Network (Local Gateway / Cloud Key / Site Manager) driver.
 *
 * Not proven on real hardware yet. An earlier note claimed tests on UX7, USW-Lite-8-PoE and U6+
 * with no recorded walk-through, so the claim was removed in Phase A (docs/security-devices/GOAL.md).
 * PoE power-cycle and restart are not built; they come back only when built and walked.
 */
final class UbiquitiUnifiNetworkDriver implements DeviceDriver
{
    public const string KEY = 'network.ubiquiti_unifi';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Ubiquiti',
            name: 'UniFi Network Local & Site Manager API',
            deviceClass: DriverDeviceClass::Network,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'UniFi OS local HTTPS API · LLDP · SNMP',
            models: ['UniFi Express 7 (UX7)', 'USW-Lite-8-PoE', 'U6+ / U7 Pro', 'UDM Pro / SE'],
            capabilities: [
                DriverCapability::NetworkDiscovery,
                DriverCapability::NetworkTopology,
                DriverCapability::NetworkHealth,
            ],
            summary: 'Reads UniFi gateways, switches, Wi-Fi points, port states, uplinks and connected devices from a UniFi OS console. It only reads: power-cycling a port and restarting a device aren’t built yet. Not tested on real hardware yet.',
            officialDocs: [
                ['label' => 'Ubiquiti Developer Documentation', 'url' => 'https://developer.ui.com/'],
            ],
            setupHref: '/security-devices/integrations',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if ($device->domain !== 'it_infrastructure' || ! in_array($device->category, ['network', 'networking'], true)) {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $device->manufacturer,
            (string) $device->model,
            (string) $device->provider,
        ])));

        return str_contains($haystack, 'ubiquiti') || str_contains($haystack, 'unifi');
    }
}
