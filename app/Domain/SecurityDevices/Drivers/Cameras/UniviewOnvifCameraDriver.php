<?php

namespace App\Domain\SecurityDevices\Drivers\Cameras;

use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

final class UniviewOnvifCameraDriver extends AbstractOnvifCameraDriver
{
    public function key(): string
    {
        return 'camera-onvif-uniview';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: $this->key(),
            brand: 'Uniview (UNV)',
            name: 'ONVIF Profile S & T network cameras',
            deviceClass: DriverDeviceClass::Camera,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'ONVIF SOAP + HTTP Digest / WS-UsernameToken on Site LAN (port 80)',
            models: ['Easy Series', 'Prime Series', 'Tri-Guard', 'ColorHunter', 'Pro Series PTZ'],
            capabilities: [
                DriverCapability::CameraSnapshot,
                DriverCapability::CameraStreamUri,
                DriverCapability::CameraPtz,
                DriverCapability::CameraDeviceInfo,
            ],
            summary: 'Supports Uniview (UNV) network cameras common across New Zealand commercial and residential-care sites via ONVIF Profile S and Profile T.',
            officialDocs: [
                ['label' => 'ONVIF Profile S', 'url' => 'https://www.onvif.org/profiles/profile-s/'],
                ['label' => 'ONVIF Profile T', 'url' => 'https://www.onvif.org/profiles/profile-t/'],
                ['label' => 'Uniview Official Support', 'url' => 'https://global.uniview.com/Support/'],
            ],
            limitationsNote: 'Built against ONVIF Profile S/T specifications; ensure camera clock is NTP-synchronised for WS-UsernameToken authentication.',
            setupHref: '/security-devices/discovery',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return $this->matchesVendorKeywords($device, ['uniview', 'unv']);
    }
}
