<?php

namespace App\Domain\SecurityDevices\Management\Http\Controllers;

use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Http\Requests\StoreDeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeviceCommandController extends Controller
{
    public function confirmIdentity(
        Request $request,
        Device $device,
        SecurityDevicesAccessService $access,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor?->canDo('securityDevices.devices.view'), 403);
        $access->assertCanViewDevice($actor, $device);

        $request->session()->put(
            'url.intended',
            route('security-devices.devices.show', $device, false).'?section=management',
        );

        return redirect()->route('password.confirm');
    }

    public function store(
        StoreDeviceCommandRequest $request,
        Device $device,
        DeviceCommandRequestService $commands,
    ): RedirectResponse {
        $validated = $request->validated();
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $command = $commands->request($device, $request->user(), new CommandRequestInput(
            capability: $validated['capability'],
            parameters: $validated['parameters'],
            reason: $validated['reason'],
            idempotencyKey: $validated['idempotency_key'],
            stepUpConfirmedAt: is_numeric($confirmedAt)
                ? CarbonImmutable::createFromTimestampUTC((int) $confirmedAt)
                : null,
            itChangeId: isset($validated['it_change_id']) ? (int) $validated['it_change_id'] : null,
            breakGlass: (bool) ($validated['break_glass'] ?? false),
            breakGlassReason: $validated['break_glass_reason'] ?? null,
            breakGlassReviewerUserId: isset($validated['break_glass_reviewer_user_id'])
                ? (int) $validated['break_glass_reviewer_user_id']
                : null,
            impactAcknowledged: (bool) ($validated['impact_acknowledged'] ?? false),
            confirmationText: $validated['confirmation_text'] ?? null,
        ));

        return back()->with(
            'success',
            $command->wasRecentlyCreated
                ? 'Device command request created. Review the governance status before execution.'
                : 'The existing device command request was returned safely.',
        );
    }
}
