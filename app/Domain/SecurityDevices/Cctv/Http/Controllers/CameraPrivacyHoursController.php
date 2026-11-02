<?php

namespace App\Domain\SecurityDevices\Cctv\Http\Controllers;

use App\Domain\SecurityDevices\Cctv\Services\CameraPrivacy;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Save a camera's privacy hours (S&D rebuild Phase E, pop-up 64 and the Settings tab switch).
 * Needs securityDevices.devices.update; another Site's camera is 404; every change is audited.
 */
final class CameraPrivacyHoursController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CameraPrivacy $privacy,
    ) {}

    public function update(Request $request, Device $device): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view') && $user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);
        abort_unless(DeviceKind::of($device) === DeviceKind::Camera && $device->status !== DeviceStatus::Decommissioned, 404);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'ranges' => ['present', 'array', 'max:'.CameraPrivacy::MAX_RANGES],
            'ranges.*.days' => ['required', 'array', 'min:1', 'max:7'],
            'ranges.*.days.*' => ['integer', 'between:1,7', 'distinct'],
            'ranges.*.from' => ['required', 'string', 'regex:/\A([01]\d|2[0-3]):[0-5]\d\z/'],
            'ranges.*.to' => ['required', 'string', 'regex:/\A([01]\d|2[0-3]):[0-5]\d\z/'],
        ], [
            'ranges.*.days.required' => 'Choose at least one day.',
            'ranges.*.days.min' => 'Choose at least one day.',
            'ranges.*.from.*' => 'Choose a start time.',
            'ranges.*.to.*' => 'Choose an end time.',
        ]);

        $enabled = (bool) $validated['enabled'];
        $ranges = array_values($validated['ranges']);
        if ($enabled && $ranges === []) {
            throw ValidationException::withMessages(['ranges' => 'Add the days and times first.']);
        }

        try {
            $this->privacy->save($user, $device, $enabled, $ranges);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['ranges' => $exception->getMessage()]);
        }

        return back()->with('success', $enabled ? 'Privacy hours saved.' : 'Privacy hours turned off.');
    }
}
