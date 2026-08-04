<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Carbon\CarbonImmutable;
use JsonException;
use UnexpectedValueException;

final class CommandAssignmentFingerprint
{
    public function forDevice(Device|int $device, ?CarbonImmutable $now = null): string
    {
        $deviceId = $device instanceof Device ? (int) $device->id : $device;
        if ($deviceId < 1) {
            throw new UnexpectedValueException('Canonical Device assignment reference is invalid.');
        }
        $now ??= CarbonImmutable::now('UTC');
        $assignments = DeviceAssignment::query()
            ->where('device_id', $deviceId)
            ->active()
            ->where('assigned_at', '<=', $now)
            ->orderBy('id')
            ->get(['id', 'assignable_type', 'assignable_id', 'assignment_type', 'assigned_at'])
            ->map(fn (DeviceAssignment $assignment): array => [
                'id' => (int) $assignment->id,
                'assignable_type' => $assignment->assignable_type,
                'assignable_id' => (int) $assignment->assignable_id,
                'assignment_type' => $assignment->assignment_type?->value,
                'assigned_at' => $assignment->assigned_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ])
            ->values()
            ->all();
        if ($assignments === []) {
            throw new UnexpectedValueException('Canonical Device assignment is unavailable.');
        }

        try {
            return hash('sha256', json_encode(
                $assignments,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Canonical Device assignment evidence is invalid.', 0, $exception);
        }
    }
}
