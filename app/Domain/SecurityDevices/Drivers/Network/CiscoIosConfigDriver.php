<?php

namespace App\Domain\SecurityDevices\Drivers\Network;

use App\Domain\SecurityDevices\Drivers\Contracts\NetworkConfigBackupDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Cisco IOS & IOS-XE read-only SSH configuration backup driver.
 *
 * Official Cisco documentation:
 * - https://www.cisco.com/c/en/us/td/docs/ios-xml/ios/fundamentals/configuration/16/fund-16-book/fund-mgmt-files.html
 */
final class CiscoIosConfigDriver implements NetworkConfigBackupDriver
{
    public const string KEY = 'network.cisco_ios_ssh';

    public function key(): string
    {
        return self::KEY;
    }

    public function vendorFamily(): string
    {
        return 'cisco_ios';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Cisco',
            name: 'IOS & IOS-XE SSH Configuration Backup',
            deviceClass: DriverDeviceClass::Network,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'SSHv2 non-interactive exec (`show running-config`) · SNMP v2c/v3 · LLDP / CDP',
            models: ['Catalyst 1000 / 2960-X / 9200 / 9300', 'CBS350', 'ISR 1100 / 4000'],
            capabilities: [
                DriverCapability::NetworkConfigBackup,
                DriverCapability::NetworkDiscovery,
                DriverCapability::NetworkTopology,
                DriverCapability::NetworkHealth,
            ],
            summary: 'Captures Cisco Catalyst & ISR running configurations via non-interactive SSH exec (`show running-config`), strips volatile byte-count and timestamp headers, redacts Type 0/5/7/8/9 secrets, and stores diffable backups.',
            officialDocs: [
                ['label' => 'Cisco IOS Configuration Files Guide', 'url' => 'https://www.cisco.com/c/en/us/td/docs/ios-xml/ios/fundamentals/configuration/16/fund-16-book/fund-mgmt-files.html'],
            ],
            limitationsNote: 'SSH user account must be granted privilege 15 or an RBAC parser view permitting `show running-config` without an interactive `enable` prompt.',
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

        return str_contains($haystack, 'cisco') || str_contains($haystack, 'catalyst') || str_contains($haystack, 'ios-xe');
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
            if (preg_match('/^(?:Building configuration\.\.\.|Current configuration\s*:\s*\d+\s*bytes|!\s*(?:Last configuration change|NVRAM config last updated|Time:)\b)/i', $trimmed) === 1) {
                continue;
            }
            $kept[] = $trimmed;
        }

        return trim(implode("\n", $kept));
    }

    public function cliErrorPatterns(): array
    {
        return [
            '% invalid input detected',
            '% incomplete command',
            '% authorization failed',
            'command authorization failed',
        ];
    }
}
