<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Devices\Pages\CameraPagePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The camera's page (S&D rebuild Phase E). Reached from DeviceController@show; the URL stays
 * /security-devices/devices/{device}. Another Site's camera is 404.
 */
final class CameraPageController extends Controller
{
    public function __construct(
        private readonly CameraPagePresenter $presenter,
        private readonly DeviceFormOptions $formOptions,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function show(Request $request, Device $device): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $page = null;
        $read = function () use (&$page, $user, $device, $request): array {
            return $page ??= $this->presenter->present($user, $device, ['tab' => $request->query('tab')]);
        };
        $props = [];
        foreach (['device', 'tab', 'tabs', 'meters', 'can', 'actions', 'privacy', 'live', 'overview', 'recordings', 'checks', 'settings'] as $key) {
            $props[$key] = fn () => $read()[$key];
        }

        return Inertia::render('security-devices/devices/camera', [
            ...$props,
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }
}
