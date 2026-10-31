<?php

namespace App\Domain\SecurityDevices\Drivers\Network;

use App\Domain\SecurityDevices\Drivers\Contracts\NetworkConfigBackupDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Fortinet FortiGate (FortiOS) read-only SSH configuration backup driver.
 *
 * Official Fortinet CLI documentation:
 * - https://docs.fortinet.com/document/fortigate/7.2.0/cli-reference/95860/show-full-configuration
 */
final class FortinetFortiOsConfigDriver implements NetworkConfigBackupDriver
{
    public const string KEY = 'network.fortinet_fortios_ssh';

    public function key(): string
    {
        return self::KEY;
    }

    public function vendorFamily(): string
    {
        return 'fortinet_fortios';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Fortinet',
            name: 'FortiGate FortiOS SSH Configuration Backup',
            deviceClass: DriverDeviceClass::Network,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'SSHv2 non-interactive exec (`show`) · SNMP v2c/v3 · Syslog / NetFlow v9 / IPFIX',
            models: ['FortiGate 40F / 60F / 80F / 100F', 'FortiWiFi 60F'],
            capabilities: [
                DriverCapability::NetworkConfigBackup,
                DriverCapability::NetworkDiscovery,
                DriverCapability::NetworkHealth,
                DriverCapability::TelemetrySyslog,
                DriverCapability::TelemetryNetflow,
            ],
            summary: 'Exports FortiGate non-default running configuration via read-only SSH (`show`), redacts `ENC` password hashes, pre-shared keys, and private keys, and stores diffable encrypted backups.',
            officialDocs: [
                ['label' => 'FortiOS CLI Configuration Reference', 'url' => 'https://docs.fortinet.com/document/fortigate/7.2.0/cli-reference/95860/show-full-configuration'],
            ],
            limitationsNote: 'Uses `show` (non-default configuration) so snapshots stay concise and diff-friendly; assign a read-only `prof_admin` read profile for least privilege.',
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

        return str_contains($haystack, 'fortinet') || str_contains($haystack, 'fortigate') || str_contains($haystack, 'fortios') || str_contains($haystack, 'fortiwifi');
    }

    public function exportCommands(Device $device): array
    {
        return ['show'];
    }

    public function normaliseConfigOutput(string $rawOutput): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $rawOutput) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = rtrim($line);
            if (preg_match('/^#conf_file_ver=\d+/i', $trimmed) === 1) {
                continue;
            }
            $kept[] = $trimmed;
        }

        return trim(implode("\n", $kept));
    }

    public function cliErrorPatterns(): array
    {
        return [
            'command parse error',
            'unknown action',
            'permission denied',
        ];
    }
}
