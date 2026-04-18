<?php

namespace App\Listeners\Care;

use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Illuminate\Support\Facades\Log;

/**
 * Care-side listener: log a structured signal when a bed-occupancy sensor
 * reports an exit. Bed-exit events arrive as DeviceEvent.event_type='bed_exit'
 * via the Milesight webhook parser (no direct TYPE_MAP entry, so they land
 * in `device_signal_generic`).
 *
 * We gate on the ORIGINAL event type to stay decoupled from whatever
 * signal-type routing the observer ends up using.
 */
class NotifyOnBedExit
{
    public function handle(DeviceSignalPublished $event): void
    {
        if ($event->originalEventType() !== 'bed_exit') {
            return;
        }

        $assignment = DeviceAssignment::query()
            ->where('device_id', $event->device->id)
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->whereNull('released_at')
            ->latest('assigned_at')
            ->first();

        if (! $assignment) {
            Log::warning('care.bed_exit.unlinked', [
                'device_id' => $event->device->id,
                'device_event_id' => $event->deviceEvent->id,
            ]);
            return;
        }

        Log::info('care.bed_exit', [
            'client_id' => $assignment->assignable_id,
            'device_id' => $event->device->id,
            'device_event_id' => $event->deviceEvent->id,
            'signal_id' => $event->signal->id,
            'occurred_at' => optional($event->deviceEvent->occurred_at)->toIso8601String(),
            'alert_created' => $event->alertCreated,
        ]);
    }
}
