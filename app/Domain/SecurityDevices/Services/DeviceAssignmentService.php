<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ClientConsent;
use App\Services\ConsentValidationService;
use App\Services\Sites\SiteTypePlanPinStatusService;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class DeviceAssignmentService
{
    public function __construct(
        private readonly ?PersonalTrackingPrivacyService $trackingPrivacy = null,
        private readonly ?DeviceCustodySiteResolver $custodySites = null,
    ) {}

    /**
     * Assign a device to an entity (site, room, vehicle, staff, client).
     *
     * @throws \InvalidArgumentException
     */
    public function assign(
        Device $device,
        string $assignableType,
        int $assignableId,
        ?int $assignedByUserId,
        AssignmentType $assignmentType = AssignmentType::Permanent,
        ?\DateTimeInterface $expectedReturnAt = null,
        ?int $consentId = null,
        ?string $notes = null,
        ?\DateTimeInterface $assignedAt = null,
        bool $replaceExisting = true,
        ?Closure $authorizeLockedDevice = null,
        ?Closure $validateLockedConsent = null,
    ): DeviceAssignment {
        $this->validateTarget($assignableType);

        return DB::transaction(function () use (
            $device, $assignableType, $assignableId, $assignedByUserId,
            $assignmentType, $expectedReturnAt, $consentId, $notes, $assignedAt,
            $replaceExisting, $authorizeLockedDevice, $validateLockedConsent,
        ) {
            // Consent withdrawal locks ClientConsent before the Device. Keep
            // assignment on the same order so an assign/withdraw race cannot
            // deadlock or authorise from a stale consent snapshot.
            $lockedConsent = $assignableType === DeviceAssignment::TARGET_CLIENT && $consentId
                ? ClientConsent::query()->with('consentType')->lockForUpdate()->find($consentId)
                : null;
            $lockedDevice = $this->lockDevice($device);
            if ($lockedDevice->status === DeviceStatus::Quarantined) {
                throw new \InvalidArgumentException('A quarantined device cannot be assigned.');
            }

            try {
                $custodySiteId = ($this->custodySites ?? app(DeviceCustodySiteResolver::class))
                    ->resolve($assignableType, $assignableId, true);
            } catch (UnexpectedValueException) {
                throw new \InvalidArgumentException('The assignment target has no authoritative current Site.');
            }

            $this->validateConsent($lockedDevice, $assignableType, $assignableId, $lockedConsent);
            if ($validateLockedConsent) {
                $validateLockedConsent($lockedConsent);
            }
            if ($authorizeLockedDevice) {
                $authorizeLockedDevice($lockedDevice);
            }
            if (! $replaceExisting && $this->unreleasedAssignments($lockedDevice)->isNotEmpty()) {
                throw new \InvalidArgumentException('This device is already assigned.');
            }
            $this->releaseActiveAssignments($lockedDevice, $assignedByUserId, 'assignment_replaced');

            return DeviceAssignment::create([
                'device_id' => $lockedDevice->id,
                'assignable_type' => $assignableType,
                'assignable_id' => $assignableId,
                'custody_site_id' => $custodySiteId,
                'assignment_type' => $assignmentType,
                'assigned_at' => $assignedAt ?? now(),
                'expected_return_at' => $expectedReturnAt,
                'assigned_by_user_id' => $assignedByUserId,
                'consent_id' => $consentId,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Release the active assignment for a device (return to pool).
     */
    public function release(
        Device $device,
        int $releasedByUserId,
        ?Closure $authorizeLockedDevice = null,
    ): ?DeviceAssignment {
        return DB::transaction(function () use ($device, $releasedByUserId, $authorizeLockedDevice): ?DeviceAssignment {
            $lockedDevice = $this->lockDevice($device);
            if ($authorizeLockedDevice) {
                $authorizeLockedDevice($lockedDevice);
            }

            return $this->releaseActiveAssignments(
                $lockedDevice,
                $releasedByUserId,
                'assignment_released',
            );
        });
    }

    /**
     * Release every active assignment as part of canonical Device retirement.
     *
     * The reason is retained on every historical row, including non-personal
     * assignments that do not use the tracking collection-stop fields.
     */
    public function releaseAllForDecommission(Device $device, int $releasedByUserId): int
    {
        return DB::transaction(function () use ($device, $releasedByUserId): int {
            $lockedDevice = $this->lockDevice($device);
            $activeCount = DeviceAssignment::query()
                ->where('device_id', $lockedDevice->id)
                ->active()
                ->lockForUpdate()
                ->get(['id'])
                ->count();

            $this->releaseActiveAssignments(
                $lockedDevice,
                $releasedByUserId,
                'device_decommissioned',
                retainReason: true,
            );

            return $activeCount;
        });
    }

    /**
     * Release only when every active row still belongs to the expected target.
     * Stale projections must never release a Device that has since moved to a
     * different Client, staff member, Site, room or vehicle.
     */
    public function releaseForTarget(
        Device $device,
        string $assignableType,
        int $assignableId,
        int $releasedByUserId,
        string $reason = 'assignment_released',
    ): ?DeviceAssignment {
        $this->validateTarget($assignableType);

        return DB::transaction(function () use (
            $device,
            $assignableType,
            $assignableId,
            $releasedByUserId,
            $reason,
        ): ?DeviceAssignment {
            $lockedDevice = $this->lockDevice($device);

            return $this->releaseActiveAssignments(
                $lockedDevice,
                $releasedByUserId,
                $reason,
                $assignableType,
                $assignableId,
            );
        });
    }

    /**
     * Transfer a device from its current assignment to a new one in a single transaction.
     */
    public function transfer(
        Device $device,
        string $assignableType,
        int $assignableId,
        int $userId,
        AssignmentType $assignmentType = AssignmentType::Permanent,
        ?\DateTimeInterface $expectedReturnAt = null,
        ?int $consentId = null,
        ?string $notes = null,
        ?\DateTimeInterface $assignedAt = null,
        ?Closure $authorizeLockedDevice = null,
        ?Closure $validateLockedConsent = null,
    ): DeviceAssignment {
        return $this->assign(
            $device, $assignableType, $assignableId, $userId,
            $assignmentType, $expectedReturnAt, $consentId, $notes, $assignedAt,
            true, $authorizeLockedDevice, $validateLockedConsent,
        );
    }

    /**
     * Release every active row for this device. The database constraint prevents
     * new duplicates; handling every row here also repairs any pre-constraint
     * data that reaches a supervised runtime during a rolling deployment.
     */
    private function releaseActiveAssignments(
        Device $device,
        ?int $userId,
        string $reason,
        ?string $expectedAssignableType = null,
        ?int $expectedAssignableId = null,
        bool $retainReason = false,
    ): ?DeviceAssignment {
        $activeAssignments = DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->active()
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        if ($activeAssignments->isEmpty()) {
            return null;
        }

        if ($expectedAssignableType !== null
            && $activeAssignments->contains(fn (DeviceAssignment $assignment): bool => $assignment->assignable_type !== $expectedAssignableType
                || (int) $assignment->assignable_id !== $expectedAssignableId)) {
            return null;
        }

        $releasedAt = now();
        foreach ($activeAssignments as $assignment) {
            $attributes = [
                'released_at' => $releasedAt,
                'released_by_user_id' => $userId,
            ];
            if ($retainReason) {
                $attributes['notes'] = $this->notesWithLifecycleReason($assignment->notes, $reason);
            }
            $assignment->update($attributes);
            $this->stopPersonalTrackingCollection(
                $assignment,
                $userId,
                $reason,
            );
        }

        app(SiteTypePlanPinStatusService::class)->markDevicePinsStale($device, $reason, $releasedAt);

        return $activeAssignments->first()->fresh();
    }

    /** @return Collection<int, DeviceAssignment> */
    private function unreleasedAssignments(Device $device)
    {
        return DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->whereNull('released_at')
            ->lockForUpdate()
            ->get();
    }

    private function notesWithLifecycleReason(?string $notes, string $reason): string
    {
        $stamp = "Lifecycle reason: {$reason}.";
        $notes = trim((string) $notes);

        return str_contains($notes, $stamp)
            ? $notes
            : trim($notes.PHP_EOL.$stamp);
    }

    private function lockDevice(Device $device): Device
    {
        return Device::query()
            ->whereKey($device->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function validateTarget(string $assignableType): void
    {
        if (! in_array($assignableType, DeviceAssignment::VALID_TARGETS, true)) {
            throw new \InvalidArgumentException(
                "Invalid assignable type '{$assignableType}'. Must be one of: ".
                implode(', ', DeviceAssignment::VALID_TARGETS)
            );
        }
    }

    /**
     * Client-assigned tracking devices require a consent record (NZ privacy).
     */
    private function validateConsent(
        Device $device,
        string $assignableType,
        int $assignableId,
        ?ClientConsent $consent,
    ): void {
        if ($assignableType !== DeviceAssignment::TARGET_CLIENT || $device->domain !== 'tracking') {
            return;
        }

        if (! $consent
            || (int) $consent->client_id !== $assignableId
            || ! ConsentValidationService::isValidTrackingConsent($consent, $assignableId)) {
            throw new \InvalidArgumentException(
                'Client tracker assignments require an active, assignment-linked location-tracking consent.'
            );
        }
    }

    private function stopPersonalTrackingCollection(
        DeviceAssignment $assignment,
        ?int $actorUserId,
        string $reason,
    ): void {
        if (! in_array($assignment->assignable_type, [
            DeviceAssignment::TARGET_CLIENT,
            DeviceAssignment::TARGET_STAFF,
        ], true) || $assignment->device_id === null) {
            return;
        }

        ($this->trackingPrivacy ?? app(PersonalTrackingPrivacyService::class))
            ->stopAssignment($assignment, $actorUserId, $reason);
    }
}
