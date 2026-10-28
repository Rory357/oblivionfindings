<?php

namespace App\Domain\SecurityDevices\Drivers\Contracts;

use App\Domain\SecurityDevices\Models\Device;

interface CameraDeviceDriver extends DeviceDriver
{
    /**
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     data_uri: string|null,
     *     captured_at: string
     * }
     */
    public function captureSnapshot(Device $device): array;

    /**
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     action: string
     * }
     */
    public function performPtz(Device $device, string $action): array;

    /**
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     profiles: list<array{token: string, name: string, encoding: string|null, stream_uri: string|null, snapshot_uri: string|null}>,
     *     device_info: array{manufacturer: string|null, model: string|null, firmware_version: string|null, serial_number: string|null}
     * }
     */
    public function probeStreams(Device $device): array;
}
