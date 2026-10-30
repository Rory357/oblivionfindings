<?php

namespace App\Domain\SecurityDevices\Drivers\Cameras;

use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

final class HanwhaVisionOnvifCameraDriver extends AbstractOnvifCameraDriver
{
    public function key(): string
    {
        return 'camera-onvif-hanwha';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: $this->key(),
            brand: 'Hanwha Vision',
            name: 'Wisenet ONVIF Profile S & T cameras',
            deviceClass: DriverDeviceClass::Camera,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'ONVIF SOAP + RFC 7616 SHA-256/MD5 HTTP Digest on Site LAN (port 80/443)',
            models: ['Wisenet Q Series', 'Wisenet X Series', 'Wisenet P Series', 'Wisenet A Series'],
            capabilities: [
                DriverCapability::CameraSnapshot,
                DriverCapability::CameraStreamUri,
                DriverCapability::CameraPtz,
                DriverCapability::CameraDeviceInfo,
            ],
            summary: 'Supports Hanwha Vision Wisenet cameras over ONVIF Profile S and Profile T with SHA-256 and MD5 HTTP Digest authentication.',
            officialDocs: [
                ['label' => 'ONVIF Profile S', 'url' => 'https://www.onvif.org/profiles/profile-s/'],
                ['label' => 'ONVIF Profile T', 'url' => 'https://www.onvif.org/profiles/profile-t/'],
                ['label' => 'Hanwha Vision Technical Support', 'url' => 'https://www.hanwhavision.com/'],
            ],
            limitationsNote: 'Built against ONVIF Profile S/T and RFC 7616 HTTP Digest specifications.',
            setupHref: '/security-devices/discovery',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return $this->matchesVendorKeywords($device, ['hanwha', 'wisenet', 'samsung techwin']);
    }
}
