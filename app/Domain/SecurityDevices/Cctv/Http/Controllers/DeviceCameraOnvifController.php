<?php

namespace App\Domain\SecurityDevices\Cctv\Http\Controllers;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Cctv\Services\CameraEndpointTrustService;
use App\Domain\SecurityDevices\Cctv\Services\OnvifCameraProfileClient;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

final class DeviceCameraOnvifController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly OnvifCameraProfileClient $onvif,
        private readonly DeviceDriverRegistry $drivers,
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

        $driver = $this->drivers->resolveCameraDriver($device);
        $result = $driver !== null
            ? $driver->captureSnapshot($device)
            : $this->onvif->captureSnapshot($device);

        AuditLogger::logOrFail('camera.onvif.snapshot', $device, [
            'actor_id' => (int) $user->id,
            'device_id' => (int) $device->id,
            'driver_key' => $driver?->key() ?? 'camera-onvif-standard',
            'ok' => $result['ok'],
            'reason_code' => $result['reason_code'],
        ]);

        return response()->json($this->withTrustPrompt($request, $device, $result))->header('Cache-Control', 'no-store');
    }

    public function streams(Request $request, Device $device): JsonResponse
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

        $driver = $this->drivers->resolveCameraDriver($device);
        $result = $driver !== null
            ? $driver->probeStreams($device)
            : $this->onvif->probeStreams($device);

        AuditLogger::logOrFail('camera.streams.probe', $device, [
            'actor_id' => (int) $user->id,
            'device_id' => (int) $device->id,
            'driver_key' => $driver?->key() ?? 'camera-onvif-standard',
            'ok' => $result['ok'],
            'reason_code' => $result['reason_code'],
        ]);

        return response()->json($this->withTrustPrompt($request, $device, $result))->header('Cache-Control', 'no-store');
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
        $driver = $this->drivers->resolveCameraDriver($device);
        $result = $driver !== null
            ? $driver->performPtz($device, $action)
            : $this->onvif->performPtz($device, $action);

        AuditLogger::logOrFail('camera.onvif.ptz', $device, [
            'actor_id' => (int) $user->id,
            'device_id' => (int) $device->id,
            'driver_key' => $driver?->key() ?? 'camera-onvif-standard',
            'ptz_action' => $action,
            'ok' => $result['ok'],
            'reason_code' => $result['reason_code'],
        ]);

        return response()->json($this->withTrustPrompt($request, $device, $result))->header('Cache-Control', 'no-store');
    }

    /**
     * "Trust this camera" (Phase A, item A5). Only a certificate the camera's address actually offered,
     * at the camera's own Site, can be trusted; replacing a trusted one needs an IT admin.
     */
    public function trustCertificate(Request $request, Device $device, CameraEndpointTrustService $trust): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $this->access->assertCanViewDevice($user, $device);
        abort_unless($device->domain === 'security' && $device->category === 'cctv', 404);

        $validated = $request->validate([
            'host' => ['required', 'ip'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'fingerprint' => ['required', 'string', 'max:100', 'regex:/\A[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){31}\z/'],
        ]);

        $siteId = $this->siteOf($device);
        abort_unless($siteId !== null, 404);

        $record = $trust->trust($user, $siteId, (string) $validated['host'], (int) $validated['port'], (string) $validated['fingerprint']);

        return response()->json([
            'trusted_fingerprint' => $record->trusted_fingerprint,
            'message' => 'Camera trusted. Try again to use it.',
        ]);
    }

    /**
     * Add what the "Trust this camera?" pop-up needs when a camera was refused for an untrusted or
     * changed certificate.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withTrustPrompt(Request $request, Device $device, array $result): array
    {
        $target = $result['tls_trust'] ?? null;
        if (! is_array($target) || ! isset($target['site_id'], $target['host'], $target['port']) || $request->user() === null) {
            unset($result['tls_trust']);

            return $result;
        }

        $trust = app(CameraEndpointTrustService::class);
        $presented = $trust->present($request->user(), $trust->record((int) $target['site_id'], (string) $target['host'], (int) $target['port']));
        $result['tls_trust'] = $presented === null ? null : [
            ...$presented,
            'host' => (string) $target['host'],
            'port' => (int) $target['port'],
            'trust_url' => route('security-devices.devices.camera.certificate.trust', ['device' => $device->id], false),
        ];

        return $result;
    }

    private function siteOf(Device $device): ?int
    {
        try {
            $siteId = app(CanonicalDeviceSiteResolver::class)->resolve((int) $device->id);
        } catch (UnexpectedValueException) {
            return null;
        }

        return $siteId > 0 ? $siteId : null;
    }
}
