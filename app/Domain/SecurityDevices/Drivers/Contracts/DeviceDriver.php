<?php

namespace App\Domain\SecurityDevices\Drivers\Contracts;

use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Common Home-Assistant-style driver contract for Security & Devices.
 *
 * Every brand/protocol integration declares its device class, capabilities,
 * honest verification level, and official vendor or standard specification URLs.
 */
interface DeviceDriver
{
    public function key(): string;

    public function metadata(): DriverMetadata;

    public function matchesDevice(Device $device): bool;
}
