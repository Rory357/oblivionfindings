<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeviceRelationshipLifecycleService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, Device $device, Device $other, array $data): DeviceRelationship
    {
        return DB::transaction(function () use ($actor, $device, $other, $data): DeviceRelationship {
            $devices = $this->lockVisibleDevices($actor, [(int) $device->id, (int) $other->id]);
            if ($devices->count() !== 2) {
                abort(404);
            }

            $parentId = $data['direction'] === 'downstream' ? (int) $device->id : (int) $other->id;
            $childId = $data['direction'] === 'downstream' ? (int) $other->id : (int) $device->id;
            if ($parentId === $childId) {
                throw ValidationException::withMessages([
                    'other_device_id' => 'A Device cannot be linked to itself.',
                ]);
            }

            $exists = DeviceRelationship::query()
                ->active()
                ->where('parent_device_id', $parentId)
                ->where('child_device_id', $childId)
                ->where('relationship_type', $data['relationship_type'])
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'other_device_id' => 'That active relationship already exists.',
                ]);
            }

            $relationship = DeviceRelationship::query()->create([
                'parent_device_id' => $parentId,
                'child_device_id' => $childId,
                'relationship_type' => $data['relationship_type'],
                'port' => $data['port'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);
            AuditLogger::logOrFail('security_devices.relationship.created', $relationship, [
                'actor_id' => (int) $actor->id,
                'parent_device_id' => $parentId,
                'child_device_id' => $childId,
                'relationship_type' => $relationship->relationship_type?->value,
            ]);

            return $relationship;
        }, 3);
    }

    public function unlink(
        User $actor,
        Device $device,
        DeviceRelationship $relationship,
        string $reason,
    ): DeviceRelationship {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Record why this Device relationship is being removed.',
            ]);
        }

        return DB::transaction(function () use ($actor, $device, $relationship, $reason): DeviceRelationship {
            $relationshipIdentity = DeviceRelationship::query()
                ->whereKey($relationship->id)
                ->firstOrFail(['id', 'parent_device_id', 'child_device_id']);
            $this->lockVisibleDevices($actor, [
                (int) $relationshipIdentity->parent_device_id,
                (int) $relationshipIdentity->child_device_id,
            ]);
            $relationship = DeviceRelationship::query()
                ->whereKey($relationshipIdentity->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array((int) $device->id, [
                (int) $relationship->parent_device_id,
                (int) $relationship->child_device_id,
            ], true)) {
                abort(404, 'This relationship does not involve this Device.');
            }
            if ($relationship->unlinked_at !== null) {
                throw ValidationException::withMessages([
                    'reason' => 'This Device relationship has already been removed and retained as history.',
                ]);
            }

            $relationship->update([
                'unlinked_at' => now(),
                'unlinked_by_user_id' => $actor->id,
                'unlink_reason' => $reason,
            ]);
            AuditLogger::logOrFail('security_devices.relationship.unlinked', $relationship, [
                'actor_id' => (int) $actor->id,
                'parent_device_id' => (int) $relationship->parent_device_id,
                'child_device_id' => (int) $relationship->child_device_id,
                'relationship_type' => $relationship->relationship_type?->value,
                'reason_recorded' => true,
            ]);

            return $relationship->fresh();
        }, 3);
    }

    /** @param list<int> $deviceIds @return Collection<int, Device> */
    private function lockVisibleDevices(User $actor, array $deviceIds): Collection
    {
        $ids = collect($deviceIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        if ($ids->count() !== 2) {
            throw ValidationException::withMessages([
                'other_device_id' => 'Choose a different Device.',
            ]);
        }

        $devices = $this->access->visibleDevices($actor)
            ->whereIn('devices.id', $ids)
            ->orderBy('devices.id')
            ->lockForUpdate()
            ->get();
        if ($devices->count() !== $ids->count()) {
            abort(404);
        }

        return $devices;
    }
}
