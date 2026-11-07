<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Devices\Pages\AlarmPagePresenter;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DoorPagePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The alarm panel's page and the door's page (S&D rebuild Phase E). Reached from
 * DeviceController@show; the URL stays /security-devices/devices/{device}. Another Site's panel or
 * door is 404. Every prop is a closure, so the page's "Checking…" refresh reads only what it asks for.
 */
final class SafetyKitPageController extends Controller
{
    private const array ALARM_PROPS = ['device', 'tab', 'tabs', 'panel', 'meters', 'can', 'control', 'areas', 'overview', 'zones', 'checks', 'notes'];

    private const array DOOR_PROPS = ['device', 'tab', 'tabs', 'lock', 'meters', 'can', 'control', 'overview', 'events', 'access'];

    public function __construct(
        private readonly AlarmPagePresenter $alarms,
        private readonly DoorPagePresenter $doors,
        private readonly DeviceFormOptions $formOptions,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public static function serves(Device $device): bool
    {
        return AlarmPagePresenter::serves($device) || DoorPagePresenter::serves($device);
    }

    public function show(Request $request, Device $device): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($user, $device);
        $alarm = AlarmPagePresenter::serves($device);
        abort_unless($alarm || DoorPagePresenter::serves($device), 404);

        $confirmed = $request->session()->get('auth.password_confirmed_at');
        $input = [
            'tab' => $request->query('tab'),
            'range' => $request->query('range'),
            'page' => $request->query('page'),
            'step_up_at' => is_numeric($confirmed) ? CarbonImmutable::createFromTimestampUTC((int) $confirmed) : null,
        ];
        $page = null;
        $read = function () use (&$page, $user, $device, $input, $alarm): array {
            return $page ??= $alarm
                ? $this->alarms->present($user, $device, $input)
                : $this->doors->present($user, $device, $input);
        };
        $props = [];
        foreach ($alarm ? self::ALARM_PROPS : self::DOOR_PROPS as $key) {
            $props[$key] = fn () => $read()[$key];
        }

        return Inertia::render($alarm ? 'security-devices/devices/alarm' : 'security-devices/devices/door', [
            ...$props,
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }
}
