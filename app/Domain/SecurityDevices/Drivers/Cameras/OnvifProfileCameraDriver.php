<?php

namespace App\Domain\SecurityDevices\Drivers\Cameras;

use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

final class OnvifProfileCameraDriver extends AbstractOnvifCameraDriver
{
    public function key(): string
    {
        return 'camera-onvif-standard';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: $this->key(),
            brand: 'ONVIF Open Standard (including TP-Link Tapo / VIGI)',
            name: 'ONVIF Profile S & Profile T IP cameras',
            deviceClass: DriverDeviceClass::Camera,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'ONVIF SOAP (`devicemgmt`, `Media1`, `Media2`, `PTZ`) + HTTP Digest / WS-UsernameToken on ports 80, 443, 2020, 8000, 8080, 8899',
            models: ['TP-Link Tapo C120 / C210 / C320WS (port 2020)', 'TP-Link VIGI', 'Reolink IP (ONVIF enabled)', 'Any ONVIF Profile S / Profile T camera'],
            capabilities: [
                DriverCapability::CameraSnapshot,
                DriverCapability::CameraStreamUri,
                DriverCapability::CameraPtz,
                DriverCapability::CameraDeviceInfo,
            ],
            summary: 'Standard standards-based driver for any ONVIF Profile S (H.264/JPEG) or Profile T (H.265/H.264) network camera on an approved Site subnet.',
            officialDocs: [
                ['label' => 'ONVIF Profile S Specification', 'url' => 'https://www.onvif.org/profiles/profile-s/'],
                ['label' => 'ONVIF Profile T Specification', 'url' => 'https://www.onvif.org/profiles/profile-t/'],
                ['label' => 'ONVIF Device & Media WSDLs', 'url' => 'https://www.onvif.org/ch10/20/'],
            ],
            limitationsNote: 'TP-Link Tapo cameras keep ONVIF port 2020 and RTSP port 554 closed until a Camera Account is enabled in the Tapo app (LAN discovery on 172.16.0.78 verified over HTTPS/8800 and MAC OUI).',
            setupHref: '/security-devices/discovery',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return $this->isCctvDevice($device);
    }
}
