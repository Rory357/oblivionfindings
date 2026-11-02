<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Enums\CommandConfirmationMode;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\CommandObservationFreshnessService;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Arm, disarm and unlock on the alarm and door pages (S&D rebuild Phase E). These are today's
 * governed commands, unchanged: the same capability, authorisation, hub route, step-up, approval,
 * MFA and read-back as everywhere else. This only says, in plain words, whether a button can be
 * offered and what happened to the last ones.
 *
 * A button is offered only when the viewer holds the rebuild key for it (securityDevices.alarms.arm,
 * .alarms.disarm, .doors.unlock) and the device declares the action (Inner Range Inception or
 * Gallagher for alarms; a UniFi Access door). Report-only panels such as Ajax declare nothing, so
 * they are view only. Automations never reach this: nothing here runs without a person (D12).
 */
final class SafetyKitCommands
{
    public const array PERMISSION = [
        'alarm.arm' => 'securityDevices.alarms.arm',
        'alarm.disarm' => 'securityDevices.alarms.disarm',
        'access.door.unlock_timed' => 'securityDevices.doors.unlock',
        // S&D Phase H: a UniFi Access lock rule through the Site's hub (pop-up 70), and ending it early.
        'access.door.keep_unlocked' => 'securityDevices.doors.unlock',
        'access.door.keep_unlocked_end' => 'securityDevices.doors.unlock',
        // S&D Phase H (pop-up 86): UniFi Access's Site-wide emergency modes, on the console's device.
        'access.site.lockdown' => 'securityDevices.access.lockdown',
        'access.site.evacuate' => 'securityDevices.access.lockdown',
        'access.site.emergency_end' => 'securityDevices.access.lockdown',
    ];

    private const array CHECKING = ['queued', 'dispatching', 'accepted', 'running', 'succeeded', 'reconciling'];

    public function __construct(
        private readonly DeclaredDeviceCommandCapabilities $declared,
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly DeviceManagementAuthorizationService $authorization,
        private readonly CommandExecutionRouteResolver $routes,
        private readonly CommandObservationFreshnessService $freshness,
    ) {}

    /** True when the device can be told to do this at all (its maker allows it and a path exists in code). */
    public function declares(Device $device, string $capability): bool
    {
        try {
            $this->capabilities->definition($capability);
        } catch (DomainException) {
            return false;
        }

        return $this->declared->supports($device, $capability);
    }

    /**
     * One action for the page, or null when it isn't offered to this viewer (no rebuild key, or the
     * device doesn't declare it). `available` false comes with the reason in plain words; the page
     * then shows the reason instead of the button.
     *
     * @return array{available: bool, reason: ?string, step_up: bool, approval: bool, type_name: bool, via: ?string}|null
     */
    public function action(User $viewer, Device $device, ?int $siteId, string $capability, ?CarbonImmutable $stepUpAt): ?array
    {
        $permission = self::PERMISSION[$capability] ?? null;
        if ($permission === null || ! $viewer->canDo($permission) || ! $this->declares($device, $capability)) {
            return null;
        }
        $definition = $this->capabilities->definition($capability);
        $this->authorization->resetMemoizedState();
        $decision = $this->authorization->evaluate($viewer, $device, $definition);
        if (! $decision->allowed) {
            // The rebuild key alone isn't enough: today's command permissions still apply.
            return null;
        }

        $route = $siteId === null ? null : $this->routes->resolve($device, $siteId, $capability);
        $stateAllowed = in_array($device->status?->value, $definition->allowedCurrentStates, true);
        $fresh = ! $definition->requiresFreshObservation || $this->freshness->inspect($device)->isFresh();
        $mfa = ! $definition->requiresMfa || $viewer->two_factor_confirmed_at !== null;
        $noun = str_starts_with($capability, 'alarm.') ? 'panel' : 'door';

        $reason = match (true) {
            $siteId === null => 'It isn’t at a Site yet, so Oblivion doesn’t know which way to reach it.',
            ! $route->available => $this->plainRouteReason($route->reason, $noun),
            ! $stateAllowed => 'It can’t be sent while the '.$noun.' is marked as '.mb_strtolower((string) $device->status?->label()).'.',
            ! $fresh => 'Oblivion hasn’t heard from the '.$noun.' recently, so it won’t send a command until it has.',
            ! $mfa => 'This needs two-step sign-in on your account. Turn it on in Settings › Security first.',
            default => null,
        };

        $maxAge = max(60, (int) config('security_devices.step_up_max_age_seconds', 900));

        return [
            'available' => $reason === null,
            'reason' => $reason,
            'step_up' => $definition->requiresStepUp && ($stepUpAt === null || $stepUpAt->lessThan(CarbonImmutable::now()->subSeconds($maxAge))),
            'approval' => $definition->requiresApproval,
            'type_name' => $definition->confirmationMode === CommandConfirmationMode::TypeDeviceName,
            'via' => $route?->mode === 'collector' && $route->collector !== null ? CoveringHubResolver::label($route->collector) : null,
        ];
    }

    /**
     * What happened to the recent requests for these actions (the last 2 hours), newest first, so
     * the page can say "Checking…" and then the confirmed state. Staff are named; the reason is kept.
     *
     * @param  list<string>  $capabilities
     * @return list<array<string, mixed>>
     */
    public function recent(User $viewer, Device $device, array $capabilities, CarbonImmutable $now): array
    {
        return DeviceCommandRequest::query()
            ->with('requestedBy:id,name', 'approvedBy:id,name')
            ->where('device_id', $device->id)
            ->whereIn('capability', $capabilities)
            ->where('created_at', '>=', $now->subHours(2))
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (DeviceCommandRequest $request): array => $this->summary($viewer, $request))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function summary(User $viewer, DeviceCommandRequest $request): array
    {
        $status = $request->status;
        $mine = (int) $request->requested_by_user_id === (int) $viewer->id;
        $phase = match (true) {
            in_array($status, [CommandStatus::Requested, CommandStatus::AwaitingStepUp], true) => 'step_up',
            in_array($status, [CommandStatus::AwaitingApproval, CommandStatus::AwaitingChange], true) => 'approval',
            $status === CommandStatus::Ready => 'ready',
            in_array($status->value, self::CHECKING, true) => 'checking',
            $status === CommandStatus::Reconciled => 'done',
            $status === CommandStatus::Mismatch => 'mismatch',
            $status === CommandStatus::Uncertain => 'unconfirmed',
            default => 'stopped',
        };
        $summary = is_array($request->safe_parameter_summary) ? $request->safe_parameter_summary : [];

        return [
            'id' => (int) $request->id,
            'action' => match ($request->capability) {
                'alarm.arm' => 'arm',
                'alarm.disarm' => 'disarm',
                default => 'unlock',
            },
            'area' => is_string($summary['area'] ?? null) ? $summary['area'] : null,
            'phase' => $phase,
            'words' => $this->words($request->capability, $status),
            'failure' => $phase === 'stopped' && is_string($request->safe_failure_reason) && $request->safe_failure_reason !== ''
                ? $request->safe_failure_reason
                : null,
            'at' => $request->created_at?->toIso8601String(),
            'done_at' => ($request->reconciled_at ?? $request->execution_completed_at)?->toIso8601String(),
            'by' => $request->requestedBy?->name,
            'approved_by' => $request->approvedBy?->name,
            'reason' => (string) $request->reason,
            'mine' => $mine,
            'can_run' => $status === CommandStatus::Ready && ($mine || $viewer->canDo('securityDevices.commands.admin')),
        ];
    }

    private function words(string $capability, CommandStatus $status): string
    {
        $done = match ($capability) {
            'alarm.arm' => 'Armed · the panel confirmed it',
            'alarm.disarm' => 'Disarmed · the panel confirmed it',
            'access.door.keep_unlocked' => 'Kept unlocked · UniFi Access confirmed it',
            'access.door.keep_unlocked_end' => 'Locked again · UniFi Access confirmed it',
            'access.site.lockdown' => 'Locked down · UniFi Access confirmed it',
            'access.site.evacuate' => 'Evacuating · UniFi Access confirmed it',
            'access.site.emergency_end' => 'Back to normal · UniFi Access confirmed it',
            default => 'Unlocked, then locked again · the door confirmed it',
        };

        return match ($status) {
            CommandStatus::Requested, CommandStatus::AwaitingStepUp => 'Waiting for you to confirm it’s you',
            CommandStatus::AwaitingApproval => 'Waiting for a second person to approve it',
            CommandStatus::AwaitingChange => 'Waiting for an approved change',
            CommandStatus::Ready => 'Approved · ready to send',
            CommandStatus::Queued, CommandStatus::Dispatching, CommandStatus::Accepted, CommandStatus::Running,
            CommandStatus::Succeeded, CommandStatus::Reconciling => 'Checking…',
            CommandStatus::Reconciled => $done,
            CommandStatus::Mismatch => match (true) {
                str_starts_with($capability, 'alarm.') => 'The panel didn’t change · it still reports its old state',
                str_starts_with($capability, 'access.site.') || $capability === 'access.door.keep_unlocked' || $capability === 'access.door.keep_unlocked_end'
                    => 'UniFi Access doesn’t show the change · check it now',
                default => 'The door didn’t lock again · check it now',
            },
            CommandStatus::Uncertain => 'Result not confirmed',
            CommandStatus::Failed => 'Didn’t work',
            CommandStatus::Rejected => 'Turned down',
            CommandStatus::Expired => 'Ran out of time before it was sent',
            CommandStatus::Cancelled => 'Cancelled',
            CommandStatus::Blocked => 'Stopped by a safety check',
        };
    }

    /**
     * The resolver's hub reasons are already plain words and are shown as they are (R6's "… needs its
     * software update before it can arm or disarm the alarm", "… isn’t connected right now"); only the
     * older developer-worded ones are replaced.
     */
    private function plainRouteReason(string $reason, string $noun): string
    {
        $developer = preg_match('/collector|adapter|central|remote-only|Site-scoped|enrolled/i', $reason) === 1;
        if (! $developer && trim($reason) !== '') {
            return $reason;
        }

        return 'Oblivion can’t reach the '.$noun.' to send this yet. It needs an approved address, a saved login and a supported model.';
    }
}
