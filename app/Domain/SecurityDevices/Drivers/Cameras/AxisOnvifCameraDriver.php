<?php

namespace App\Domain\SecurityDevices\Drivers\Cameras;

use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

final class AxisOnvifCameraDriver extends AbstractOnvifCameraDriver
{
    public function key(): string
    {
        return 'camera-onvif-axis';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: $this->key(),
            brand: 'Axis Communications',
            name: 'ONVIF Profile S & T network cameras',
            deviceClass: DriverDeviceClass::Camera,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'ONVIF SOAP + HTTP Digest / WS-UsernameToken on Site LAN (port 80/443)',
            models: ['AXIS M-Series', 'AXIS P-Series', 'AXIS Q-Series', 'AXIS Companion'],
            capabilities: [
                DriverCapability::CameraSnapshot,
                DriverCapability::CameraStreamUri,
                DriverCapability::CameraPtz,
                DriverCapability::CameraDeviceInfo,
            ],
            summary: 'Connects to Axis network cameras using a dedicated ONVIF user created in AXIS OS System › ONVIF.',
            officialDocs: [
                ['label' => 'Axis ONVIF Developer Documentation', 'url' => 'https://developer.axis.com/vapix/onvif/'],
                ['label' => 'ONVIF Profile T', 'url' => 'https://www.onvif.org/profiles/profile-t/'],
            ],
            limitationsNote: 'Built from Axis ONVIF and Profile S/T documentation; create a dedicated ONVIF Media/Operator account in AXIS OS.',
            setupHref: '/security-devices/discovery',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return $this->matchesVendorKeywords($device, ['axis']);
    }
}
