<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Devices\Pages\SensorPagePresenter;
use App\Domain\SecurityDevices\Screens\Devices\Pages\TrackerPagePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sensor's page and the care tracker's page (S&D rebuild Phase E). Reached from
 * DeviceController@show; the URL stays /security-devices/devices/{device}. Another Site's sensor or
 * tracker is 404. Every prop is a closure, so a partial reload reads only what it asks for.
 */
final class SensorTrackerPageController extends Controller
{
    private const array SENSOR_PROPS = ['device', 'tab', 'tabs', 'range', 'meters', 'can', 'readings', 'alerts'];

    private const array TRACKER_PROPS = ['device', 'tab', 'tabs', 'meters', 'can', 'privacy', 'commands', 'location', 'console'];

    public function __construct(
        private readonly SensorPagePresenter $sensors,
        private readonly TrackerPagePresenter $trackers,
        private readonly DeviceFormOptions $formOptions,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public static function serves(Device $device): bool
    {
        return SensorPagePresenter::serves($device) || TrackerPagePresenter::serves($device);
    }

    public function show(Request $request, Device $device): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($user, $device);
        $sensor = SensorPagePresenter::serves($device);
        abort_unless($sensor || TrackerPagePresenter::serves($device), 404);

        $input = ['tab' => $request->query('tab'), 'range' => $request->query('range')];
        $page = null;
        $read = function () use (&$page, $user, $device, $input, $sensor): array {
            return $page ??= $sensor
                ? $this->sensors->present($user, $device, $input)
                : $this->trackers->present($user, $device, $input);
        };
        $props = [];
        foreach ($sensor ? self::SENSOR_PROPS : self::TRACKER_PROPS as $key) {
            $props[$key] = fn () => $read()[$key];
        }

        return Inertia::render($sensor ? 'security-devices/devices/sensor' : 'security-devices/devices/tracker', [
            ...$props,
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }
}
