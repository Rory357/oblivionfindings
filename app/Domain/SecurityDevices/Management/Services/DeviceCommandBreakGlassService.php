<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Enums\BreakGlassReviewOutcome;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Notifications\SecurityDevices\DeviceCommandBreakGlassDeclaredNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeviceCommandBreakGlassService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceManagementAuthorizationService $authorization,
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly DeviceCommandAuditService $audit,
    ) {}

    /** @return Collection<int, User> */
    public function eligibleReviewers(User $requester, Device $device, array $capabilityKeys = []): Collection
    {
        return User::query()
            ->whereNotNull('approved_at')
            ->whereKeyNot($requester->id)
            ->with(['roles.permissions', 'permissionOverrides'])
            ->orderBy('name')
            ->limit(max(1, (int) config('security_devices.break_glass_reviewer_limit', 100)))
            ->get()
            ->filter(fn (User $candidate): bool => $this->isEligibleReviewer(
                $candidate,
                $requester,
                $device,
                $capabilityKeys,
            ))
            ->values();
    }

    public function reviewerFor(
        User $requester,
        Device $device,
        ?int $reviewerId,
        string $capabilityKey,
    ): User {
        $reviewer = $reviewerId === null
            ? null
            : User::query()
                ->with(['roles.permissions', 'permissionOverrides'])
                ->find($reviewerId);

        if (! $reviewer instanceof User
            || ! $this->isEligibleReviewer($reviewer, $requester, $device, [$capabilityKey])) {
            throw ValidationException::withMessages([
                'break_glass_reviewer_user_id' => 'Choose a different current command administrator who can access this Device and Site.',
            ]);
        }

        return $reviewer;
    }

    public function assertRequesterEligible(User $requester): void
    {
        if (! $requester->canDo('securityDevices.commands.admin')) {
            throw ValidationException::withMessages([
                'break_glass' => 'Break glass is restricted to command administrators.',
            ]);
        }
        if ($requester->two_factor_confirmed_at === null) {
            throw ValidationException::withMessages([
                'break_glass' => 'Configured multi-factor authentication is required before declaring break glass.',
            ]);
        }
    }

    public function emergencyReason(?string $reason): string
    {
        $reason = trim((string) $reason);
        Validator::make(['break_glass_reason' => $reason], [
            'break_glass_reason' => ['required', 'string', 'min:20', 'max:1000'],
        ])->validate();

        return $reason;
    }

    public function notifyReviewer(
        DeviceCommandRequest $command,
        User $requester,
        User $reviewer,
    ): void {
        $command->loadMissing(['device', 'site']);
        $capability = $this->capabilities->definition($command->capability);
        Notification::sendNow($reviewer, new DeviceCommandBreakGlassDeclaredNotification(
            commandId: (int) $command->id,
            deviceId: (int) $command->device_id,
            deviceName: $command->device->name,
            siteName: $command->site->name,
            capabilityLabel: $capability->label,
            requesterName: $requester->name,
            reviewDueAt: $command->break_glass_review_due_at->toISOString(),
        ));

        $command->break_glass_notification_sent_at = CarbonImmutable::now('UTC')->startOfSecond();
        $command->save();
        $this->audit->append($command, $requester, 'break_glass_reviewer_notified', [
            'reviewer_user_id' => (int) $reviewer->id,
            'review_due_at' => $command->break_glass_review_due_at->toISOString(),
            'site_id' => (int) $command->site_id,
        ]);
    }

    public function assertDispatchable(DeviceCommandRequest $command): void
    {
        if (! $command->is_break_glass) {
            return;
        }

        $command->loadMissing(['device', 'requestedBy', 'breakGlassReviewer']);
        $capability = $this->capabilities->definition($command->capability);
        $requester = $command->requestedBy;
        $reviewer = $command->breakGlassReviewer;
        if (! $capability->allowsBreakGlass
            || ! $requester instanceof User
            || ! $reviewer instanceof User
            || ! $requester->canDo('securityDevices.commands.admin')
            || $requester->two_factor_confirmed_at === null
            || $command->break_glass_declared_at === null
            || $command->break_glass_review_due_at === null
            || $command->break_glass_notification_sent_at === null
            || ! is_string($command->break_glass_reason)
            || trim($command->break_glass_reason) === ''
            || ! $this->isEligibleReviewer(
                $reviewer,
                $requester,
                $command->device,
                [$command->capability],
            )) {
            throw ValidationException::withMessages([
                'command' => 'Break-glass governance is no longer complete. The command was not dispatched.',
            ]);
        }
    }

    public function isDispatchable(DeviceCommandRequest $command): bool
    {
        try {
            $this->assertDispatchable($command);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function review(
        DeviceCommandRequest $command,
        User $reviewer,
        BreakGlassReviewOutcome $outcome,
        string $summary,
    ): DeviceCommandRequest {
        $summary = trim($summary);
        Validator::make(['summary' => $summary], [
            'summary' => ['required', 'string', 'min:20', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($command, $reviewer, $outcome, $summary): DeviceCommandRequest {
            $locked = DeviceCommandRequest::query()
                ->with(['device', 'requestedBy', 'breakGlassReviewer'])
                ->lockForUpdate()
                ->findOrFail($command->id);
            abort_unless($locked->is_break_glass, 404);
            abort_unless((int) $locked->break_glass_reviewer_user_id === (int) $reviewer->id, 404);
            abort_unless((int) $locked->requested_by_user_id !== (int) $reviewer->id, 404);
            abort_unless($reviewer->canDo('securityDevices.commands.admin'), 404);
            $this->access->assertCanViewDevice($reviewer, $locked->device);
            $capability = $this->capabilities->definition($locked->capability);
            $authorization = $this->authorization->evaluate(
                $reviewer,
                $locked->device,
                $capability,
                ManagementLevel::Observe,
                true,
            );
            abort_unless($authorization->allowed, 404);
            if ($locked->break_glass_reviewed_at !== null) {
                throw ValidationException::withMessages([
                    'outcome' => 'This break-glass use has already received its permanent post-use review.',
                ]);
            }
            if ($locked->execution_completed_at === null || ! $locked->attempts()->exists()) {
                throw ValidationException::withMessages([
                    'outcome' => 'Post-use review becomes available only after an execution attempt completes.',
                ]);
            }

            $locked->break_glass_reviewed_by_user_id = $reviewer->id;
            $locked->break_glass_review_outcome = $outcome;
            $locked->break_glass_review_summary = $summary;
            $locked->break_glass_reviewed_at = CarbonImmutable::now('UTC')->startOfSecond();
            $locked->save();
            $this->audit->append($locked, $reviewer, 'break_glass_post_use_reviewed', [
                'outcome' => $outcome->value,
                'reviewer_user_id' => (int) $reviewer->id,
                'review_due_at' => $locked->break_glass_review_due_at?->toISOString(),
                'reviewed_at' => $locked->break_glass_reviewed_at->toISOString(),
                'site_id' => (int) $locked->site_id,
            ]);

            return $locked;
        });
    }

    /** @param list<string> $capabilityKeys */
    private function isEligibleReviewer(
        User $reviewer,
        User $requester,
        Device $device,
        array $capabilityKeys = [],
    ): bool {
        $baseEligible = (int) $reviewer->id !== (int) $requester->id
            && $reviewer->approved_at !== null
            && $reviewer->two_factor_confirmed_at !== null
            && $reviewer->canDo('securityDevices.devices.view')
            && $reviewer->canDo('securityDevices.commands.approve')
            && $reviewer->canDo('securityDevices.commands.admin')
            && $this->access->visibleDevices($reviewer)->whereKey($device->id)->exists();

        if (! $baseEligible) {
            return false;
        }

        foreach ($capabilityKeys as $capabilityKey) {
            try {
                $capability = $this->capabilities->definition($capabilityKey);
            } catch (\DomainException) {
                return false;
            }
            if (! $this->authorization->evaluate(
                $reviewer,
                $device,
                $capability,
                ManagementLevel::Observe,
                true,
            )->allowed) {
                return false;
            }
        }

        return true;
    }
}
