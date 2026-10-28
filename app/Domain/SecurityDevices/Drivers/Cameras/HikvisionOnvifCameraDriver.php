<?php

namespace App\Domain\SecurityDevices\Drivers\Cameras;

use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

final class HikvisionOnvifCameraDriver extends AbstractOnvifCameraDriver
{
    public function key(): string
    {
        return 'camera-onvif-hikvision';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: $this->key(),
            brand: 'Hikvision',
            name: 'ONVIF Profile S & T network cameras',
            deviceClass: DriverDeviceClass::Camera,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'ONVIF SOAP + RFC 7616 HTTP Digest / WS-UsernameToken on Site LAN (port 80/443)',
            models: ['AcuSense', 'ColorVu', 'Value Series', 'Ultra Series', 'DarkFighter PTZ', 'HiLook IP'],
            capabilities: [
                DriverCapability::CameraSnapshot,
                DriverCapability::CameraStreamUri,
                DriverCapability::CameraPtz,
                DriverCapability::CameraDeviceInfo,
            ],
            summary: 'Connects to Hikvision IP cameras and NVRs once ONVIF integration is enabled in the camera Network › Integration Protocol settings with an ONVIF operator account.',
            officialDocs: [
                ['label' => 'ONVIF Profile S', 'url' => 'https://www.onvif.org/profiles/profile-s/'],
                ['label' => 'ONVIF Profile T', 'url' => 'https://www.onvif.org/profiles/profile-t/'],
                ['label' => 'Hikvision Technology Partner Portal', 'url' => 'https://tpp.hikvision.com/'],
            ],
            limitationsNote: 'Built against ONVIF Profile S/T and HTTP Digest specifications; enable ONVIF user authentication on Hikvision firmware 5.5+.',
            setupHref: '/security-devices/discovery',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return $this->matchesVendorKeywords($device, ['hikvision', 'hilook']);
    }
}
