<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeviceMaintenanceLifecycleService
{
    private const TRANSITIONS = [
        'scheduled' => ['scheduled', 'in_progress', 'completed', 'cancelled'],
        'in_progress' => ['in_progress', 'completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, Device $device, array $data): DeviceMaintenanceRecord
    {
        return DB::transaction(function () use ($actor, $device, $data): DeviceMaintenanceRecord {
            $device = $this->lockVisibleDevice($actor, (int) $device->getKey());
            $data['device_id'] = $device->id;
            $data['status'] = $data['status'] ?? 'scheduled';
            $data = $this->completionEvidence($data, $actor, true);
            $record = DeviceMaintenanceRecord::query()->create($data);
            AuditLogger::logOrFail('security_devices.maintenance.created', $record, [
                'actor_id' => (int) $actor->id,
                'device_id' => (int) $device->id,
                'status' => $record->status,
            ]);

            return $record;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, DeviceMaintenanceRecord $record, array $data): DeviceMaintenanceRecord
    {
        return DB::transaction(function () use ($actor, $record, $data): DeviceMaintenanceRecord {
            $record = DeviceMaintenanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $device = $this->lockVisibleDevice($actor, (int) $record->device_id);
            $requestedStatus = (string) ($data['status'] ?? $record->status);
            $this->assertTransition($record, $requestedStatus);
            $data['status'] = $requestedStatus;
            $data = $this->completionEvidence($data, $actor, false);
            $before = $record->only(['status', 'completed_at', 'performed_by_user_id']);
            $record->update($data);
            AuditLogger::logOrFail('security_devices.maintenance.updated', $record, [
                'actor_id' => (int) $actor->id,
                'device_id' => (int) $device->id,
                'before' => $before,
                'after' => $record->only(['status', 'completed_at', 'performed_by_user_id']),
            ]);

            return $record->fresh();
        }, 3);
    }

    public function complete(User $actor, DeviceMaintenanceRecord $record): DeviceMaintenanceRecord
    {
        return DB::transaction(function () use ($actor, $record): DeviceMaintenanceRecord {
            $record = DeviceMaintenanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $device = $this->lockVisibleDevice($actor, (int) $record->device_id);
            $this->assertTransition($record, 'completed');
            $record->update([
                'status' => 'completed',
                'completed_at' => now(),
                'performed_by_user_id' => $actor->id,
            ]);
            AuditLogger::logOrFail('security_devices.maintenance.completed', $record, [
                'actor_id' => (int) $actor->id,
                'device_id' => (int) $device->id,
                'completed_at' => $record->completed_at?->toIso8601String(),
            ]);

            return $record->fresh();
        }, 3);
    }

    private function lockVisibleDevice(User $actor, int $deviceId): Device
    {
        return $this->access->visibleDevices($actor)
            ->whereKey($deviceId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertTransition(DeviceMaintenanceRecord $record, string $requestedStatus): void
    {
        $allowed = self::TRANSITIONS[$record->status] ?? [];
        if (! in_array($requestedStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A {$record->status} maintenance record is retained history and cannot transition to {$requestedStatus}.",
            ]);
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function completionEvidence(array $data, User $actor, bool $creating): array
    {
        if (($data['status'] ?? 'scheduled') !== 'completed') {
            if (! empty($data['completed_at'])) {
                throw ValidationException::withMessages([
                    'completed_at' => 'Completion time is allowed only when the maintenance record is completed.',
                ]);
            }
            $data['completed_at'] = null;
            $data['performed_by_user_id'] = null;

            return $data;
        }

        $completedAt = CarbonImmutable::parse($data['completed_at'] ?? now());
        if ($completedAt->isFuture()) {
            throw ValidationException::withMessages([
                'completed_at' => 'Completion time cannot be in the future.',
            ]);
        }
        if (! $creating && array_key_exists('performed_by_user_id', $data)) {
            unset($data['performed_by_user_id']);
        }
        $data['completed_at'] = $completedAt;
        $data['performed_by_user_id'] = $actor->id;

        return $data;
    }
}
