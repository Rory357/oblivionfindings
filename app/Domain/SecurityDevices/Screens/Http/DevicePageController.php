<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DevicePagePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The device page for network gear, computers, printers, UPSs, recorders, sensor gateways and
 * other devices (S&D rebuild C2). Reached from DeviceController@show, which keeps today's
 * profile for cameras and the safety kit. The URL stays /security-devices/devices/{device}.
 */
final class DevicePageController extends Controller
{
    public function __construct(
        private readonly DevicePagePresenter $presenter,
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
            return $page ??= $this->presenter->present($user, $device, [
                'tab' => $request->query('tab'),
                'range' => $request->query('range'),
                // S&D v13 (pg-sdr-3e): the History tab's "What" and "From".
                'what' => $request->query('what'),
                'from' => $request->query('from'),
            ]);
        };
        $props = [];
        foreach (['device', 'tab', 'tabs', 'range', 'meters', 'can', 'actions', 'trouble', 'overview', 'health', 'remote', 'checks', 'notes', 'obli', 'terminal', 'ports', 'history', 'settings'] as $key) {
            $props[$key] = fn () => $read()[$key];
        }

        return Inertia::render('security-devices/devices/device', [
            ...$props,
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }
}
