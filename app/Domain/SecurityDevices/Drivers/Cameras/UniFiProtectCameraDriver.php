<?php

namespace App\Domain\SecurityDevices\Drivers\Cameras;

use App\Domain\SecurityDevices\Cctv\Services\UniFiProtectLocalCameraClient;
use App\Domain\SecurityDevices\Drivers\Contracts\CameraDeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

final class UniFiProtectCameraDriver implements CameraDeviceDriver
{
    public function __construct(
        private readonly UniFiProtectLocalCameraClient $protect,
    ) {}

    public function key(): string
    {
        return 'camera-unifi-protect-local';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: $this->key(),
            brand: 'Ubiquiti UniFi',
            name: 'UniFi Protect local camera API',
            deviceClass: DriverDeviceClass::Camera,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'Local HTTPS API (`/proxy/protect/integration/v1`) through the Site hub with `X-API-KEY`',
            models: ['G4 Bullet / Dome / Pro / Instant', 'G5 Bullet / Dome / Turret Ultra / Flex', 'AI Pro / AI Theta / AI PTZ'],
            capabilities: [
                DriverCapability::CameraSnapshot,
                DriverCapability::CameraStreamUri,
                DriverCapability::CameraDeviceInfo,
            ],
            summary: 'Uses Ubiquiti\'s official local Protect Integration v1 API on the Site\'s UniFi OS Console or UNVR to discover cameras, capture still snapshots, and inspect RTSPS streams without relying on undocumented bootstrap endpoints.',
            officialDocs: [
                ['label' => 'UniFi Protect Integration API v1', 'url' => 'https://developer.ui.com/protect'],
                ['label' => 'Ubiquiti Developer Portal', 'url' => 'https://developer.ui.com/'],
            ],
            limitationsNote: 'Built against the official Ubiquiti Protect Integration API v1 specification; create a local Protect API key in UniFi OS › Settings › Control Plane › Integrations.',
            setupHref: '/security-devices/integrations/unifi',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if ($device->domain !== 'security' || $device->category !== 'cctv') {
            return false;
        }

        if (data_get($device->external_ref, 'protect_camera_id') !== null
            || data_get($device->config, 'protect.camera_id') !== null) {
            return true;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $device->manufacturer,
            (string) $device->model,
            (string) $device->provider,
        ])));

        return str_contains($haystack, 'unifi_protect')
            || str_contains($haystack, 'unifi protect')
            || str_contains($haystack, 'ubiquiti')
            || str_contains($haystack, 'uvc-');
    }

    public function captureSnapshot(Device $device): array
    {
        return $this->protect->captureSnapshot($device);
    }

    public function performPtz(Device $device, string $action): array
    {
        return [
            'ok' => false,
            'reason_code' => 'ptz_not_supported',
            'message' => 'Direct PTZ movement is not exposed on the public UniFi Protect Integration v1 camera endpoint yet.',
            'action' => $action,
        ];
    }

    public function probeStreams(Device $device): array
    {
        return $this->protect->probeStreams($device);
    }
}
