<?php

namespace App\Listeners\Care;

use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Illuminate\Support\Facades\Log;

/**
 * Care-side listener: log a structured signal when a device reports a fall.
 *
 * Milesight fall events arrive as DeviceEvent.event_type='fall_detected'.
 * The observer has no direct map for that key, so they route through to
 * `device_signal_generic`. We also future-proof for a dedicated
 * `device_fall` signal type code if that mapping is ever added.
 *
 * Does NOT dispatch notifications; that lives in the Care notification
 * stack (out of scope here). This is the audit / log handoff surface.
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
