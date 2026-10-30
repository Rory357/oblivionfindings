<?php

namespace App\Domain\SecurityDevices\Drivers\Endpoints;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Endpoints\Presenters\EndpointDevicePosturePresenter;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Windows computer support service and Obli tray helper driver descriptor.
 *
 * Official Microsoft documentation:
 * - https://learn.microsoft.com/en-us/windows/win32/services/services
 * - https://learn.microsoft.com/en-us/windows/win32/direct3ddxgi/desktop-dup-api
 */
final class WindowsEndpointObliDriver implements DeviceDriver
{
    public const string KEY = 'computer.oblivion_windows_obli';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Microsoft Windows',
            name: 'Windows Computer Service & Obli Tray Helper',
            deviceClass: DriverDeviceClass::Computer,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'HTTPS Ed25519 signed heartbeats · Local Obli tray IPC · WebRTC attended screen share',
            models: [
                'Windows 10 / 11 Pro & Enterprise (x64)',
                'Windows Server 2019 / 2022 / 2025 (x64)',
            ],
            capabilities: [
                DriverCapability::ComputerPosture,
                DriverCapability::ComputerServiceControl,
                DriverCapability::ComputerRestart,
                DriverCapability::ComputerSoftware,
                DriverCapability::ComputerAttendedRemote,
            ],
            summary: 'Installs the Windows computer support service and starts the Obli tray helper for signed-in staff via a one-time setup code (ofc1_) or profile file. Reports disk, memory, BitLocker, and update health, executes approved service, restart, and software actions, and requires explicit Allow / Decline consent with a persistent Stop banner for attended screen help.',
            officialDocs: [
                [
                    'label' => 'Microsoft Windows Services Documentation',
                    'url' => 'https://learn.microsoft.com/en-us/windows/win32/services/services',
                ],
                [
                    'label' => 'Microsoft Desktop Duplication API',
                    'url' => 'https://learn.microsoft.com/en-us/windows/win32/direct3ddxgi/desktop-dup-api',
                ],
            ],
            limitationsNote: 'Attended screen help requires remote support to be enabled in Settings › Obli and requires the person at the computer to click Allow. Arbitrary PowerShell or command-line script execution is disabled.',
            setupHref: '/security-devices/agent-downloads',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return EndpointDevicePosturePresenter::isComputer($device)
            && strtolower(trim((string) $device->provider)) !== 'intune';
    }
}
