<?php

namespace App\Domain\SecurityDevices\Drivers\Network;

use App\Domain\SecurityDevices\Drivers\Contracts\NetworkConfigBackupDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * HPE Aruba Networking AOS-CX & ArubaOS-Switch read-only SSH configuration backup driver.
 *
 * Official HPE Aruba documentation:
 * - https://www.arubanetworks.com/techdocs/AOS-CX/10.13/HTML/fundamentals/Content/Chp_Cfg_FW_mgt/cfg-fil-mgt-cmds/sho-run-cfg-10.htm
 */
final class ArubaAosConfigDriver implements NetworkConfigBackupDriver
{
    public const string KEY = 'network.aruba_aos_ssh';

    public function key(): string
    {
        return self::KEY;
    }

    public function vendorFamily(): string
    {
        return 'aruba_aos';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'HPE Aruba Networking',
            name: 'AOS-CX & ArubaOS-Switch SSH Configuration Backup',
            deviceClass: DriverDeviceClass::Network,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'SSHv2 non-interactive exec (`show running-config`) · SNMP v2c/v3 · LLDP',
            models: ['Aruba CX 6000 / 6100 / 6200 / 6300', 'Aruba 2530 / 2540 / 2930F'],
            capabilities: [
                DriverCapability::NetworkConfigBackup,
                DriverCapability::NetworkDiscovery,
                DriverCapability::NetworkTopology,
                DriverCapability::NetworkHealth,
            ],
            summary: 'Exports HPE Aruba CX and ArubaOS-Switch (ProCurve) running configurations via read-only SSH (`show running-config`), removes volatile header timestamps, redacts ciphertext/RADIUS/SNMP secrets, and stores diffable backups.',
            officialDocs: [
                ['label' => 'HPE Aruba AOS-CX Configuration Guide', 'url' => 'https://www.arubanetworks.com/techdocs/AOS-CX/10.13/HTML/fundamentals/Content/Chp_Cfg_FW_mgt/cfg-fil-mgt-cmds/sho-run-cfg-10.htm'],
            ],
            limitationsNote: 'Requires an Aruba `auditors` or `administrators` SSH account able to run `show running-config` on a non-interactive SSH exec channel.',
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

        return str_contains($haystack, 'aruba') || str_contains($haystack, 'aos-cx') || str_contains($haystack, 'procurve');
    }

    public function exportCommands(Device $device): array
    {
        return ['show running-config'];
    }

    public function normaliseConfigOutput(string $rawOutput): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $rawOutput) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = rtrim($line);
            if (preg_match('/^(?:Running configuration:|;\s*\S+\s+Configuration Editor;\s*Created on\b|!\s*Current configuration\b)/i', $trimmed) === 1) {
                continue;
            }
            $kept[] = $trimmed;
        }

        return trim(implode("\n", $kept));
    }

    public function cliErrorPatterns(): array
    {
        return [
            'invalid input:',
            '% invalid input',
            'ambiguous input:',
            'not authorized',
        ];
    }
}
