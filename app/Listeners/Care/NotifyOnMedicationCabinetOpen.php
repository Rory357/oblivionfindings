<?php

namespace App\Listeners\Care;

use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Illuminate\Support\Facades\Log;

/**
 * Care-side listener: emit a compliance-relevant audit log whenever a
 * medication-cabinet door opens.
 *
 * The Device model does not currently have a dedicated `compliance_flags`
 * column (discussed in docs/security-devices-restructure-plan.md §1152 as
 * a future addition); we therefore flag medication cabinets via the
 * existing JSON `meta` column: `meta['medication_cabinet'] === true`.
 *
 * If/when `compliance_flags` lands, we check that first and fall back to
 * `meta` for unmigrated rows.
 */
class NotifyOnMedicationCabinetOpen
{
    public function handle(DeviceSignalPublished $event): void
    {
        if ($event->signalTypeCode() !== 'device_door_opened') {
            return;
        }

        $device = $event->device;

        $complianceFlags = $device->getAttribute('compliance_flags');
        $isMedCabinet = is_array($complianceFlags)
            ? (bool) ($complianceFlags['medication_cabinet'] ?? false)
            : (bool) (($device->meta['medication_cabinet'] ?? false));

        if (! $isMedCabinet) {
            return;
        }

        $assignment = DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->whereIn('assignable_type', [DeviceAssignment::TARGET_SITE, DeviceAssignment::TARGET_ROOM])
            ->whereNull('released_at')
            ->latest('assigned_at')
            ->first();

        Log::info('care.medication_cabinet_opened', [
            'device_id' => $device->id,
            'device_event_id' => $event->deviceEvent->id,
            'signal_id' => $event->signal->id,
            'site_id' => $assignment?->assignable_type === DeviceAssignment::TARGET_SITE
                ? $assignment->assignable_id : null,
            'room_id' => $assignment?->assignable_type === DeviceAssignment::TARGET_ROOM
                ? $assignment->assignable_id : null,
            'occurred_at' => optional($event->deviceEvent->occurred_at)->toIso8601String(),
            'alert_created' => $event->alertCreated,
        ]);
    }
}
