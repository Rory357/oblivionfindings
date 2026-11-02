<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Devices\Pages\TrackerPagePresenter;
use App\Domain\SecurityDevices\Services\QueclinkUnitConsoleService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Send a command to the tracker (S&D rebuild Phase E, pop-up sdr-19): ask for its position, change
 * how often it reports, restart it, or ask for its settings. Today's command path, unchanged
 * (QueclinkUnitConsoleService::sendCommand: who may send which command, a reason for the risky
 * ones, the saved unit password, the audit log). On top of that it needs
 * securityDevices.trackers.command, and asking for a position needs the location to be shown.
 */
final class TrackerCommandController extends Controller
{
    public function __construct(
        private readonly QueclinkUnitConsoleService $console,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function store(Request $request, Device $device): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);
        // Another Site's tracker is 404, before anything else is said about it.
        $this->access->assertCanViewDevice($user, $device);
        abort_unless(TrackerPagePresenter::serves($device) && $this->console->supportsDevice($device), 404);
        abort_unless($user->canDo('securityDevices.trackers.command'), 403);

        $data = $request->validate([
            'command' => ['required', 'string', Rule::in(array_keys(TrackerPagePresenter::COMMANDS))],
            'interval_minutes' => ['nullable', 'required_if:command,update_reporting_interval', 'integer', Rule::in(TrackerPagePresenter::INTERVALS)],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'interval_minutes.required_if' => 'Choose how often it should report.',
        ]);
        $command = (string) $data['command'];
        $reason = trim((string) ($data['reason'] ?? ''));
        [, , $risky] = TrackerPagePresenter::COMMANDS[$command];

        if ($risky && mb_strlen($reason) < 4) {
            throw ValidationException::withMessages(['reason' => 'Say why in a few words. It’s kept in the console history.']);
        }
        if ($command === 'request_location') {
            $position = $this->console->presentTrackerPosition($user, $device);
            if (! in_array($position['state'] ?? null, ['available', 'no_fix_yet'], true)) {
                throw ValidationException::withMessages(['command' => 'Ask for position now is off while the location can’t be shown.']);
            }
        }

        $pending = $this->console->sendCommand(
            actor: $user,
            device: $device,
            commandKey: $command,
            parameters: match ($command) {
                'update_reporting_interval' => ['interval_seconds' => (int) $data['interval_minutes'] * 60],
                'read_config' => ['section' => 'ALL'],
                default => [],
            },
            reason: $reason !== '' ? $reason : null,
            // The pop-up names what the command does on its button; sending it is the confirmation.
            confirmed: true,
        );

        return response()->json([
            'id' => (int) $pending->id,
            'status' => (string) $pending->status,
            'message' => 'Sent. The unit picks it up next time it checks in.',
        ]);
    }
}
