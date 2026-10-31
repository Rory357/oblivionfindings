<?php

namespace App\Domain\SecurityDevices\Drivers\Contracts;

use App\Domain\SecurityDevices\Models\Device;

/**
 * Contract for read-only SSH running-configuration export drivers on network infrastructure.
 */
interface NetworkConfigBackupDriver extends DeviceDriver
{
    public function vendorFamily(): string;

    /**
     * Ordered read-only CLI commands to try over SSH (non-interactive exec channel).
     *
     * @return list<string>
     */
    public function exportCommands(Device $device): array;

    /**
     * Strip volatile export timestamps / banners so unchanged configurations
     * produce a stable SHA-256 hash while preserving real configuration lines.
     */
    public function normaliseConfigOutput(string $rawOutput): string;

    /**
     * Case-insensitive substrings that indicate the device CLI rejected the command.
     *
     * @return list<string>
     */
    public function cliErrorPatterns(): array;
}
