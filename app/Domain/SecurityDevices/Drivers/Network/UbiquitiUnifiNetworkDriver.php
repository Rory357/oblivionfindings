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
 * Hardware-verified on live UniFi OS hardware (UX7, USW-Lite-8-PoE, U6+) on Kauri House LAN.
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
            supportLevel: DriverSupportLevel::TestedOnHardware,
            transportSummary: 'UniFi OS HTTPS API via Site hub · LLDP · SNMP v2c/v3',
            models: ['UniFi Express 7 (UX7)', 'USW-Lite-8-PoE', 'U6+ / U7 Pro', 'UDM Pro / SE'],
            capabilities: [
                DriverCapability::NetworkDiscovery,
                DriverCapability::NetworkTopology,
                DriverCapability::NetworkHealth,
                DriverCapability::NetworkPoeCycle,
                DriverCapability::NetworkReboot,
            ],
            summary: 'Discovers UniFi gateways, PoE switches, Wi-Fi access points, port states, uplinks, and client neighbours directly from a local UniFi OS console through the Site hub or Site Manager.',
            officialDocs: [
                ['label' => 'Ubiquiti Developer Documentation', 'url' => 'https://developer.ui.com/'],
            ],
            hardwareEvidence: 'Tested live on UniFi Express 7 (UX7, 172.16.0.1), USW-Lite-8-PoE (172.16.0.132), and U6+ access points (172.16.0.141 / 172.16.0.168).',
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
