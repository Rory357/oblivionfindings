<?php

namespace App\Domain\SecurityDevices\Cctv\Http\Controllers;

use App\Domain\SecurityDevices\Cctv\Services\OnvifCameraProfileClient;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class DeviceCameraOnvifController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly OnvifCameraProfileClient $onvif,
    ) {}

    public function snapshot(Request $request, Device $device): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user
            && $user->canDo('securityDevices.devices.view')
            && $user->canDo('securityDevices.cctv.media.view'),
            403,
        );
        $this->access->assertCanViewDevice($user, $device);
        abort_unless(
            $device->domain === 'security'
            && $device->category === 'cctv'
            && $device->status !== DeviceStatus::Decommissioned,
            404,
        );

        $result = $this->onvif->captureSnapshot($device);

        AuditLogger::logOrFail('camera.onvif.snapshot', $device, [
            'actor_id' => (int) $user->id,
            'device_id' => (int) $device->id,
            'ok' => $result['ok'],
            'reason_code' => $result['reason_code'],
        ]);

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function ptz(Request $request, Device $device): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user
            && $user->canDo('securityDevices.devices.view')
            && $user->canDo('securityDevices.cctv.media.view')
            && (
                $user->canDo('securityDevices.commands.operate')
                || $user->canDo('securityDevices.commands.manage')
                || $user->canDo('securityDevices.commands.control')
                || $user->canDo('securityDevices.commands.admin')
            ),
            403,
        );
        $this->access->assertCanViewDevice($user, $device);
        abort_unless(
            $device->domain === 'security'
            && $device->category === 'cctv'
            && $device->status !== DeviceStatus::Decommissioned,
            404,
        );

        $validated = $request->validate([
            'action' => [
                'required',
                'string',
                Rule::in([
                    'pan_left',
                    'pan_right',
                    'tilt_up',
                    'tilt_down',
                    'zoom_in',
                    'zoom_out',
                    'home',
                    'stop',
                ]),
            ],
        ]);

        $action = (string) $validated['action'];
        $result = $this->onvif->performPtz($device, $action);

        AuditLogger::logOrFail('camera.onvif.ptz', $device, [
            'actor_id' => (int) $user->id,
            'device_id' => (int) $device->id,
            'ptz_action' => $action,
            'ok' => $result['ok'],
            'reason_code' => $result['reason_code'],
        ]);

        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
