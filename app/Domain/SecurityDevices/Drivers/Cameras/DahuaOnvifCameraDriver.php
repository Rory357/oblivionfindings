<?php

namespace App\Domain\SecurityDevices\Drivers\Cameras;

use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

final class DahuaOnvifCameraDriver extends AbstractOnvifCameraDriver
{
    public function key(): string
    {
        return 'camera-onvif-dahua';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: $this->key(),
            brand: 'Dahua Technology',
            name: 'ONVIF Profile S & T network cameras',
            deviceClass: DriverDeviceClass::Camera,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'ONVIF SOAP + RFC 7616 HTTP Digest / WS-UsernameToken on Site LAN (port 80/443)',
            models: ['WizSense', 'WizMind', 'TiOC', 'Lite Series', 'SD PTZ Series'],
            capabilities: [
                DriverCapability::CameraSnapshot,
                DriverCapability::CameraStreamUri,
                DriverCapability::CameraPtz,
                DriverCapability::CameraDeviceInfo,
            ],
            summary: 'Supports Dahua IP cameras for still snapshots, Profile S/T RTSP stream inspection, device metadata, and PTZ movement.',
            officialDocs: [
                ['label' => 'ONVIF Profile S', 'url' => 'https://www.onvif.org/profiles/profile-s/'],
                ['label' => 'ONVIF Profile T', 'url' => 'https://www.onvif.org/profiles/profile-t/'],
                ['label' => 'Dahua Wiki & ONVIF Setup', 'url' => 'https://dahuawiki.com/'],
            ],
            limitationsNote: 'Built from ONVIF Profile S/T specifications; verify ONVIF authentication is enabled under Network › Access Platform › ONVIF.',
            setupHref: '/security-devices/discovery',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return $this->matchesVendorKeywords($device, ['dahua', 'imou']);
    }
}
