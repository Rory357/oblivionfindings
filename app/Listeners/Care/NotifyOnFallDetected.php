<?php

namespace App\Listeners\Care;

use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Illuminate\Support\Facades\Log;

/**
 * Care-side listener: log a structured signal when a device reports a fall.
 *
 * Milesight fall events arrive as DeviceEvent.event_type='fall_detected'.
 * Since the safety fix round (7 Nov 2026) they go to Control Room's own
 * `fall_detected` type (critical, DeviceEventObserver::SAFETY_TYPE_MAP),
 * and SafetyEventObserver opens a "possible fall" alert for the staff on
 * shift at the house. This listener only writes the care audit line; it
 * still accepts the older `device_signal_generic` and `device_fall` codes.
 *
 * Does NOT dispatch notifications itself.
 */
class NotifyOnFallDetected
{
    public function handle(DeviceSignalPublished $event): void
    {
        $originalType = $event->originalEventType();
        $signalCode = $event->signalTypeCode();

        $fallLike = $originalType === 'fall_detected'
            || $signalCode === 'device_fall'
            || ($signalCode === 'device_signal_generic' && preg_match('/fall/i', $originalType));

        if (! $fallLike) {
            return;
        }

        $assignment = DeviceAssignment::query()
            ->where('device_id', $event->device->id)
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->whereNull('released_at')
            ->latest('assigned_at')
            ->first();

        if (! $assignment) {
            Log::warning('care.fall_detected.unlinked', [
                'device_id' => $event->device->id,
                'device_event_id' => $event->deviceEvent->id,
                'original_event_type' => $originalType,
                'signal_type_code' => $signalCode,
            ]);
            return;
        }

        Log::info('care.fall_detected', [
            'client_id' => $assignment->assignable_id,
            'device_id' => $event->device->id,
            'device_event_id' => $event->deviceEvent->id,
            'signal_id' => $event->signal->id,
            'occurred_at' => optional($event->deviceEvent->occurred_at)->toIso8601String(),
            'alert_created' => $event->alertCreated,
        ]);
    }
}
