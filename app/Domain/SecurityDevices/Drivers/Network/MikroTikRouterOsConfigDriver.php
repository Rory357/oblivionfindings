<?php

namespace App\Domain\SecurityDevices\Drivers\Network;

use App\Domain\SecurityDevices\Drivers\Contracts\NetworkConfigBackupDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * MikroTik RouterOS v6 & v7 read-only SSH configuration backup driver.
 *
 * Official MikroTik documentation:
 * - https://help.mikrotik.com/docs/spaces/ROS/pages/328173/Configuration+Management
 */
final class MikroTikRouterOsConfigDriver implements NetworkConfigBackupDriver
{
    public const string KEY = 'network.mikrotik_routeros_ssh';

    public function key(): string
    {
        return self::KEY;
    }

    public function vendorFamily(): string
    {
        return 'mikrotik_routeros';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'MikroTik',
            name: 'RouterOS v6 / v7 SSH Configuration Backup',
            deviceClass: DriverDeviceClass::Network,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'SSHv2 non-interactive exec (`/export terse hide-sensitive`) · SNMP v2c/v3 · LLDP / MNDP',
            models: ['hEX (RB750Gr3)', 'RB4011 / RB5009', 'CRS326 / CRS328', 'CCR2004'],
            capabilities: [
                DriverCapability::NetworkConfigBackup,
                DriverCapability::NetworkDiscovery,
                DriverCapability::NetworkTopology,
                DriverCapability::NetworkHealth,
            ],
            summary: 'Exports MikroTik RouterOS running configuration over read-only SSH (`/export terse hide-sensitive`), strips volatile export timestamps, redacts residual secrets, and stores diffable encrypted snapshots.',
            officialDocs: [
                ['label' => 'MikroTik RouterOS Configuration Management', 'url' => 'https://help.mikrotik.com/docs/spaces/ROS/pages/328173/Configuration+Management'],
            ],
            limitationsNote: 'RouterOS v7 hides sensitive fields by default on `/export terse`; RouterOS v6 requires `hide-sensitive` (tried automatically first).',
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

        return str_contains($haystack, 'mikrotik') || str_contains($haystack, 'routeros') || str_contains($haystack, 'routerboard');
    }

    public function exportCommands(Device $device): array
    {
        return [
            '/export terse hide-sensitive',
            '/export terse',
        ];
    }

    public function normaliseConfigOutput(string $rawOutput): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $rawOutput) ?: [];
        $kept = [];

        foreach ($lines as $index => $line) {
            $trimmed = rtrim($line);
            // Strip RouterOS volatile export header timestamp (e.g. "# oct/28/2026 09:00:00 by RouterOS 7.15" or "# 2026-10-28 09:00:00 by RouterOS 7.16")
            if ($index < 5 && preg_match('/^#\s*(?:[a-z]{3}\/\d{2}\/\d{4}|\d{4}-\d{2}-\d{2})\s+\d{2}:\d{2}:\d{2}\s+by\s+RouterOS\b/i', $trimmed) === 1) {
                continue;
            }
            $kept[] = $trimmed;
        }

        return trim(implode("\n", $kept));
    }

    public function cliErrorPatterns(): array
    {
        return [
            'bad command name',
            'syntax error (line',
            'not enough permissions',
            'expected end of command',
        ];
    }
}
