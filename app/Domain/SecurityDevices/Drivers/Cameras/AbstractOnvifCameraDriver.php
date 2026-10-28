<?php

namespace App\Domain\SecurityDevices\Drivers\Cameras;

use App\Domain\SecurityDevices\Cctv\Services\OnvifCameraProfileClient;
use App\Domain\SecurityDevices\Drivers\Contracts\CameraDeviceDriver;
use App\Domain\SecurityDevices\Models\Device;

abstract class AbstractOnvifCameraDriver implements CameraDeviceDriver
{
    public function __construct(
        protected readonly OnvifCameraProfileClient $onvif,
    ) {}

    public function captureSnapshot(Device $device): array
    {
        return $this->onvif->captureSnapshot($device);
    }

    public function performPtz(Device $device, string $action): array
    {
        return $this->onvif->performPtz($device, $action);
    }

    public function probeStreams(Device $device): array
    {
        $result = $this->onvif->probeStreams($device);

        return [
            'ok' => $result['ok'],
            'reason_code' => $result['reason_code'],
            'message' => $result['message'],
            'profiles' => array_map(
                static fn (array $profile): array => [
                    'token' => $profile['token'],
                    'name' => $profile['name'],
                    'encoding' => $profile['encoding'],
                    'stream_uri' => $profile['stream_uri'],
                    'snapshot_uri' => $profile['snapshot_uri'],
                ],
                $result['profiles'],
            ),
            'device_info' => $result['device_info'],
        ];
    }

    protected function isCctvDevice(Device $device): bool
    {
        return $device->domain === 'security' && $device->category === 'cctv';
    }

    /**
     * @param  list<string>  $keywords
     */
    protected function matchesVendorKeywords(Device $device, array $keywords): bool
    {
        if (! $this->isCctvDevice($device)) {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $device->manufacturer,
            (string) $device->model,
            (string) $device->provider,
        ])));

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($haystack, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}
