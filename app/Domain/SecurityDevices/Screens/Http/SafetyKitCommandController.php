<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Devices\Pages\AlarmPagePresenter;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DoorPagePresenter;
use App\Domain\SecurityDevices\Screens\Devices\Pages\SafetyKitCommands;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * Arm, disarm and "Unlock for N seconds" from the alarm and door pages (S&D rebuild Phase E, pop-ups
 * sdr-17, sdr-18 and sdr-71). Each is a person's request through today's governed command framework,
 * unchanged: authorisation, the hub route, step-up, a second person's approval, MFA for disarm, the
 * typed name the framework asks for, signed dispatch and the read-back. On top of that, each needs
 * its rebuild key (securityDevices.alarms.arm, .alarms.disarm, .doors.unlock).
 *
 * Nothing here is reachable by an automation: every route needs a signed-in person (D12).
 */
final class SafetyKitCommandController extends Controller
{
    public function __construct(
        private readonly DeviceCommandRequestService $commands,
        private readonly DeviceCommandQueueService $queue,
        private readonly SecurityDevicesAccessService $access,
        private readonly AlarmPagePresenter $alarms,
    ) {}

    public function arm(Request $request, Device $device): RedirectResponse
    {
        $user = $this->guard($request, $device, AlarmPagePresenter::serves($device), 'alarm.arm');
        $data = $request->validate([
            'areas' => ['required', 'array', 'min:1', 'max:16'],
            'areas.*' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/', 'distinct'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ]);
        $areas = $this->knownAreas($user, $device, $data['areas']);

        return $this->send($request, $user, $device, 'alarm.arm', $areas, 'Armed from the alarm page in Oblivion', null, $data['idempotency_key']);
    }

    public function disarm(Request $request, Device $device): RedirectResponse
    {
        $user = $this->guard($request, $device, AlarmPagePresenter::serves($device), 'alarm.disarm');
        $data = $request->validate([
            'areas' => ['required', 'array', 'min:1', 'max:16'],
            'areas.*' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/', 'distinct'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation_text' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ], [
            'reason.min' => 'Say a little more about why, so the next person understands (at least 10 characters).',
        ]);
        $areas = $this->knownAreas($user, $device, $data['areas']);

        return $this->send($request, $user, $device, 'alarm.disarm', $areas, trim($data['reason']), $data['confirmation_text'] ?? null, $data['idempotency_key']);
    }

    public function unlock(Request $request, Device $device): RedirectResponse
    {
        $user = $this->guard($request, $device, DoorPagePresenter::serves($device), 'access.door.unlock_timed');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ], [
            'reason.min' => 'Say a little more about why, so the next person understands (at least 10 characters).',
        ]);
        // The seconds are the door's own approved setting, never the browser's.
        $seconds = data_get($device->config ?? [], 'management.unifi_access.unlock_duration_seconds');
        if (! is_int($seconds) || $seconds < 5 || $seconds > 60) {
            throw ValidationException::withMessages(['reason' => 'The door’s unlock time isn’t set up safely yet, so Oblivion won’t unlock it.']);
        }

        return $this->sendOne($request, $user, $device, 'access.door.unlock_timed', ['duration_seconds' => $seconds], trim($data['reason']), null, $data['idempotency_key']);
    }

    /**
     * S&D Phase H (pop-up 70): keep a UniFi Access door unlocked until a time at most 4 hours ahead
     * (NZ time), through the Site's hub. The minutes are worked out here, never taken from the browser.
     */
    public function keepUnlocked(Request $request, Device $device): RedirectResponse
    {
        $user = $this->guard($request, $device, DoorPagePresenter::serves($device), 'access.door.keep_unlocked');
        $data = $request->validate([
            'until' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ], [
            'reason.min' => 'Say a little more about why, so the next person understands (at least 10 characters).',
            'until.date_format' => 'Choose a date and a time.',
        ]);
        $until = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['until'], 'Pacific/Auckland')->utc();
        $minutes = (int) ceil(CarbonImmutable::now('UTC')->diffInSeconds($until, false) / 60);
        if ($minutes < 5) {
            throw ValidationException::withMessages(['until' => 'Choose a time at least 5 minutes from now.']);
        }
        if ($minutes > 240) {
            throw ValidationException::withMessages(['until' => 'Choose a time at most 4 hours ahead.']);
        }

        return $this->sendOne($request, $user, $device, 'access.door.keep_unlocked', ['minutes' => $minutes], trim($data['reason']), null, $data['idempotency_key']);
    }

    /** S&D Phase H: end a keep unlocked early. Only locks the door again. */
    public function keepUnlockedEnd(Request $request, Device $device): RedirectResponse
    {
        $user = $this->guard($request, $device, DoorPagePresenter::serves($device), 'access.door.keep_unlocked_end');
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ]);

        return $this->sendOne($request, $user, $device, 'access.door.keep_unlocked_end', [], 'Locked again early from the door page in Oblivion', null, $data['idempotency_key']);
    }

    /**
     * S&D Phase H (pop-up 86): lock down, evacuate, or end either at a Site, on its UniFi Access
     * console's device. Every governed step stays: reason, the typed name, two-step sign-in, and a
     * second person's approval (or IT's break-glass). Exit buttons and handles always open the doors.
     */
    public function siteEmergency(Request $request, Device $device, string $mode): RedirectResponse
    {
        $capability = ['lockdown' => 'access.site.lockdown', 'evacuate' => 'access.site.evacuate', 'end' => 'access.site.emergency_end'][$mode] ?? null;
        abort_unless($capability !== null, 404);
        $user = $this->guard($request, $device, \App\Domain\SecurityDevices\Access\AccessConsoles::consoleFor($device) !== null, $capability);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation_text' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ], [
            'reason.min' => 'Say a little more about why, so the next person understands (at least 10 characters).',
        ]);

        return $this->sendOne($request, $user, $device, $capability, [], trim($data['reason']), $data['confirmation_text'] ?? null, $data['idempotency_key']);
    }

    /** Send a request a second person has approved; only its requester (or an IT admin) may. */
    public function run(Request $request, Device $device, DeviceCommandRequest $command): RedirectResponse
    {
        abort_unless((int) $command->device_id === (int) $device->id, 404);
        $user = $this->guard($request, $device, true, $command->capability);
        abort_unless(in_array($command->capability, ['alarm.arm', 'alarm.disarm', 'access.door.unlock_timed', 'access.door.keep_unlocked', 'access.door.keep_unlocked_end', 'access.site.lockdown', 'access.site.evacuate', 'access.site.emergency_end'], true), 404);
        abort_unless((int) $command->requested_by_user_id === (int) $user->id || $user->canDo('securityDevices.commands.admin'), 403);
        $this->queue->queue($command, $user);

        return back()->with('success', 'Sent. Checking that it worked…');
    }

    /** Confirm it's you (step-up), then come back to this page. */
    public function confirmIdentity(Request $request, Device $device): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($user, $device);
        // A Site's UniFi Access console comes back to the Access page (pop-up 86), anything else to its own page.
        $request->session()->put('url.intended', \App\Domain\SecurityDevices\Access\AccessConsoles::consoleFor($device) !== null
            ? '/security-devices/access'
            : route('security-devices.devices.show', $device, false));

        return redirect()->route('password.confirm');
    }

    private function guard(Request $request, Device $device, bool $kind, string $capability): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);
        // Another Site's panel or door is 404, before anything else is said about it.
        $this->access->assertCanViewDevice($user, $device);
        abort_unless($kind, 404);
        $permission = SafetyKitCommands::PERMISSION[$capability] ?? null;
        abort_unless($permission !== null && $user->canDo($permission), 403);

        return $user;
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function knownAreas(User $user, Device $device, array $requested): array
    {
        $known = $this->alarms->areaIds($user, $device);
        $unknown = array_diff($requested, $known);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['areas' => 'One of those areas isn’t on this panel any more. Reload the page and try again.']);
        }

        return array_values($requested);
    }

    /** @param list<string> $areas */
    private function send(Request $request, User $user, Device $device, string $capability, array $areas, string $reason, ?string $typedName, string $key): RedirectResponse
    {
        $statuses = [];
        foreach ($areas as $index => $area) {
            $statuses[] = $this->request($request, $user, $device, $capability, ['area' => $area], $reason, $typedName, $key.'-'.$index);
        }

        return $this->answer($statuses);
    }

    /** @param array<string, mixed> $parameters */
    private function sendOne(Request $request, User $user, Device $device, string $capability, array $parameters, string $reason, ?string $typedName, string $key): RedirectResponse
    {
        return $this->answer([$this->request($request, $user, $device, $capability, $parameters, $reason, $typedName, $key)]);
    }

    /** @param array<string, mixed> $parameters */
    private function request(Request $request, User $user, Device $device, string $capability, array $parameters, string $reason, ?string $typedName, string $key): CommandStatus
    {
        $confirmed = $request->session()->get('auth.password_confirmed_at');
        $command = $this->commands->request($device, $user, new CommandRequestInput(
            capability: $capability,
            parameters: $parameters,
            reason: $reason,
            idempotencyKey: 'sd-safety:'.$key,
            stepUpConfirmedAt: is_numeric($confirmed) ? CarbonImmutable::createFromTimestampUTC((int) $confirmed) : null,
            // The pop-up stated the effect and the person pressed the button named for it.
            impactAcknowledged: true,
            confirmationText: $typedName,
        ));
        // Nothing to wait for (no second person needed): send it now, then read the state back.
        if ($command->status === CommandStatus::Ready && (int) $command->requested_by_user_id === (int) $user->id) {
            $command = $this->queue->queue($command, $user);
        }

        return $command->status;
    }

    /** @param list<CommandStatus> $statuses */
    private function answer(array $statuses): RedirectResponse
    {
        $message = match (true) {
            in_array(CommandStatus::AwaitingStepUp, $statuses, true) => 'Confirm it’s you first, then send it again.',
            in_array(CommandStatus::AwaitingApproval, $statuses, true) => 'Asked for. A second person needs to approve it before it’s sent.',
            in_array(CommandStatus::Blocked, $statuses, true) => 'Stopped by a safety check, so nothing was sent.',
            default => 'Sent. Checking that it worked…',
        };

        return back()->with('success', $message);
    }
}
