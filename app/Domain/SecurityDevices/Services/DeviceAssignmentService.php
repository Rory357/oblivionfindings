<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Services\Sites\SiteTypePlanPinStatusService;
use Illuminate\Support\Facades\DB;

class DeviceAssignmentService
{
    /**
     * Assign a device to an entity (site, room, vehicle, staff, client).
     *
     * @throws \InvalidArgumentException
     */
    public function assign(
        Device $device,
        string $assignableType,
        int $assignableId,
        int $assignedByUserId,
        AssignmentType $assignmentType = AssignmentType::Permanent,
        ?\DateTimeInterface $expectedReturnAt = null,
        ?int $consentId = null,
        ?string $notes = null,
    ): DeviceAssignment {
        $this->validateTarget($assignableType);
        $this->validateConsent($device, $assignableType, $consentId);

        return DB::transaction(function () use (
            $device, $assignableType, $assignableId, $assignedByUserId,
            $assignmentType, $expectedReturnAt, $consentId, $notes,
        ) {
            // Release any existing active assignment first.
            $this->releaseActiveAssignment($device, $assignedByUserId);

            return DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => $assignableType,
                'assignable_id' => $assignableId,
                'assignment_type' => $assignmentType,
                'assigned_at' => now(),
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
    public function release(Device $device, int $releasedByUserId): ?DeviceAssignment
    {
        $active = $device->assignments()->active()->first();

        if (! $active) {
            return null;
        }

        $releasedAt = now();

        $active->update([
            'released_at' => $releasedAt,
            'released_by_user_id' => $releasedByUserId,
        ]);
        app(SiteTypePlanPinStatusService::class)->markDevicePinsStale($device, 'assignment_released', $releasedAt);

        return $active->fresh();
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
    ): DeviceAssignment {
        return $this->assign(
            $device, $assignableType, $assignableId, $userId,
            $assignmentType, $expectedReturnAt, $consentId, $notes,
        );
    }

    /**
     * Release the active assignment if one exists.
     */
    private function releaseActiveAssignment(Device $device, int $userId): void
    {
        $active = $device->assignments()->active()->first();

        if ($active) {
            $releasedAt = now();

            $active->update([
                'released_at' => $releasedAt,
                'released_by_user_id' => $userId,
            ]);
            app(SiteTypePlanPinStatusService::class)->markDevicePinsStale($device, 'assignment_replaced', $releasedAt);
        }
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
    private function validateConsent(Device $device, string $assignableType, ?int $consentId): void
    {
        if (
            $assignableType === DeviceAssignment::TARGET_CLIENT
            && $device->domain === 'tracking'
            && $consentId === null
        ) {
            throw new \InvalidArgumentException(
                'Client tracker assignments require a consent_id (NZ privacy requirement).'
            );
        }
    }
}
